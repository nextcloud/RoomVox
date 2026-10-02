<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Migration;

use OCA\RoomVox\Migration\MoveBookingsToRoomCalendars;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\RoomService;
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class MoveBookingsToRoomCalendarsTest extends TestCase {
    /**
     * Nextcloud first creates any missing room calendar; then every room
     * with one has its leftover bookings moved, and a room without one is
     * reported and left alone.
     */
    public function testSyncsFirstThenMovesPerRoom(): void {
        $order = [];
        $roomManager = $this->createMock(IRoomManager::class);
        $roomManager->method('update')->willReturnCallback(function () use (&$order) {
            $order[] = 'sync';
        });

        $roomService = $this->createMock(RoomService::class);
        $roomService->method('getAllRooms')->willReturn([
            ['id' => 'aula', 'userId' => 'rb_aula'],
            ['id' => 'broken', 'userId' => 'rb_broken'],
        ]);

        $calDAVService = $this->createMock(CalDAVService::class);
        $calDAVService->method('getRoomCalendarId')->willReturnCallback(fn (string $u) => $u === 'rb_aula' ? 21 : null);
        $calDAVService->expects($this->once())->method('moveLegacyBookings')->with('rb_aula')
            ->willReturnCallback(function () use (&$order) {
                $order[] = 'move rb_aula';
                return ['moved' => 2, 'skipped' => 1, 'failed' => 0];
            });

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('warning')->with($this->stringContains('broken'));
        $output->expects($this->once())->method('info')->with($this->stringContains('Moved 2 bookings'));

        (new MoveBookingsToRoomCalendars($roomService, $calDAVService, $roomManager))->run($output);

        $this->assertSame(['sync', 'move rb_aula'], $order);
    }

    /** One room failing does not stop the others from being moved. */
    public function testAFailingRoomDoesNotStopTheOthers(): void {
        $roomService = $this->createMock(RoomService::class);
        $roomService->method('getAllRooms')->willReturn([
            ['id' => 'first', 'userId' => 'rb_first'],
            ['id' => 'second', 'userId' => 'rb_second'],
        ]);
        $calDAVService = $this->createMock(CalDAVService::class);
        $calDAVService->method('getRoomCalendarId')->willReturn(21);
        $moved = [];
        $calDAVService->method('moveLegacyBookings')->willReturnCallback(function (string $user) use (&$moved) {
            if ($user === 'rb_first') {
                throw new \RuntimeException('database gone away');
            }
            $moved[] = $user;
            return ['moved' => 1, 'skipped' => 0, 'failed' => 0];
        });
        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('warning')->with($this->stringContains('first'));

        (new MoveBookingsToRoomCalendars($roomService, $calDAVService, $this->createMock(IRoomManager::class)))->run($output);

        $this->assertSame(['rb_second'], $moved);
    }
}
