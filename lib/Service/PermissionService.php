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

    /**
     * Bulk-load effective permissions for all rooms in a single pass.
     *
     * Avoids N+1 IAppConfig reads when filtering large room collections
     * (CalDAV PROPFIND on principals/calendar-rooms/). Uses appConfig's
     * getAllValues to fetch every permissions/* and group_permissions/*
     * key in two reads, then merges per room using the same union logic
     * as getEffectivePermissions().
     *
     * @return array<string, array{viewers: array, bookers: array, managers: array}> roomId → effective perms
     */
    public function getAllEffectivePermissions(): array {
        $roomPermsByKey = $this->appConfig->getAllValues(Application::APP_ID, self::PERM_PREFIX);
        $groupPermsByKey = $this->appConfig->getAllValues(Application::APP_ID, self::GROUP_PERM_PREFIX);

        $roomPerms = [];
        foreach ($roomPermsByKey as $key => $value) {
            $roomId = substr($key, \strlen(self::PERM_PREFIX));
            $roomPerms[$roomId] = $this->decodePermissions((string)$value);
        }

        $groupPerms = [];
        foreach ($groupPermsByKey as $key => $value) {
            $groupId = substr($key, \strlen(self::GROUP_PERM_PREFIX));
            $groupPerms[$groupId] = $this->decodePermissions((string)$value);
        }

        $result = [];
        $allRooms = $this->roomService?->getAllRooms() ?? [];
        foreach ($allRooms as $room) {
            $roomId = $room['id'];
            $rPerms = $roomPerms[$roomId] ?? ['viewers' => [], 'bookers' => [], 'managers' => []];
            $groupId = $room['groupId'] ?? '';

            if ($groupId !== '' && isset($groupPerms[$groupId])) {
                $gPerms = $groupPerms[$groupId];
                $result[$roomId] = [
                    'viewers' => $this->mergeEntries($gPerms['viewers'], $rPerms['viewers']),
                    'bookers' => $this->mergeEntries($gPerms['bookers'], $rPerms['bookers']),
                    'managers' => $this->mergeEntries($gPerms['managers'], $rPerms['managers']),
                ];
            } else {
                $result[$roomId] = $rPerms;
            }
        }

        return $result;
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

    // ── Cleanup on deleted principals ────────────────────────────

    /**
     * Drop every permission entry referring to a deleted user or group.
     *
     * Permissions store bare ids, so a deleted principal leaves an entry behind
     * that can never match again: it grants nothing, but it stays visible in the
     * permission editor and, for a group, silently stops resolving to the
     * managers who were supposed to receive approval requests.
     *
     * Both room-level and room-group-level permissions are swept.
     *
     * @param 'user'|'group' $type
     * @return int The number of entries removed
     */
    public function removeEntriesFor(string $type, string $id): int {
        $removed = 0;
        /** @var array<string, array<string, true>> prefix → ids that lost a manager */
        $lostManager = [self::PERM_PREFIX => [], self::GROUP_PERM_PREFIX => []];

        foreach ([self::PERM_PREFIX, self::GROUP_PERM_PREFIX] as $prefix) {
            foreach ($this->appConfig->getAllValues(Application::APP_ID, $prefix) as $key => $value) {
                $permissions = $this->decodePermissions((string)$value);
                $changed = false;

                foreach (['viewers', 'bookers', 'managers'] as $role) {
                    $kept = array_values(array_filter(
                        $permissions[$role],
                        static fn ($entry) => ($entry['type'] ?? '') !== $type || ($entry['id'] ?? '') !== $id
                    ));

                    $dropped = \count($permissions[$role]) - \count($kept);
                    if ($dropped > 0) {
                        if ($role === 'managers') {
                            $lostManager[$prefix][substr($key, \strlen($prefix))] = true;
                        }

                        $permissions[$role] = $kept;
                        $removed += $dropped;
                        $changed = true;
                    }
                }

                if ($changed) {
                    $this->savePermissions($key, $permissions);
                }
            }
        }

        $this->warnAboutRoomsWithoutManagers(
            $lostManager[self::PERM_PREFIX],
            $lostManager[self::GROUP_PERM_PREFIX],
            $type,
            $id,
        );

        if ($removed > 0) {
            $this->logger->info(
                'RoomVox: removed ' . $removed . ' permission entr' . ($removed === 1 ? 'y' : 'ies')
                . ' for deleted ' . $type . ' "' . $id . '"'
            );
        }

        return $removed;
    }

    /**
     * Log every affected room that is left with nobody to approve its requests.
     *
     * That is a legitimate outcome of deleting the account or group, but it must
     * not happen silently. Whether anyone is left depends on the room's
     * effective managers, its own plus its room group's, so an empty entry on
     * one level is not enough: a room whose group still has a manager is fine,
     * and emptying a group's managers matters only for rooms without their own.
     *
     * @param array<string, true> $rooms Rooms whose own entry lost a manager
     * @param array<string, true> $groups Room groups whose entry lost a manager
     */
    private function warnAboutRoomsWithoutManagers(array $rooms, array $groups, string $type, string $id): void {
        if ($rooms === [] && $groups === []) {
            return;
        }

        $effective = $this->getAllEffectivePermissions();
        foreach ($this->roomService?->getAllRooms() ?? [] as $room) {
            $affected = isset($rooms[$room['id']]) || isset($groups[$room['groupId'] ?? '']);
            if ($affected && ($effective[$room['id']]['managers'] ?? []) === []) {
                $this->logger->warning(
                    'RoomVox: room "' . $room['id'] . '" has no managers left after removing deleted '
                    . $type . ' "' . $id . '"'
                );
            }
        }
    }

    // ── Private helpers ──────────────────────────────────────────

    private function loadPermissions(string $key): array {
        $json = $this->appConfig->getValueString(Application::APP_ID, $key, '');
        return $this->decodePermissions($json);
    }

    private function decodePermissions(string $json): array {
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
                } else {
                    // A configured group that no backend resolves. Typically an
                    // external backend (LDAP) that is unreachable or no longer
                    // active: canManage() keeps working through isInGroup(),
                    // but manager notifications would silently reach nobody.
                    $this->logger->warning(
                        "Permission group '{$id}' could not be resolved by any group backend; "
                        . 'its members will not receive notifications. If this is an LDAP group, '
                        . 'check that the LDAP backend is active and reachable.',
                    );
                }
            }
        }

        return array_unique($userIds);
    }

    private function isAdmin(string $userId): bool {
        return $this->groupManager->isAdmin($userId);
    }
}
