<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\Service\TelemetryConsentService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The three answers to the usage-statistics notification.
 *
 * OCS endpoints, called by the notifications app the same way Nextcloud's own
 * notification actions are (files_sharing accept/decline). Admin only and
 * CSRF-checked: there is deliberately no NoAdminRequired or NoCSRFRequired.
 * Every answer marks the notification processed for all administrators.
 */
class TelemetryConsentController extends OCSController {
    public function __construct(
        string $appName,
        IRequest $request,
        private TelemetryConsentService $consent,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * "Share usage statistics"
     */
    public function share(): DataResponse {
        $this->consent->give();
        return new DataResponse(['enabled' => true]);
    }

    /**
     * "Not now"
     */
    public function postpone(): DataResponse {
        $this->consent->postpone();
        return new DataResponse(['enabled' => $this->consent->isEnabled()]);
    }

    /**
     * "Never ask again"
     */
    public function neverAsk(): DataResponse {
        $this->consent->neverAsk();
        return new DataResponse(['enabled' => $this->consent->isEnabled()]);
    }
}
