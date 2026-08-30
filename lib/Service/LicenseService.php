<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Support\Subscription\IRegistry;
use Psr\Log\LoggerInterface;

class LicenseService {
	private const FREE_ROOM_LIMIT = 10;
	private const FREE_ROOM_GROUP_LIMIT = 3;
	/**
	 * Above this many users the settings page suggests a support subscription.
	 *
	 * Not a limit and not enforced anywhere -- RoomVox keeps working exactly the
	 * same on either side of it. It marks the point where an instance is large
	 * enough that a support line is worth having, which is what the subscription
	 * actually buys.
	 */
	private const SUPPORT_NUDGE_USER_THRESHOLD = 100;
	private const LICENSE_SERVER_URL = 'https://licenses.voxcloud.nl';

	public function __construct(
		private IClientService $httpClient,
		private IConfig $config,
		private IDBConnection $db,
		private RoomService $roomService,
		private RoomGroupService $roomGroupService,
		private IUserManager $userManager,
		private LoggerInterface $logger,
		private ?IRegistry $subscriptionRegistry = null,
	) {
	}

	// --- License key management ---

	public function getLicenseKey(): string {
		return $this->config->getAppValue(Application::APP_ID, 'license_key', '');
	}

	public function setLicenseKey(string $key): void {
		$this->config->setAppValue(Application::APP_ID, 'license_key', trim($key));
		// Clear cached validation when key changes
		$this->config->deleteAppValue(Application::APP_ID, 'license_valid');
		$this->config->deleteAppValue(Application::APP_ID, 'license_info');
		$this->config->deleteAppValue(Application::APP_ID, 'license_limits');
	}

	public function getLicenseServerUrl(): string {
		return $this->config->getAppValue(Application::APP_ID, 'license_server_url', self::LICENSE_SERVER_URL);
	}

	/**
	 * SHA-256 of the instance URL, so the licence server never sees the URL
	 * itself.
	 *
	 * The URL is hashed as a full URL (scheme + host) so licence data lines up
	 * across apps.
	 *
	 * The source must be request-context-independent: the daily cron job and an
	 * admin web request both compute this hash, and if they disagreed the server
	 * would see two instances for one customer and freeze the seat count. We
	 * therefore use overwrite.cli.url when set, otherwise trusted_domains[0]
	 * promoted to a full URL — both are identical from cron and web.
	 */
	public function getInstanceUrlHash(): string {
		return hash('sha256', $this->normalizedInstanceUrl());
	}

	/**
	 * Request-independent instance URL, lower-cased and without a trailing
	 * slash. overwrite.cli.url wins; otherwise trusted_domains[0] is promoted
	 * to https:// so it is a full URL rather than a bare hostname.
	 */
	private function normalizedInstanceUrl(): string {
		$url = $this->config->getSystemValue('overwrite.cli.url', '');
		if (empty($url)) {
			$domain = $this->config->getSystemValue('trusted_domains', ['localhost'])[0] ?? 'localhost';
			// Promote a bare hostname to a full URL; leave an already-qualified
			// value (someone put a scheme in trusted_domains) untouched.
			$url = preg_match('#^https?://#i', $domain) ? $domain : 'https://' . $domain;
		}
		return strtolower(rtrim($url, '/'));
	}

	/**
	 * The hash this app used to send before the change above, so the server can
	 * recognise the instance across it instead of treating it as a second one —
	 * which would be refused, freezing the seat count at its pre-update value.
	 *
	 * Returns '' when overwrite.cli.url is set (the hash never changed for those
	 * instances) or when the legacy hash equals the current one (nothing to
	 * migrate). Otherwise it keeps returning the legacy hash: we have no local
	 * signal that the server has adopted the new hash, so we keep sending it —
	 * the server is idempotent and ignores it once adopted.
	 */
	public function getPreviousInstanceUrlHash(): string {
		if (!empty($this->config->getSystemValue('overwrite.cli.url', ''))) {
			return '';
		}

		$legacy = $this->config->getSystemValue('trusted_domains', ['localhost'])[0] ?? 'localhost';
		$hash = hash('sha256', strtolower(rtrim($legacy, '/')));

		return $hash === $this->getInstanceUrlHash() ? '' : $hash;
	}

	/**
	 * Includes previousInstanceUrlHash while the legacy hash differs from the
	 * current one, so the server can adopt the new hash. The field is omitted
	 * for instances whose hash never changed (overwrite.cli.url set).
	 */
	private function hashMigrationPayload(): array {
		$previous = $this->getPreviousInstanceUrlHash();

		return $previous === '' ? [] : ['previousInstanceUrlHash' => $previous];
	}

	// --- License validation ---

