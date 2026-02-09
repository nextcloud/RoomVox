<?php

declare(strict_types=1);

namespace OCA\ResaVox\Service;

use OCA\ResaVox\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

class PermissionService {
    private const PERM_PREFIX = 'permissions/';

    public function __construct(
        private IAppConfig $appConfig,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get permissions for a room
     * @return array{viewers: array, bookers: array, managers: array}
     */
    public function getPermissions(string $roomId): array {
        $json = $this->appConfig->getValueString(
            Application::APP_ID,
            self::PERM_PREFIX . $roomId,
            ''
        );

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

    /**
     * Set permissions for a room
     */
    public function setPermissions(string $roomId, array $permissions): void {
        $data = [
            'viewers' => $permissions['viewers'] ?? [],
            'bookers' => $permissions['bookers'] ?? [],
            'managers' => $permissions['managers'] ?? [],
        ];

        $this->appConfig->setValueString(
            Application::APP_ID,
            self::PERM_PREFIX . $roomId,
            json_encode($data)
        );

        $this->logger->info("Permissions updated for room: {$roomId}");
    }

    /**
     * Delete permissions for a room
     */
    public function deletePermissions(string $roomId): void {
        $this->appConfig->deleteKey(Application::APP_ID, self::PERM_PREFIX . $roomId);
    }

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
     * Get the effective role for a user on a room
     * Roles are hierarchical: manager > booker > viewer > none
     * The highest matching role is returned.
     */
    public function getEffectiveRole(string $userId, string $roomId): string {
        $permissions = $this->getPermissions($roomId);

        // Check manager first (highest role)
        if ($this->matchesAnyEntry($userId, $permissions['managers'])) {
            return 'manager';
        }

        // Check booker
        if ($this->matchesAnyEntry($userId, $permissions['bookers'])) {
            return 'booker';
        }

        // Check viewer
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
     * Get all manager user IDs for a room (resolved from groups)
     * @return string[]
     */
    public function getManagerUserIds(string $roomId): array {
        $permissions = $this->getPermissions($roomId);
        return $this->resolveUserIds($permissions['managers']);
    }

    /**
     * Check if user matches any permission entry (user or group)
     */
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

    /**
     * Resolve permission entries to user IDs
     * @return string[]
     */
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

    /**
     * Check if user is a Nextcloud admin
     */
    private function isAdmin(string $userId): bool {
        return $this->groupManager->isAdmin($userId);
    }
}
