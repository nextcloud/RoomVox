<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Support\Subscription\IRegistry;
use Psr\Log\LoggerInterface;

/**
 * Service for anonymous telemetry data collection and reporting.
 * This is an opt-out feature that helps improve RoomVox.
 */
class TelemetryService {
    private const TELEMETRY_URL = 'https://licenses.voxcloud.nl/api/telemetry/roomvox';

    public function __construct(
        private IClientService $httpClient,
        private IConfig $config,
        private LoggerInterface $logger,
        private IUserManager $userManager,
        private RoomService $roomService,
        private RoomGroupService $roomGroupService,
        private LicenseService $licenseService,
        private ?IRegistry $subscriptionRegistry = null,
    ) {
    }

    /**
     * Check if telemetry is enabled.
     * Default is true (opt-out).
     */
    public function isEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, 'telemetry_enabled', 'true') === 'true';
    }

    /**
     * Enable or disable telemetry.
     */
    public function setEnabled(bool $enabled): void {
        $this->config->setAppValue(Application::APP_ID, 'telemetry_enabled', $enabled ? 'true' : 'false');
        $this->logger->info('TelemetryService: Telemetry ' . ($enabled ? 'enabled' : 'disabled'));
    }

    /**
     * Get the telemetry server URL.
     */
    public function getTelemetryUrl(): string {
        return $this->config->getAppValue(
            Application::APP_ID,
            'telemetry_url',
            self::TELEMETRY_URL
        );
    }

    /**
     * Send telemetry report to the server.
     * @return bool Success status
     */
    public function sendReport(): bool {
        return $this->sendReportWithDetails()['success'];
    }

    /**
     * Send telemetry report with detailed result for UI feedback.
     * @return array{success: bool, reason?: string, message?: string}
     */
    public function sendReportWithDetails(): array {
        if (!$this->isEnabled()) {
            $this->logger->debug('TelemetryService: Telemetry is disabled, skipping report');
            return ['success' => false, 'reason' => 'disabled'];
        }

        try {
            $data = $this->collectData();

            $client = $this->httpClient->newClient();
            $response = $client->post($this->getTelemetryUrl(), [
                'json' => $data,
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'RoomVox/' . $this->getAppVersion(),
                    'Content-Type' => 'application/json'
                ]
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('TelemetryService: Report sent successfully', [
                    'totalRooms' => $data['totalRooms'],
                    'totalRoomGroups' => $data['totalRoomGroups']
                ]);

                $this->config->setAppValue(
                    Application::APP_ID,
                    'telemetry_last_report',
                    (string)time()
                );

                return ['success' => true];
            }

            return ['success' => false, 'reason' => 'server_error', 'message' => 'HTTP ' . $statusCode];
        } catch (\Exception $e) {
            $message = $e->getMessage();

            // Extract server error message from Guzzle response
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $body = (string) $e->getResponse()->getBody();
                $json = json_decode($body, true);
                if (isset($json['error'])) {
                    $message = $json['error'];
                } elseif (!empty($body) && strlen($body) < 200) {
                    $message = $body;
                }
            }

            $this->logger->warning('TelemetryService: Failed to send report: ' . $message);
            return ['success' => false, 'reason' => 'error', 'message' => $message];
        }
    }

    /**
     * Collect telemetry data from RoomVox configuration.
     */
    public function collectData(): array {
        $rooms = $this->roomService->getAllRooms();
        $roomStats = $this->calculateRoomStats($rooms);
        $groups = $this->roomGroupService->getAllGroups();

        return [
            'instanceHash' => $this->getInstanceHash(),
            'version' => $this->getAppVersion(),
            'totalRooms' => count($rooms),
            'totalRoomGroups' => count($groups),
            'totalBookings' => 0, // Skipped — CalDAV queries are too expensive for telemetry
            'roomTypeCounts' => $roomStats['roomTypeCounts'],
            'avgCapacity' => $roomStats['avgCapacity'],
            'facilitiesCounts' => $roomStats['facilitiesCounts'],
            'autoAcceptCount' => $roomStats['autoAcceptCount'],
            'roomsWithSmtp' => $roomStats['roomsWithSmtp'],
            'availabilityRulesEnabled' => $roomStats['availabilityRulesEnabled'],
            'totalUsers' => $this->getUserCount(),
            'activeUsers30d' => $this->getActiveUserCount(30),
            'disabledUsers' => $this->getDisabledUserCount(),
            'nextcloudVersion' => $this->getNextcloudVersion(),
            'phpVersion' => PHP_VERSION,
            'countryCode' => $this->getCountryCode(),
            'databaseType' => $this->config->getSystemValue('dbtype', 'sqlite'),
            'defaultLanguage' => $this->config->getSystemValue('default_language', 'en'),
            'defaultTimezone' => $this->getDefaultTimezone(),
            'osFamily' => PHP_OS_FAMILY,
            'webServer' => $this->getWebServer(),
            'isDocker' => $this->isDocker(),
            // The Enterprise signal. hasExtendedSupport is the narrower add-on
            // and is kept alongside it: servers that predate hasValidSubscription
            // still read that key, and it stays useful on its own.
            'hasValidSubscription' => $this->hasValidSubscription(),
            'hasExtendedSupport' => $this->hasExtendedSupport(),
            // Sent so the license server can verify hasExtendedSupport claims —
            // the boolean alone is unauthenticated and could be spoofed by anyone
            // posting to /api/telemetry/report. The server only honors the claim
            // when this key + the instance hash match an active license_usage row.
            // Empty string for community instances (no license) — server treats
            // those as 'never Enterprise' which is correct.
            'licenseKey' => $this->licenseService->getLicenseKey() ?: '',
        ];
    }

    /**
     * Whether the host Nextcloud has a valid Enterprise subscription.
     *
     * Asks IRegistry directly rather than going through
     * OCP\Util::hasExtendedSupport(). That helper answers a different question:
     * delegateHasExtendedSupport() reports the paid *Extended Support* add-on,
     * which sits on top of a subscription. An ordinary Enterprise customer
     * without that add-on answers false, so every such instance was counted as
     * Community. Nextcloud core itself never uses hasExtendedSupport() for
     * subscription decisions -- ServerDevNotice, PushService and
     * updatenotification all call delegateHasValidSubscription().
     *
     * It also drops a spoofing hole: Util::hasExtendedSupport() falls back to
     * the `extendedSupport` system config value when the registry is missing,
     * so any admin could set it by hand. IRegistry only answers true when a
     * real ISubscription handler is registered.
     *
     * Returns false on any failure, so Community is never reported as
     * Enterprise.
     */
    private function hasValidSubscription(): bool {
        try {
            return $this->subscriptionRegistry?->delegateHasValidSubscription() ?? false;
        } catch (\Throwable $e) {
            $this->logger->debug('TelemetryService: delegateHasValidSubscription() check failed', [
                'error' => $e->getMessage()
            ]);
        }
        return false;
    }

    /**
     * Whether that subscription also carries the Extended Support add-on.
     *
     * Reported separately so the two signals stay distinguishable: this is a
     * strict subset of hasValidSubscription() and is not a substitute for it.
     */
    private function hasExtendedSupport(): bool {
        try {
            return $this->subscriptionRegistry?->delegateHasExtendedSupport() ?? false;
        } catch (\Throwable $e) {
            $this->logger->debug('TelemetryService: delegateHasExtendedSupport() check failed', [
                'error' => $e->getMessage()
            ]);
        }
        return false;
    }

    /**
     * Calculate aggregate statistics from room data.
     */
    private function calculateRoomStats(array $rooms): array {
        $roomTypeCounts = [];
        $facilitiesCounts = [];
        $autoAcceptCount = 0;
        $roomsWithSmtp = 0;
        $availabilityRulesEnabled = 0;
        $totalCapacity = 0;
        $capacityCount = 0;

        foreach ($rooms as $room) {
            // Room type counts
            $type = $room['roomType'] ?? 'other';
            $roomTypeCounts[$type] = ($roomTypeCounts[$type] ?? 0) + 1;

            // Facilities counts
            foreach ($room['facilities'] ?? [] as $facility) {
                $facilitiesCounts[$facility] = ($facilitiesCounts[$facility] ?? 0) + 1;
            }

            // Auto-accept
            if (!empty($room['autoAccept'])) {
                $autoAcceptCount++;
            }

            // SMTP configured
            if (!empty($room['smtpConfig']['host'])) {
                $roomsWithSmtp++;
            }

            // Availability rules
            if (!empty($room['availabilityRules']['enabled'])) {
                $availabilityRulesEnabled++;
            }

            // Capacity for average
            $capacity = $room['capacity'] ?? 0;
            if ($capacity > 0) {
                $totalCapacity += $capacity;
                $capacityCount++;
            }
        }

        return [
            'roomTypeCounts' => $roomTypeCounts,
            'facilitiesCounts' => $facilitiesCounts,
            'autoAcceptCount' => $autoAcceptCount,
            'roomsWithSmtp' => $roomsWithSmtp,
            'availabilityRulesEnabled' => $availabilityRulesEnabled,
            'avgCapacity' => $capacityCount > 0 ? round($totalCapacity / $capacityCount, 2) : 0,
        ];
    }

    /**
     * Get SHA-256 hash of instance URL for privacy.
     * Delegates to LicenseService so the telemetry instanceHash is byte-for-byte
     * identical to license_usage.instance_url_hash — required for the license
     * server's enterprise-claim validation join.
     */
    private function getInstanceHash(): string {
        return $this->licenseService->getInstanceUrlHash();
    }

    /**
     * Get the RoomVox app version.
     */
    private function getAppVersion(): string {
        return $this->config->getAppValue(Application::APP_ID, 'installed_version', 'unknown');
    }

    /**
     * Get the Nextcloud version.
     */
    private function getNextcloudVersion(): string {
        return $this->config->getSystemValue('version', 'unknown');
    }

    /**
     * Get total user count.
     */
    /**
     * Accounts that exist but are disabled.
     *
     * They count towards the named-user total, because disabling is how
     * Nextcloud offboards someone while keeping their file ownership. Reported
     * separately so the difference is visible when usage is compared against a
     * contract -- otherwise a customer who has shrunk looks like one who never
     * did.
     *
     * Returns null rather than 0 on failure: the licence server distinguishes
     * "this app does not report the figure" from "measured, nobody disabled",
     * and a swallowed error must not read as the latter.
     */
    private function getDisabledUserCount(): ?int {
        try {
            $count = 0;
            $this->userManager->callForAllUsers(function ($user) use (&$count) {
                if (!$user->isEnabled()) {
                    $count++;
                }
            });
            return $count;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getUserCount(): int {
        try {
            $count = 0;
            $this->userManager->callForAllUsers(function ($user) use (&$count) {
                $count++;
            });
            return max(1, $count);
        } catch (\Exception $e) {
            return 1;
        }
    }

    /**
     * Get active user count for the last N days.
     */
    private function getActiveUserCount(int $days): int {
        try {
            $cutoffTime = time() - ($days * 24 * 60 * 60);
            $count = 0;

            $this->userManager->callForSeenUsers(function ($user) use (&$count, $cutoffTime) {
                if ($user->getLastLogin() >= $cutoffTime) {
                    $count++;
                }
            });

            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get ISO 3166-1 alpha-2 country code from default_phone_region setting.
     * Returns null if not configured — server derives country from timezone.
     */
    private function getCountryCode(): ?string {
        $region = $this->config->getSystemValue('default_phone_region', '');
        if (!empty($region) && preg_match('/^[A-Z]{2}$/', strtoupper($region))) {
            return strtoupper($region);
        }
        return null;
    }

    /**
     * Get the default timezone setting.
     */
    private function getDefaultTimezone(): string {
        $tz = $this->config->getSystemValue('default_timezone', '');
        if (!empty($tz) && $tz !== 'UTC') {
            return $tz;
        }
        $phpTz = date_default_timezone_get();
        if (!empty($phpTz) && $phpTz !== 'UTC') {
            return $phpTz;
        }
        return 'UTC';
    }

    /**
     * Detect web server from SERVER_SOFTWARE header.
     */
    private function getWebServer(): ?string {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? null;
        if ($software === null) {
            return null;
        }
        if (stripos($software, 'apache') !== false) {
            return 'Apache';
        }
        if (stripos($software, 'nginx') !== false) {
            return 'nginx';
        }
        return explode('/', $software)[0];
    }

    /**
     * Detect if running inside a Docker container.
     */
    private function isDocker(): bool {
        if (file_exists('/.dockerenv')) {
            return true;
        }
        if (file_exists('/proc/1/cgroup')) {
            $cgroup = @file_get_contents('/proc/1/cgroup');
            if ($cgroup !== false && str_contains($cgroup, 'docker')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the last report timestamp.
     */
    public function getLastReportTime(): ?int {
        $time = $this->config->getAppValue(Application::APP_ID, 'telemetry_last_report', '');
        return empty($time) ? null : (int)$time;
    }

    /**
     * Check if a report should be sent (not sent in last 24 hours).
     */
    public function shouldSendReport(): bool {
        if (!$this->isEnabled()) {
            return false;
        }

        $lastReport = $this->getLastReportTime();
        if ($lastReport === null) {
            return true;
        }

        return (time() - $lastReport) > (24 * 60 * 60);
    }

    /**
     * Get telemetry status for admin panel.
     */
    public function getStatus(): array {
        return [
            'enabled' => $this->isEnabled(),
            'lastReport' => $this->getLastReportTime(),
            'telemetryUrl' => $this->getTelemetryUrl()
        ];
    }
}