	public function validateLicense(): array {
		$licenseKey = $this->getLicenseKey();
		if (empty($licenseKey)) {
			return ['valid' => false, 'reason' => 'No license key configured', 'isFree' => true];
		}

		try {
			$client = $this->httpClient->newClient();
			$response = $client->post($this->getLicenseServerUrl() . '/api/licenses/validate', [
				'json' => [
					'licenseKey' => $licenseKey,
					'instanceUrlHash' => $this->getInstanceUrlHash(),
					'appType' => 'roomvox',
				] + $this->hashMigrationPayload(),
				'timeout' => 10,
				'headers' => [
					'User-Agent' => 'RoomVox/' . $this->getAppVersion(),
				],
			]);

			$data = json_decode($response->getBody(), true);

			if ($data['valid'] ?? false) {
				$this->config->setAppValue(Application::APP_ID, 'license_valid', 'true');
				$this->config->setAppValue(Application::APP_ID, 'license_info', json_encode($data));
				$this->config->setAppValue(Application::APP_ID, 'license_last_check', (string)time());
				return $data;
			}

			$this->config->setAppValue(Application::APP_ID, 'license_valid', 'false');
			return $data;
		} catch (\Exception $e) {
			$this->logger->warning('LicenseService: Failed to validate license', [
				'error' => $e->getMessage(),
			]);

			// Fallback to cached validation
			$cachedValid = $this->config->getAppValue(Application::APP_ID, 'license_valid', '');
			if ($cachedValid === 'true') {
				$cachedInfo = json_decode(
					$this->config->getAppValue(Application::APP_ID, 'license_info', '{}'),
					true
				);
				return array_merge($cachedInfo, ['valid' => true, 'cached' => true]);
			}

			return ['valid' => false, 'reason' => 'Could not connect to license server', 'cached' => false];
		}
	}

	// --- Usage reporting ---

	public function updateUsage(): array {
		$licenseKey = $this->getLicenseKey();
		if (empty($licenseKey)) {
			return ['success' => false, 'reason' => 'No license key configured'];
		}

		try {
			$stats = $this->getUsageStats();
			$client = $this->httpClient->newClient();
			$response = $client->post($this->getLicenseServerUrl() . '/api/licenses/usage', [
				'json' => [
					'licenseKey' => $licenseKey,
					'instanceUrlHash' => $this->getInstanceUrlHash(),
					'instanceName' => '',
					'appType' => 'roomvox',
					'currentRooms' => $stats['totalRooms'],
					'currentRoomGroups' => $stats['totalRoomGroups'],
					'currentUsers' => $stats['totalUsers'],
					'disabledUsers' => $this->countDisabledUsers(),
					// Tells the server how the count was taken, so readings from
					// releases that counted unreliably stay out of the averages
					// a contract is measured against.
					'countMethod' => self::COUNT_METHOD,
				] + $this->hashMigrationPayload(),
				'timeout' => 15,
				'headers' => [
					'User-Agent' => 'RoomVox/' . $this->getAppVersion(),
				],
			]);

			$data = json_decode($response->getBody(), true);

			if (isset($data['limits'])) {
				$this->config->setAppValue(Application::APP_ID, 'license_limits', json_encode($data['limits']));
			}

			return $data;
		} catch (\Exception $e) {
			$this->logger->warning('LicenseService: Failed to update usage', [
				'error' => $e->getMessage(),
			]);
			return ['success' => false, 'reason' => 'Could not connect to license server'];
		}
	}

	// --- Limit checking ---

	public function checkLimits(): array {
		$stats = $this->getUsageStats();
		$licenseKey = $this->getLicenseKey();

		if (empty($licenseKey)) {
			$roomsExceeded = $stats['totalRooms'] > self::FREE_ROOM_LIMIT;
			$groupsExceeded = $stats['totalRoomGroups'] > self::FREE_ROOM_GROUP_LIMIT;

			return [
				'isFree' => true,
				'roomLimit' => self::FREE_ROOM_LIMIT,
				'roomGroupLimit' => self::FREE_ROOM_GROUP_LIMIT,
				'roomsUsed' => $stats['totalRooms'],
				'roomGroupsUsed' => $stats['totalRoomGroups'],
				'roomsExceeded' => $roomsExceeded,
				'roomGroupsExceeded' => $groupsExceeded,
				'exceeded' => $roomsExceeded || $groupsExceeded,
			];
		}

		// Licensed: use cached limits from server
		$cachedLimits = json_decode(
			$this->config->getAppValue(Application::APP_ID, 'license_limits', '{}'),
			true
		);

		return [
			'isFree' => false,
			'roomLimit' => $cachedLimits['maxRooms'] ?? null,
			'roomGroupLimit' => $cachedLimits['maxRoomGroups'] ?? null,
			'roomsUsed' => $stats['totalRooms'],
			'roomGroupsUsed' => $stats['totalRoomGroups'],
			'roomsExceeded' => false,
			'roomGroupsExceeded' => false,
			'exceeded' => false,
		];
	}

	// --- Statistics for admin UI ---

