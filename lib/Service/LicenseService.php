<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class LicenseService {
	private const FREE_ROOM_LIMIT = 10;
	private const FREE_ROOM_GROUP_LIMIT = 3;
	private const LICENSE_SERVER_URL = 'https://licenses.voxcloud.nl';

	public function __construct(
		private IClientService $httpClient,
		private IConfig $config,
		private IDBConnection $db,
		private RoomService $roomService,
		private RoomGroupService $roomGroupService,
		private LoggerInterface $logger,
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

	public function getInstanceUrlHash(): string {
		$instanceUrl = $this->config->getSystemValue('overwrite.cli.url', '');
		if (empty($instanceUrl)) {
			$instanceUrl = $this->config->getSystemValue('trusted_domains', ['localhost'])[0] ?? 'localhost';
		}
		return hash('sha256', strtolower(rtrim($instanceUrl, '/')));
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
				],
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
					'instanceName' => $this->config->getAppValue(Application::APP_ID, 'organization_name', ''),
					'appType' => 'roomvox',
					'currentRooms' => $stats['totalRooms'],
					'currentRoomGroups' => $stats['totalRoomGroups'],
					'currentUsers' => $stats['totalUsers'],
				],
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
		];
	}

	// --- Internal counting ---

	private function getUsageStats(): array {
		try {
			$rooms = $this->roomService->getAllRooms();
			$totalRooms = count($rooms);

			$groups = $this->roomGroupService->getAllGroups();
			$totalRoomGroups = count($groups);

			// Total users
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('uid', 'count'))
				->from('users');
			$result = $qb->executeQuery();
			$totalUsers = (int)$result->fetchOne();
			$result->closeCursor();

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
}
