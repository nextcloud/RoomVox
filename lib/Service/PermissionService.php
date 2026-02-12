<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

class PermissionService {
    private const PERM_PREFIX = 'permissions/';
    private const GROUP_PERM_PREFIX = 'group_permissions/';

    private ?RoomService $roomService = null;

    public function __construct(
        private IAppConfig $appConfig,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Late injection to avoid circular dependency
     */
    public function setRoomService(RoomService $roomService): void {
        $this->roomService = $roomService;
    }

    // ── Room permissions ─────────────────────────────────────────

    /**
     * Get permissions for a room (room-level only, no group merge)
     * @return array{viewers: array, bookers: array, managers: array}
     */
    public function getPermissions(string $roomId): array {
        return $this->loadPermissions(self::PERM_PREFIX . $roomId);
    }

    /**
     * Set permissions for a room
     */
    public function setPermissions(string $roomId, array $permissions): void {
        $this->savePermissions(self::PERM_PREFIX . $roomId, $permissions);
        $this->logger->info("Permissions updated for room: {$roomId}");
    }

    /**
     * Delete permissions for a room
     */
    public function deletePermissions(string $roomId): void {
        $this->appConfig->deleteKey(Application::APP_ID, self::PERM_PREFIX . $roomId);
    }

    // ── Room group permissions ───────────────────────────────────

    /**
     * Get permissions for a room group
     * @return array{viewers: array, bookers: array, managers: array}
     */
    public function getGroupPermissions(string $groupId): array {
        return $this->loadPermissions(self::GROUP_PERM_PREFIX . $groupId);
    }

    /**
     * Set permissions for a room group
     */
    public function setGroupPermissions(string $groupId, array $permissions): void {
        $this->savePermissions(self::GROUP_PERM_PREFIX . $groupId, $permissions);
        $this->logger->info("Permissions updated for room group: {$groupId}");
    }

    /**
     * Delete permissions for a room group
     */
    public function deleteGroupPermissions(string $groupId): void {
        $this->appConfig->deleteKey(Application::APP_ID, self::GROUP_PERM_PREFIX . $groupId);
    }

    // ── Effective permissions (room + group merged) ──────────────

    /**
     * Get effective permissions for a room (union of room-level + group-level).
     * If the room belongs to a group, both are merged.
     * If not, only room-level permissions are returned.
     */
    public function getEffectivePermissions(string $roomId): array {
        $roomPerms = $this->getPermissions($roomId);

        if ($this->roomService === null) {
            return $roomPerms;
        }

        $room = $this->roomService->getRoom($roomId);
        if ($room === null || empty($room['groupId'])) {
            return $roomPerms;
        }

        $groupPerms = $this->getGroupPermissions($room['groupId']);

        return [
            'viewers' => $this->mergeEntries($groupPerms['viewers'], $roomPerms['viewers']),
            'bookers' => $this->mergeEntries($groupPerms['bookers'], $roomPerms['bookers']),
            'managers' => $this->mergeEntries($groupPerms['managers'], $roomPerms['managers']),
        ];
    }

    // ── Permission checks ────────────────────────────────────────

    /**
     * Check if user can view a room (viewer, booker, manager, or NC admin)
     */
    public function canView(string $userId, string $roomId): bool {
        if ($this->isAdmin($userId)) {
            return true;
        }

        $role = $this->getEffectiveRole($userId, $roomId);
        return in_array($role, ['viewer', 'booker', 'manager']);
    }

    /**
     * Check if user can book a room (booker, manager, or NC admin)
     */
    public function canBook(string $userId, string $roomId): bool {
        if ($this->isAdmin($userId)) {
            return true;
        }

        $role = $this->getEffectiveRole($userId, $roomId);
        return in_array($role, ['booker', 'manager']);
    }

    /**
     * Check if user can manage a room (manager or NC admin)
     */
    public function canManage(string $userId, string $roomId): bool {
        if ($this->isAdmin($userId)) {
            return true;
        }

        $role = $this->getEffectiveRole($userId, $roomId);
        return $role === 'manager';
    }

    /**
     * Get the effective role for a user on a room.
     * Uses effective permissions (room + group merged).
     */
    public function getEffectiveRole(string $userId, string $roomId): string {
        $permissions = $this->getEffectivePermissions($roomId);

        if ($this->matchesAnyEntry($userId, $permissions['managers'])) {
            return 'manager';
        }

        if ($this->matchesAnyEntry($userId, $permissions['bookers'])) {
            return 'booker';
        }

        if ($this->matchesAnyEntry($userId, $permissions['viewers'])) {
            return 'viewer';
        }

        return 'none';
    }

    /**
     * Get all rooms visible to a user
     */
    public function getVisibleRoomIds(string $userId, array $allRoomIds): array {
        if ($this->isAdmin($userId)) {
            return $allRoomIds;
        }

        return array_values(array_filter($allRoomIds, function (string $roomId) use ($userId) {
            return $this->canView($userId, $roomId);
        }));
    }

    /**
     * Get all manager user IDs for a room (resolved from groups).
     * Uses effective permissions so group-level managers are included.
     * @return string[]
     */
    public function getManagerUserIds(string $roomId): array {
        $permissions = $this->getEffectivePermissions($roomId);
        return $this->resolveUserIds($permissions['managers']);
    }

    // ── Private helpers ──────────────────────────────────────────

    private function loadPermissions(string $key): array {
        $json = $this->appConfig->getValueString(Application::APP_ID, $key, '');

        if ($json === '') {
            return ['viewers' => [], 'bookers' => [], 'managers' => []];
        }

        $perms = json_decode($json, true);
        if (!is_array($perms)) {
            return ['viewers' => [], 'bookers' => [], 'managers' => []];
        }

        return [
            'viewers' => $perms['viewers'] ?? [],
            'bookers' => $perms['bookers'] ?? [],
            'managers' => $perms['managers'] ?? [],
        ];
    }

    private function savePermissions(string $key, array $permissions): void {
        $data = [
            'viewers' => $permissions['viewers'] ?? [],
            'bookers' => $permissions['bookers'] ?? [],
            'managers' => $permissions['managers'] ?? [],
        ];

        $this->appConfig->setValueString(
            Application::APP_ID,
            $key,
            json_encode($data)
        );
    }

    /**
     * Merge two permission entry arrays (union, deduplicated by type+id)
     */
    private function mergeEntries(array $entries1, array $entries2): array {
        $merged = $entries1;
        foreach ($entries2 as $entry) {
            if (!$this->containsEntry($merged, $entry)) {
                $merged[] = $entry;
            }
        }
        return $merged;
    }

    private function containsEntry(array $entries, array $target): bool {
        foreach ($entries as $entry) {
            if (($entry['type'] ?? '') === ($target['type'] ?? '')
                && ($entry['id'] ?? '') === ($target['id'] ?? '')) {
                return true;
            }
        }
        return false;
    }

    private function matchesAnyEntry(string $userId, array $entries): bool {
        foreach ($entries as $entry) {
            $type = $entry['type'] ?? '';
            $id = $entry['id'] ?? '';

            if ($type === 'user' && $id === $userId) {
                return true;
            }

            if ($type === 'group' && $this->groupManager->isInGroup($userId, $id)) {
                return true;
            }
        }

        return false;
    }

    private function resolveUserIds(array $entries): array {
        $userIds = [];

        foreach ($entries as $entry) {
            $type = $entry['type'] ?? '';
            $id = $entry['id'] ?? '';

            if ($type === 'user') {
                $userIds[] = $id;
            } elseif ($type === 'group') {
                $group = $this->groupManager->get($id);
                if ($group !== null) {
                    foreach ($group->getUsers() as $user) {
                        $userIds[] = $user->getUID();
                    }
                }
            }
        }

        return array_unique($userIds);
    }

    private function isAdmin(string $userId): bool {
        return $this->groupManager->isAdmin($userId);
    }
}
