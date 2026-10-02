<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Support\Subscription\IRegistry;
use Psr\Log\LoggerInterface;

/**
 * Usage statistics about this installation, sent to licenses.voxcloud.nl
 * once a day -- and only after an administrator agreed.
 *
 * Bound by the VoxCloud telemetry rules (design TELEMETRY.md 1.1.0). The
 * field list lives in exactly one place, getFieldDefinitions(): collectData()
 * sends those keys and nothing else, and the admin pane shows the same list
 * with the purpose of each field. A field added later raises SCHEMA and is
 * withheld until the administrator agrees to the longer list.
 */
class TelemetryService {
    /** The field-list version this app version sends. */
    public const SCHEMA = 1;

    private const TELEMETRY_URL = 'https://licenses.voxcloud.nl/api/telemetry/roomvox';

    /** The endpoint accepts one report per hour; do not send sooner. */
    private const MIN_SECONDS_BETWEEN_REPORTS = 3600;

    public function __construct(
        private IClientService $httpClient,
        private IConfig $config,
        private IAppConfig $appConfig,
        private IL10N $l,
        private LoggerInterface $logger,
        private IUserManager $userManager,
        private RoomService $roomService,
        private RoomGroupService $roomGroupService,
        private LicenseService $licenseService,
        private TelemetryConsentService $consent,
        private ?IRegistry $subscriptionRegistry = null,
    ) {
    }

    /**
     * The fields each schema added, with a label and the purpose of each.
     *
     * The purposes follow the "why" column of TELEMETRY.md §3. They say plainly
     * that user counts are used to size a licence; do not soften that.
     *
     * The highest key here must equal SCHEMA. Protected only so a test can
     * add a later schema and check that its fields are withheld.
     *
     * @return array<int, array<string, array{label: string, purpose: string}>>
     */
    protected function fieldsBySchema(IL10N $l): array {
        $appUsage = $l->t('Shows how RoomVox features are used, to decide what to develop and maintain.');
        $licence = $l->t('Used to size a license and to find installations that may need one.');

        return [
            1 => [
                'instanceHash' => [
                    'label' => $l->t('Installation identifier'),
                    'purpose' => $l->t('A SHA-256 hash of this server\'s address, needed to tell installations apart and to join the reports of the VoxCloud apps on one server with its license records. The address itself is not sent.'),
                ],
                'telemetrySchema' => [
                    'label' => $l->t('Field-list version'),
                    'purpose' => $l->t('Which version of this list the report follows, so that fields added later are only sent after you agree to them.'),
                ],
                'appVersion' => [
                    'label' => $l->t('RoomVox version'),
                    'purpose' => $l->t('Shows which RoomVox releases are still in use.'),
                ],
                'nextcloudVersion' => [
                    'label' => $l->t('Nextcloud version'),
                    'purpose' => $l->t('Shows which Nextcloud versions RoomVox must keep supporting.'),
                ],
                'phpVersion' => [
                    'label' => $l->t('PHP version'),
                    'purpose' => $l->t('Shows which PHP versions RoomVox must keep supporting. Only the major and minor version, such as 8.3.'),
                ],
                'totalUsers' => [
                    'label' => $l->t('Number of user accounts'),
                    'purpose' => $licence,
                ],
                'hasValidSubscription' => [
                    'label' => $l->t('Nextcloud subscription (yes or no)'),
                    'purpose' => $l->t('Shows whether this server has a Nextcloud Enterprise subscription. Such servers are listed as Enterprise customers and are not approached about a license.'),
                ],
                'activeUsers30d' => [
                    'label' => $l->t('Users active in the last 30 days'),
                    'purpose' => $licence,
                ],
                'disabledUsers' => [
                    'label' => $l->t('Number of disabled accounts'),
                    'purpose' => $licence,
                ],
                'countryCode' => [
                    'label' => $l->t('Country'),
                    'purpose' => $l->t('Shown on a world map of installations. Taken from the default phone region, or worked out on this server from the default time zone. The time zone itself is not sent.'),
                ],
                'totalRooms' => [
                    'label' => $l->t('Number of rooms'),
                    'purpose' => $appUsage,
                ],
                'totalRoomGroups' => [
                    'label' => $l->t('Number of room groups'),
                    'purpose' => $appUsage,
                ],
                'autoAcceptCount' => [
                    'label' => $l->t('Rooms that accept bookings automatically'),
                    'purpose' => $appUsage,
                ],
                'roomsWithSmtp' => [
                    'label' => $l->t('Rooms with their own mail server'),
                    'purpose' => $appUsage,
                ],
                'exchangeSyncEnabled' => [
                    'label' => $l->t('Microsoft Exchange sync switched on (yes or no)'),
                    'purpose' => $l->t('Shows whether the Exchange integration is used. The tenant ID, client ID and client secret are never sent.'),
                ],
                'roomsWithExchange' => [
                    'label' => $l->t('Rooms synced with Microsoft Exchange'),
                    'purpose' => $appUsage,
                ],
            ],
        ];
    }

