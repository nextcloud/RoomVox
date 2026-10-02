<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\Service\LicenseService;
use OCA\RoomVox\Service\TelemetryConsentService;
use OCA\RoomVox\Service\TelemetryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class LicenseController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private LicenseService $licenseService,
		private TelemetryService $telemetryService,
		private TelemetryConsentService $telemetryConsent,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}
		return $this->groupManager->isAdmin($user->getUID());
	}

	#[NoCSRFRequired]
	public function getStats(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['success' => false, 'message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$stats = $this->licenseService->getStats();
			// The usage-statistics state and the exact field list, with the
			// purpose of each field, from the definition the report uses.
			$stats['telemetry'] = $this->telemetryService->getStatus();
			return new DataResponse([
				'success' => true,
				'stats' => $stats,
			]);
		} catch (\Exception $e) {
			return new DataResponse([
				'success' => false,
				'message' => 'Failed to get license stats',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	public function saveSettings(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['success' => false, 'message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		$licenseKey = $this->request->getParam('licenseKey');
		if ($licenseKey !== null) {
			$this->licenseService->setLicenseKey((string)$licenseKey);
		}

		return new DataResponse(['success' => true, 'message' => 'License settings saved']);
	}

	public function validate(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['success' => false, 'message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->licenseService->validateLicense();
			return new DataResponse(['success' => true, 'validation' => $result]);
		} catch (\Exception $e) {
			return new DataResponse([
				'success' => false,
				'message' => 'License validation failed',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	public function updateUsage(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['success' => false, 'message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->licenseService->updateUsage();
			return new DataResponse(['success' => true, 'result' => $result]);
		} catch (\Exception $e) {
			return new DataResponse([
				'success' => false,
				'message' => 'Usage update failed',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * The one usage-statistics switch, in the Support pane. Switching on is
	 * consent to the field list this version sends, and answers the pending
	 * notification for every administrator.
	 */
	public function setTelemetry(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['success' => false, 'message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		if ($this->request->getParam('enabled') === true) {
			$this->telemetryConsent->give();
		} else {
			$this->telemetryConsent->withdraw();
		}

		return new DataResponse([
			'success' => true,
			'telemetry' => $this->telemetryService->getStatus(),
		]);
	}

	/**
	 * "Send report now". Refuses while usage statistics are off and never
	 * switches them on; 'recently_sent' when a report went out within the hour.
	 */
	public function sendTelemetry(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['success' => false, 'message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		$result = $this->telemetryService->sendReportWithDetails();
		$result['lastReport'] = $this->telemetryService->getLastReportTime();
		return new DataResponse($result);
	}
}