	public function getStats(): array {
		$stats = $this->getUsageStats();
		$limits = $this->checkLimits();
		$licenseKey = $this->getLicenseKey();
		$hasLicense = !empty($licenseKey);

		$licenseValid = false;
		$licenseInfo = [];
		if ($hasLicense) {
			$cachedValid = $this->config->getAppValue(Application::APP_ID, 'license_valid', '');
			$licenseValid = $cachedValid === 'true';
			$licenseInfo = json_decode(
				$this->config->getAppValue(Application::APP_ID, 'license_info', '{}'),
				true
			);
		}

		// Mask license key for frontend display
		$maskedKey = '';
		if ($hasLicense) {
			$key = $this->getLicenseKey();
			if (strlen($key) > 8) {
				$maskedKey = substr($key, 0, 4) . '-····-····-' . substr($key, -4);
			} else {
				$maskedKey = '········';
			}
		}

		return [
			'totalRooms' => $stats['totalRooms'],
			'totalRoomGroups' => $stats['totalRoomGroups'],
			'totalUsers' => $stats['totalUsers'],
			'hasLicense' => $hasLicense,
			'licenseValid' => $licenseValid,
			'licenseInfo' => $licenseInfo,
			'licenseKeyMasked' => $maskedKey,
			'limits' => $limits,
			'freeRoomLimit' => self::FREE_ROOM_LIMIT,
			'freeRoomGroupLimit' => self::FREE_ROOM_GROUP_LIMIT,
			'supportNudgeUserThreshold' => self::SUPPORT_NUDGE_USER_THRESHOLD,
			'hasValidSubscription' => $this->hasValidSubscription(),
			'hasExtendedSupport' => $this->hasExtendedSupport(),
		];
	}

	/**
	 * Whether the host Nextcloud has a valid Enterprise subscription.
	 *
	 * Asks IRegistry directly rather than going through
	 * OCP\Util::hasExtendedSupport(). That helper answers a different question:
	 * delegateHasExtendedSupport() reports the paid *Extended Support* add-on,
	 * which sits on top of a subscription. An ordinary Enterprise customer
	 * without that add-on answers false, so every such instance looked like
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
	 * Enterprise. Mirrors TelemetryService, so the admin panel and the report
	 * sent to the licence server cannot disagree about the same instance.
	 */
	private function hasValidSubscription(): bool {
		try {
			return $this->subscriptionRegistry?->delegateHasValidSubscription() ?? false;
		} catch (\Throwable $e) {
			$this->logger->debug('LicenseService: delegateHasValidSubscription() check failed', [
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
			$this->logger->debug('LicenseService: delegateHasExtendedSupport() check failed', [
				'error' => $e->getMessage()
			]);
		}
		return false;
	}

	// --- Internal counting ---

	private function getUsageStats(): array {
		try {
			$rooms = $this->roomService->getAllRooms();
			$totalRooms = count($rooms);

			$groups = $this->roomGroupService->getAllGroups();
			$totalRoomGroups = count($groups);

			$totalUsers = $this->countAllUsers();

			return [
				'totalRooms' => $totalRooms,
				'totalRoomGroups' => $totalRoomGroups,
				'totalUsers' => $totalUsers,
			];
		} catch (\Exception $e) {
			$this->logger->warning('LicenseService: Failed to get usage stats', [
				'error' => $e->getMessage(),
			]);
			return [
				'totalRooms' => 0,
				'totalRoomGroups' => 0,
				'totalUsers' => 0,
			];
		}
	}

	private function getAppVersion(): string {
		return $this->config->getAppValue(Application::APP_ID, 'installed_version', '0.0.0');
	}
	/**
	 * How the user count is taken, reported alongside it so the licence server
	 * can keep readings from releases that counted unreliably out of the
	 * averages a contract is measured against.
	 */
	public const COUNT_METHOD = 'callForAllUsers';

	/**
	 * Total named users.
	 *
	 * callForAllUsers covers every backend, so LDAP and SSO users are included.
	 * Counting rows in oc_users misses them entirely: those accounts often
	 * exist only in the backend, which understated exactly the large customers
	 * a subscription is priced on ("per named user").
	 */
	private function countAllUsers(): int {
		$count = 0;
		$this->userManager->callForAllUsers(function () use (&$count) {
			$count++;
		});
		return $count;
	}

	/**
	 * Users that exist but are disabled. They count towards the named-user
	 * total, because disabling is how an account is retired without deleting
	 * its data — the seat is still occupied.
	 */
	private function countDisabledUsers(): int {
		try {
			$count = 0;
			$this->userManager->callForAllUsers(function ($user) use (&$count) {
				if (!$user->isEnabled()) {
					$count++;
				}
			});
			return $count;
		} catch (\Throwable $e) {
			$this->logger->warning('LicenseService: Failed to count disabled users', [
				'error' => $e->getMessage()
			]);
			return 0;
		}
	}

}