    /**
     * Every field up to and including $schema (all fields when null), in the
     * order they are sent.
     *
     * @return array<string, array{label: string, purpose: string, schema: int}>
     */
    public function getFieldDefinitions(?IL10N $l = null, ?int $schema = null): array {
        $fields = [];
        foreach ($this->fieldsBySchema($l ?? $this->l) as $since => $definitions) {
            if ($schema !== null && $since > $schema) {
                continue;
            }
            foreach ($definitions as $key => $definition) {
                $fields[$key] = $definition + ['schema' => $since];
            }
        }
        return $fields;
    }

    /**
     * Whether an administrator agreed to send usage statistics.
     * A missing value means no.
     */
    public function isEnabled(): bool {
        return $this->consent->isEnabled();
    }

    /**
     * Get the telemetry server URL.
     */
    public function getTelemetryUrl(): string {
        return $this->appConfig->getValueString(Application::APP_ID, 'telemetry_url', self::TELEMETRY_URL);
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
     *
     * Refuses while telemetry is off, and never switches it on for the send.
     * A report within the last hour is answered with 'recently_sent' -- the
     * endpoint would refuse it with HTTP 429 anyway.
     *
     * @return array{success: bool, reason?: string, message?: string}
     */
    public function sendReportWithDetails(): array {
        if (!$this->isEnabled()) {
            $this->logger->debug('TelemetryService: Telemetry is disabled, skipping report');
            return ['success' => false, 'reason' => 'disabled'];
        }

        $lastReport = $this->getLastReportTime();
        if ($lastReport !== null && (time() - $lastReport) < self::MIN_SECONDS_BETWEEN_REPORTS) {
            return ['success' => false, 'reason' => 'recently_sent'];
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
                $this->logger->info('TelemetryService: Report sent successfully');
                $this->appConfig->setValueString(Application::APP_ID, 'telemetry_last_report', (string)time());
                return ['success' => true];
            }

            if ($statusCode === 429) {
                return ['success' => false, 'reason' => 'recently_sent'];
            }

            return ['success' => false, 'reason' => 'server_error', 'message' => 'HTTP ' . $statusCode];
        } catch (\Exception $e) {
            $message = $e->getMessage();

            // Extract server error message from Guzzle response
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $errorResponse = $e->getResponse();
                if ($errorResponse->getStatusCode() === 429) {
                    return ['success' => false, 'reason' => 'recently_sent'];
                }
                $body = (string) $errorResponse->getBody();
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
     * The report: exactly the fields of the schema the administrator agreed
     * to, in definition order. A field added by a later schema is withheld
     * until the administrator agrees to the longer list.
     */
    public function collectData(): array {
        $schema = $this->consent->getConsentedSchema();
        $rooms = $this->roomService->getAllRooms();
        $roomStats = $this->calculateRoomStats($rooms);

        $values = [
            'instanceHash' => $this->getInstanceHash(),
            'telemetrySchema' => $schema,
            'appVersion' => $this->getAppVersion(),
            'nextcloudVersion' => $this->getNextcloudVersion(),
            'phpVersion' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'totalUsers' => $this->getUserCount(),
            'hasValidSubscription' => $this->hasValidSubscription(),
            'activeUsers30d' => $this->getActiveUserCount(30),
            'disabledUsers' => $this->getDisabledUserCount(),
            'countryCode' => $this->getCountryCode(),
            'totalRooms' => count($rooms),
            'totalRoomGroups' => count($this->roomGroupService->getAllGroups()),
            'autoAcceptCount' => $roomStats['autoAcceptCount'],
            'roomsWithSmtp' => $roomStats['roomsWithSmtp'],
            // Whether the Microsoft Exchange integration is switched on, plus how
            // many rooms are actually wired to a resource mailbox. Deliberately
            // only the on/off flag and a count: the tenant id, client id and
            // client secret sitting next to it in appconfig are never reported.
            // A tenant id would name the organisation outright.
            'exchangeSyncEnabled' => $this->isExchangeSyncEnabled(),
            'roomsWithExchange' => $roomStats['roomsWithExchange'],
        ];

        $data = [];
        foreach (array_keys($this->getFieldDefinitions(null, $schema)) as $key) {
            $data[$key] = $values[$key];
        }
        return $data;
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
     * Whether the admin switched the Microsoft Exchange integration on.
     *
     * Reads the single `exchange_enabled` flag and nothing else. The
     * neighbouring `exchange_tenant_id`, `exchange_client_id` and
     * `exchange_client_secret` keys stay out of telemetry by design -- a
     * tenant id is directly traceable to an organisation. Hashing it would
     * not help: the set of tenant ids is small enough to enumerate.
     *
     * Says "switched on", not "working": an instance whose client secret has
     * expired still reports true while nothing actually syncs. That is what
     * roomsWithExchange is for.
     */
    private function isExchangeSyncEnabled(): bool {
        return $this->appConfig->getValueString(Application::APP_ID, 'exchange_enabled', 'false') === 'true';
    }

    /**
     * Aggregate counts over the rooms. Counts only: nothing keyed by room.
     *
     * @return array{autoAcceptCount: int, roomsWithSmtp: int, roomsWithExchange: int}
     */
    private function calculateRoomStats(array $rooms): array {
        $autoAcceptCount = 0;
        $roomsWithSmtp = 0;
        $roomsWithExchange = 0;

        foreach ($rooms as $room) {
            if (!empty($room['autoAccept'])) {
                $autoAcceptCount++;
            }

            if (!empty($room['smtpConfig']['host'])) {
                $roomsWithSmtp++;
            }

            // Rooms actually wired to an Exchange resource. Mirrors
            // ExchangeSyncService::isExchangeRoom() minus the global flag,
            // which is reported separately -- a room stays counted here when
            // the admin flips the global switch off, so the two figures
            // together show how much would break if the sync went away.
            $exchangeConfig = $room['exchangeConfig'] ?? null;
            if (is_array($exchangeConfig)
                && !empty($exchangeConfig['resourceEmail'])
                && !empty($exchangeConfig['syncEnabled'])) {
                $roomsWithExchange++;
            }
        }

        return [
            'autoAcceptCount' => $autoAcceptCount,
            'roomsWithSmtp' => $roomsWithSmtp,
            'roomsWithExchange' => $roomsWithExchange,
        ];
    }

    /**
     * Get SHA-256 hash of instance URL.
     * Delegates to LicenseService so the telemetry instanceHash is byte-for-byte
     * identical to license_usage.instance_url_hash -- the platform verifies an
     * Enterprise claim by joining on it.
     */
    private function getInstanceHash(): string {
        return $this->licenseService->getInstanceUrlHash();
    }

    /**
     * Get the RoomVox app version.
     */
    private function getAppVersion(): string {
        return $this->appConfig->getValueString(Application::APP_ID, 'installed_version', 'unknown');
    }

    /**
     * Get the Nextcloud version.
     */
    private function getNextcloudVersion(): string {
        return (string)$this->config->getSystemValue('version', 'unknown');
    }

    /**
     * Every account on the server, disabled ones included.
     *
     * Null when the count failed: the platform must store "unknown", not a
     * made-up figure (TELEMETRY.md §8, "missing is not zero").
     */
    private function getUserCount(): ?int {
        try {
            $count = 0;
            $this->userManager->callForAllUsers(function ($user) use (&$count) {
                $count++;
            });
            return $count;
        } catch (\Exception $e) {
            return null;
        }
    }

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

    /**
     * Accounts that logged in during the last N days. Null when the count
     * failed, for the same reason as the other user counts.
     */
    private function getActiveUserCount(int $days): ?int {
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
            return null;
        }
    }

    /**
     * ISO 3166-1 alpha-2 country of this installation, worked out here.
     *
     * The administrator's default_phone_region comes first. Without it, the
     * country of default_timezone is looked up locally by PHP; only the
     * two-letter code is sent, never the time zone. UTC, the Etc/* zones and
     * an invalid zone have no country and give null.
     */
    private function getCountryCode(): ?string {
        $region = strtoupper(trim((string)$this->config->getSystemValue('default_phone_region', '')));
        if (preg_match('/^[A-Z]{2}$/', $region)) {
            return $region;
        }

        $timezone = trim((string)$this->config->getSystemValue('default_timezone', ''));
        if ($timezone === '') {
            return null;
        }
        try {
            $location = (new \DateTimeZone($timezone))->getLocation();
        } catch (\Exception $e) {
            return null;
        }
        $code = is_array($location) ? ($location['country_code'] ?? '??') : '??';
        return preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
    }

    /**
     * Get the last report timestamp.
     */
    public function getLastReportTime(): ?int {
        $time = $this->appConfig->getValueString(Application::APP_ID, 'telemetry_last_report', '');
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
     * Telemetry state for the admin pane, including the field list with the
     * purpose of each field -- from the same definition collectData() uses.
     *
     * A field is marked 'withheld' while telemetry is on for an older field
     * list that did not include it.
     */
    public function getStatus(): array {
        $enabled = $this->isEnabled();
        $consented = $this->consent->getConsentedSchema();

        $fields = [];
        foreach ($this->getFieldDefinitions() as $key => $definition) {
            $fields[] = [
                'key' => $key,
                'label' => $definition['label'],
                'purpose' => $definition['purpose'],
                'withheld' => $enabled && $definition['schema'] > $consented,
            ];
        }

        return [
            'enabled' => $enabled,
            'lastReport' => $this->getLastReportTime(),
            'schema' => self::SCHEMA,
            'consentedSchema' => $enabled ? $consented : null,
            'fields' => $fields,
        ];
    }
}
