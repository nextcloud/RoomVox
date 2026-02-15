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

class SettingsController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
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
            'roomTypes' => $this->getRoomTypes(),
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

    private function getCurrentUserId(): ?string {
        $user = $this->userSession->getUser();
        return $user?->getUID();
    }
}
