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
        // Get group restrictions for visibility filtering
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
     * Extract all unique group IDs from permission entries
     * Used for NC Calendar's group-based resource filtering
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
