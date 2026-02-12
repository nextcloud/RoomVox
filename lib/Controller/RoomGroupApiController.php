<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomGroupService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class RoomGroupApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private RoomGroupService $roomGroupService,
        private RoomService $roomService,
        private PermissionService $permissionService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List all room groups (admin only)
     */
    public function index(): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $groups = $this->roomGroupService->getAllGroups();
        return new JSONResponse(array_values($groups));
    }

    /**
     * Get a single room group
     */
    public function show(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $group = $this->roomGroupService->getGroup($id);
        if ($group === null) {
            return new JSONResponse(['error' => 'Room group not found'], 404);
        }

        return new JSONResponse($group);
    }

    /**
     * Create a new room group
     */
    public function create(): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $data = [
            'name' => $this->request->getParam('name', ''),
            'description' => $this->request->getParam('description', ''),
        ];

        if (empty($data['name'])) {
            return new JSONResponse(['error' => 'Group name is required'], 400);
        }

        try {
            $group = $this->roomGroupService->createGroup($data);

            // Initialize empty permissions
            $this->permissionService->setGroupPermissions($group['id'], [
                'viewers' => [],
                'bookers' => [],
                'managers' => [],
            ]);

            return new JSONResponse($group, 201);
        } catch (\Exception $e) {
            $this->logger->error("Failed to create room group: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to create room group: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update a room group
     */
    public function update(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $data = [];
        foreach (['name', 'description'] as $field) {
            $value = $this->request->getParam($field);
            if ($value !== null) {
                $data[$field] = $value;
            }
        }

        try {
            $group = $this->roomGroupService->updateGroup($id, $data);
            if ($group === null) {
                return new JSONResponse(['error' => 'Room group not found'], 404);
            }

            return new JSONResponse($group);
        } catch (\Exception $e) {
            $this->logger->error("Failed to update room group {$id}: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to update room group'], 500);
        }
    }

    /**
     * Delete a room group (only if no rooms assigned)
     */
    public function destroy(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        // Check if any rooms are assigned to this group
        $rooms = $this->roomService->getAllRooms();
        foreach ($rooms as $room) {
            if (($room['groupId'] ?? null) === $id) {
                return new JSONResponse([
                    'error' => 'Cannot delete group: rooms are still assigned. Move or unassign rooms first.',
                ], 409);
            }
        }

        $group = $this->roomGroupService->getGroup($id);
        if ($group === null) {
            return new JSONResponse(['error' => 'Room group not found'], 404);
        }

        // Delete group permissions
        $this->permissionService->deleteGroupPermissions($id);

        // Delete the group
        $this->roomGroupService->deleteGroup($id);

        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Get permissions for a room group
     */
    public function getPermissions(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $permissions = $this->permissionService->getGroupPermissions($id);
        return new JSONResponse($permissions);
    }

    /**
     * Set permissions for a room group
     */
    public function setPermissions(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $permissions = [
            'viewers' => $this->request->getParam('viewers', []),
            'bookers' => $this->request->getParam('bookers', []),
            'managers' => $this->request->getParam('managers', []),
        ];

        $this->permissionService->setGroupPermissions($id, $permissions);

        return new JSONResponse(['status' => 'ok']);
    }

    private function getCurrentUserId(): ?string {
        $user = $this->userSession->getUser();
        return $user?->getUID();
    }
}
