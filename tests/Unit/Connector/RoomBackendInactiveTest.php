<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Connector;

use OCA\RoomVox\Connector\Room\RoomBackend;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud treats a room that leaves the backend's list as deleted and
 * permanently deletes its calendar with every booking in it. Inactive rooms
 * therefore stay listed; RoomVox refuses bookings for them instead.
 */
class RoomBackendInactiveTest extends TestCase {
    public function testInactiveRoomsStayKnownToNextcloud(): void {
        $roomService = $this->createMock(RoomService::class);
        // Keyed by id, as RoomService::getAllRooms() returns them.
        $rooms = [
            'aula' => ['id' => 'aula', 'name' => 'Aula', 'email' => 'aula@example.com', 'active' => true],
            'library' => ['id' => 'library', 'name' => 'Library', 'email' => 'library@example.com', 'active' => false],
        ];
        $roomService->method('getAllRooms')->willReturn($rooms);
        $roomService->method('getRoom')->willReturnCallback(fn (string $id) => $rooms[$id] ?? null);
        $roomService->method('buildRoomLocation')->willReturn('');

        $backend = new RoomBackend($roomService, $this->createMock(PermissionService::class), $this->createMock(LoggerInterface::class));

        $this->assertSame(['aula', 'library'], $backend->listAllRooms());
        $this->assertCount(2, $backend->getAllRooms());
        $this->assertNotNull($backend->getRoom('library'));
    }
}
