<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ICrypto;

class SettingsController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
        private ICrypto $crypto,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct($appName, $request);
    }

    private const DEFAULT_ROOM_TYPES = [
        ['id' => 'meeting-room', 'label' => 'Meeting room'],
        ['id' => 'rehearsal-room', 'label' => 'Rehearsal room'],
        ['id' => 'studio', 'label' => 'Studio'],
        ['id' => 'lecture-hall', 'label' => 'Lecture hall'],
        ['id' => 'outdoor-area', 'label' => 'Outdoor area'],
        ['id' => 'other', 'label' => 'Other'],
    ];

    private const DEFAULT_FACILITIES = [
        ['id' => 'projector', 'label' => 'Projector'],
        ['id' => 'whiteboard', 'label' => 'Whiteboard'],
        ['id' => 'videoconf', 'label' => 'Video conference'],
        ['id' => 'audio', 'label' => 'Audio system'],
        ['id' => 'display', 'label' => 'Display screen'],
        ['id' => 'wheelchair', 'label' => 'Wheelchair accessible'],
    ];

    /**
     * Get global settings
     */
    public function get(): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $settings = [
            'defaultAutoAccept' => $this->appConfig->getValueString(Application::APP_ID, 'default_auto_accept', 'false') === 'true',
            'emailEnabled' => $this->appConfig->getValueString(Application::APP_ID, 'email_enabled', 'true') === 'true',
            'telemetryEnabled' => $this->appConfig->getValueString(Application::APP_ID, 'telemetry_enabled', 'true') === 'true',
            'roomTypes' => $this->getRoomTypes(),
            'facilities' => $this->getFacilities(),
            'exchangeEnabled' => $this->appConfig->getValueString(Application::APP_ID, 'exchange_enabled', 'false') === 'true',
            'exchangeTenantId' => $this->appConfig->getValueString(Application::APP_ID, 'exchange_tenant_id', ''),
            'exchangeClientId' => $this->appConfig->getValueString(Application::APP_ID, 'exchange_client_id', ''),
            'exchangeClientSecret' => $this->appConfig->getValueString(Application::APP_ID, 'exchange_client_secret', '') !== '' ? '***' : '',
            'exchangeWebhookMaxInlineSync' => (int) $this->appConfig->getValueString(Application::APP_ID, 'exchange_webhook_max_inline_sync', '1'),
            'exchangeWebhookRateLimit' => (int) $this->appConfig->getValueString(Application::APP_ID, 'exchange_webhook_rate_limit', '5'),
        ];

        return new JSONResponse($settings);
    }

    /**
     * Save global settings
     */
    public function save(): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $defaultAutoAccept = $this->request->getParam('defaultAutoAccept');
        if ($defaultAutoAccept !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID,
                'default_auto_accept',
                $defaultAutoAccept ? 'true' : 'false'
            );
        }

        $emailEnabled = $this->request->getParam('emailEnabled');
        if ($emailEnabled !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID,
                'email_enabled',
                $emailEnabled ? 'true' : 'false'
            );
        }

        $telemetryEnabled = $this->request->getParam('telemetryEnabled');
        if ($telemetryEnabled !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID,
                'telemetry_enabled',
                $telemetryEnabled ? 'true' : 'false'
            );
        }

        // Exchange settings
        $exchangeEnabled = $this->request->getParam('exchangeEnabled');
        if ($exchangeEnabled !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID,
                'exchange_enabled',
                $exchangeEnabled ? 'true' : 'false'
            );
        }

        $exchangeTenantId = $this->request->getParam('exchangeTenantId');
        if ($exchangeTenantId !== null) {
            $this->appConfig->setValueString(Application::APP_ID, 'exchange_tenant_id', (string)$exchangeTenantId);
        }

        $exchangeClientId = $this->request->getParam('exchangeClientId');
        if ($exchangeClientId !== null) {
            $this->appConfig->setValueString(Application::APP_ID, 'exchange_client_id', (string)$exchangeClientId);
        }

        $maxInlineSync = $this->request->getParam('exchangeWebhookMaxInlineSync');
        if ($maxInlineSync !== null) {
            $value = max(0, (int) $maxInlineSync);
            $this->appConfig->setValueString(Application::APP_ID, 'exchange_webhook_max_inline_sync', (string) $value);
        }

        $rateLimit = $this->request->getParam('exchangeWebhookRateLimit');
        if ($rateLimit !== null) {
            $value = max(0, (int) $rateLimit);
            $this->appConfig->setValueString(Application::APP_ID, 'exchange_webhook_rate_limit', (string) $value);
        }

        $exchangeClientSecret = $this->request->getParam('exchangeClientSecret');
        if ($exchangeClientSecret !== null && $exchangeClientSecret !== '' && $exchangeClientSecret !== '***') {
            $this->appConfig->setValueString(
                Application::APP_ID,
                'exchange_client_secret',
                $this->crypto->encrypt($exchangeClientSecret)
            );
        }

        $roomTypes = $this->request->getParam('roomTypes');
        if ($roomTypes !== null && is_array($roomTypes)) {
            $cleaned = [];
            foreach ($roomTypes as $type) {
                if (!empty($type['id']) && !empty($type['label'])) {
                    $cleaned[] = [
                        'id' => (string)$type['id'],
                        'label' => (string)$type['label'],
                    ];
                }
            }
            $this->appConfig->setValueString(
                Application::APP_ID,
                'room_types',
                json_encode($cleaned)
            );
        }

        $facilities = $this->request->getParam('facilities');
        if ($facilities !== null && is_array($facilities)) {
            $cleaned = [];
            foreach ($facilities as $facility) {
                if (!empty($facility['id']) && !empty($facility['label'])) {
                    $cleaned[] = [
                        'id' => (string)$facility['id'],
                        'label' => (string)$facility['label'],
                    ];
                }
            }
            $this->appConfig->setValueString(
                Application::APP_ID,
                'facilities',
                json_encode($cleaned)
            );
        }

        return new JSONResponse(['status' => 'ok']);
    }

    private function getRoomTypes(): array {
        $json = $this->appConfig->getValueString(Application::APP_ID, 'room_types', '');
        if ($json === '') {
            return self::DEFAULT_ROOM_TYPES;
        }
        $types = json_decode($json, true);
        return is_array($types) ? $types : self::DEFAULT_ROOM_TYPES;
    }

    private function getFacilities(): array {
        $json = $this->appConfig->getValueString(Application::APP_ID, 'facilities', '');
        if ($json === '') {
            return self::DEFAULT_FACILITIES;
        }
        $facilities = json_decode($json, true);
        return is_array($facilities) ? $facilities : self::DEFAULT_FACILITIES;
    }

    private function getCurrentUserId(): ?string {
        $user = $this->userSession->getUser();
        return $user?->getUID();
    }
}
