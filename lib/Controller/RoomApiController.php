<?php

declare(strict_types=1);

namespace OCA\RoomBooking\Controller;

use OCA\RoomBooking\Service\CalDAVService;
use OCA\RoomBooking\Service\MailService;
use OCA\RoomBooking\Service\PermissionService;
use OCA\RoomBooking\Service\RoomService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class RoomApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private RoomService $roomService,
        private PermissionService $permissionService,
        private CalDAVService $calDAVService,
        private MailService $mailService,
        private IRoomManager $roomManager,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List all rooms (admin sees all, managers see managed rooms)
     */
    public function index(): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $rooms = $this->roomService->getAllRooms();

        // Non-admins only see rooms they can manage
        if (!$this->groupManager->isAdmin($userId)) {
            $rooms = array_filter($rooms, function ($room) use ($userId) {
                return $this->permissionService->canManage($userId, $room['id']);
            });
        }

        // Strip SMTP passwords from response
        $sanitized = array_map(function ($room) {
            if (!empty($room['smtpConfig']['password'])) {
                $room['smtpConfig']['password'] = '***';
            }
            return $room;
        }, array_values($rooms));

        return new JSONResponse($sanitized);
    }

    /**
     * Get a single room
     */
    public function show(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $room = $this->roomService->getRoom($id);
        if ($room === null) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

        if (!$this->groupManager->isAdmin($userId) && !$this->permissionService->canManage($userId, $id)) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }

        if (!empty($room['smtpConfig']['password'])) {
            $room['smtpConfig']['password'] = '***';
        }

        return new JSONResponse($room);
    }

    /**
     * Create a new room (admin only)
     */
    public function create(): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $data = [
            'name' => $this->request->getParam('name', ''),
            'email' => $this->request->getParam('email', ''),
            'description' => $this->request->getParam('description', ''),
            'capacity' => $this->request->getParam('capacity', 0),
            'location' => $this->request->getParam('location', ''),
            'facilities' => $this->request->getParam('facilities', []),
            'autoAccept' => $this->request->getParam('autoAccept', false),
            'smtpConfig' => $this->request->getParam('smtpConfig', null),
        ];

        if (empty($data['name'])) {
            return new JSONResponse(['error' => 'Room name is required'], 400);
        }

        try {
            // 1. Create room in config
            $room = $this->roomService->createRoom($data);

            // 2. Provision CalDAV calendar
            $calendarUri = $this->calDAVService->provisionCalendar($room['userId'], $room['name']);
            $this->roomService->setCalendarUri($room['id'], $calendarUri);
            $room['calendarUri'] = $calendarUri;

            // 3. Initialize empty permissions
            $this->permissionService->setPermissions($room['id'], [
                'viewers' => [],
                'bookers' => [],
                'managers' => [],
            ]);

            // 4. Sync room cache so it appears in calendar apps immediately
            $this->syncRoomCache();

            if (!empty($room['smtpConfig']['password'])) {
                $room['smtpConfig']['password'] = '***';
            }

            return new JSONResponse($room, 201);
        } catch (\Exception $e) {
            $this->logger->error("Failed to create room: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to create room: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update a room (admin or manager)
     */
    public function update(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        if (!$this->groupManager->isAdmin($userId) && !$this->permissionService->canManage($userId, $id)) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }

        $data = [];
        $updatableFields = ['name', 'email', 'description', 'capacity', 'location', 'facilities', 'autoAccept', 'active', 'smtpConfig'];

        foreach ($updatableFields as $field) {
            $value = $this->request->getParam($field);
            if ($value !== null) {
                $data[$field] = $value;
            }
        }

        try {
            $room = $this->roomService->updateRoom($id, $data);
            if ($room === null) {
                return new JSONResponse(['error' => 'Room not found'], 404);
            }

            // Sync room cache
            $this->syncRoomCache();

            if (!empty($room['smtpConfig']['password'])) {
                $room['smtpConfig']['password'] = '***';
            }

            return new JSONResponse($room);
        } catch (\Exception $e) {
            $this->logger->error("Failed to update room {$id}: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to update room'], 500);
        }
    }

    /**
     * Delete a room (admin only)
     */
    public function destroy(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $room = $this->roomService->getRoom($id);
        if ($room === null) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

        try {
            // Delete calendar first
            $this->calDAVService->deleteCalendar($room['userId']);
        } catch (\Exception $e) {
            $this->logger->warning("Failed to delete calendar for room {$id}: " . $e->getMessage());
        }

        // Delete permissions
        $this->permissionService->deletePermissions($id);

        // Delete room config
        $this->roomService->deleteRoom($id);

        // Sync room cache
        $this->syncRoomCache();

        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Get permissions for a room
     */
    public function getPermissions(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        if (!$this->groupManager->isAdmin($userId) && !$this->permissionService->canManage($userId, $id)) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }

        $permissions = $this->permissionService->getPermissions($id);
        return new JSONResponse($permissions);
    }

    /**
     * Set permissions for a room
     */
    public function setPermissions(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        if (!$this->groupManager->isAdmin($userId) && !$this->permissionService->canManage($userId, $id)) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }

        $permissions = [
            'viewers' => $this->request->getParam('viewers', []),
            'bookers' => $this->request->getParam('bookers', []),
            'managers' => $this->request->getParam('managers', []),
        ];

        $this->permissionService->setPermissions($id, $permissions);

        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Search users and groups for the permission editor
     */
    public function searchSharees(): JSONResponse {
        $search = $this->request->getParam('search', '');
        $results = [];

        // Search users
        $users = $this->userManager->search($search, 25);
        foreach ($users as $user) {
            $uid = $user->getUID();
            // Skip room accounts
            if (str_starts_with($uid, 'rb_')) {
                continue;
            }
            $results[] = [
                'type' => 'user',
                'id' => $uid,
                'label' => $user->getDisplayName() . ' (' . $uid . ')',
            ];
        }

        // Search groups
        $groups = $this->groupManager->search($search, 25);
        foreach ($groups as $group) {
            $results[] = [
                'type' => 'group',
                'id' => $group->getGID(),
                'label' => $group->getDisplayName() . ' (group)',
            ];
        }

        return new JSONResponse($results);
    }

    /**
     * Trigger room cache sync so rooms appear immediately in calendar apps
     */
    private function syncRoomCache(): void {
        try {
            $this->roomManager->update();
        } catch (\Exception $e) {
            $this->logger->warning('Failed to sync room cache: ' . $e->getMessage());
        }
    }

    private function getCurrentUserId(): ?string {
        $user = $this->userSession->getUser();
        return $user?->getUID();
    }
}
