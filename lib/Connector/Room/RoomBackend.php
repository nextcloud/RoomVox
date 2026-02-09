<?php

declare(strict_types=1);

namespace OCA\ResaVox\Connector\Room;

use OCA\ResaVox\AppInfo\Application;
use OCA\ResaVox\Service\PermissionService;
use OCA\ResaVox\Service\RoomService;
use OCP\Calendar\Room\IBackend;
use OCP\Calendar\Room\IRoom;
use Psr\Log\LoggerInterface;

class RoomBackend implements IBackend {
    public function __construct(
        private RoomService $roomService,
        private PermissionService $permissionService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getBackendIdentifier(): string {
        return Application::APP_ID;
    }

    /**
     * @inheritDoc
     * @return IRoom[]
     */
    public function getAllRooms(): array {
        $rooms = $this->roomService->getAllRooms();
        $result = [];

        foreach ($rooms as $room) {
            if (!($room['active'] ?? true)) {
                continue;
            }

            $result[] = $this->createRoomObject($room);
        }

        return $result;
    }

    /**
     * @inheritDoc
     * @return string[]
     */
    public function listAllRooms(): array {
        $rooms = $this->roomService->getAllRooms();
        $result = [];

        foreach ($rooms as $room) {
            if (!($room['active'] ?? true)) {
                continue;
            }

            $result[] = $room['id'];
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getRoom($id): ?IRoom {
        $room = $this->roomService->getRoom($id);
        if ($room === null || !($room['active'] ?? true)) {
            return null;
        }

        return $this->createRoomObject($room);
    }

    /**
     * Create a Room object from room config data
     */
    private function createRoomObject(array $roomData): Room {
        $permissions = $this->permissionService->getPermissions($roomData['id']);
        $groupRestrictions = $this->extractGroupIds($permissions);

        return new Room(
            backend: $this,
            id: $roomData['id'],
            displayName: $roomData['name'],
            email: $roomData['email'] ?? '',
            capacity: $roomData['capacity'] ?? null,
            location: $roomData['location'] ?? null,
            description: $roomData['description'] ?? null,
            facilities: $roomData['facilities'] ?? [],
            groupRestrictions: $groupRestrictions,
        );
    }

    /**
     * Extract explicitly configured group IDs from permissions.
     *
     * NC Calendar uses group_restrictions to filter room visibility:
     * - Empty array → room visible to everyone
     * - Non-empty → only members of listed groups see the room
     *
     * Only explicit group entries are used here. User-based permissions
     * are enforced by the SchedulingPlugin at booking time, not at
     * visibility level. To restrict room visibility, admins should
     * assign groups (not individual users) in the permission editor.
     *
     * @return string[]
     */
    private function extractGroupIds(array $permissions): array {
        $groups = [];

        foreach (['viewers', 'bookers', 'managers'] as $role) {
            foreach ($permissions[$role] ?? [] as $entry) {
                if (($entry['type'] ?? '') === 'group') {
                    $groups[] = $entry['id'];
                }
            }
        }

        return array_unique($groups);
    }
}
