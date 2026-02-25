<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\BookingApiController;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for BookingApiController — validates that the internal API
 * correctly checks for conflicts, permissions, and returns proper HTTP codes.
 */
class BookingApiConflictTest extends TestCase {
    private BookingApiController $controller;
    private CalDAVService $calDAVService;
    private RoomService $roomService;
    private PermissionService $permissionService;
    private ExchangeSyncService $exchangeSyncService;
    private IRequest $request;
    private IUserSession $userSession;
    private IGroupManager $groupManager;

    private array $testRoom = [
        'id' => 'room1',
        'userId' => 'rb_room1',
        'name' => 'Conference Room',
        'email' => 'room1@example.com',
        'autoAccept' => true,
        'active' => true,
    ];

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->roomService = $this->createMock(RoomService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->exchangeSyncService = $this->createMock(ExchangeSyncService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Default: user is authenticated
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        // Default: user is admin (can always book)
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->controller = new BookingApiController(
            'roomvox',
            $this->request,
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->exchangeSyncService,
            $this->userSession,
            $this->groupManager,
            $logger,
        );
    }

    // ── Create booking ─────────────────────────────────────────────

    public function testCreateBookingSuccess(): void {
        $this->roomService->method('getRoom')->willReturn($this->testRoom);
        $this->calDAVService->method('hasConflict')->willReturn(false);
        $this->calDAVService->method('createBooking')->willReturn('new-uid-123');

        $this->request->method('getParam')->willReturnCallback(function (string $key, $default = '') {
            return match ($key) {
                'summary' => 'Team Meeting',
                'start' => '2026-02-20T10:00:00',
                'end' => '2026-02-20T11:00:00',
                'description' => '',
                default => $default,
            };
        });

        $response = $this->controller->create('room1');

        $this->assertSame(201, $response->getStatus());
        $this->assertSame('new-uid-123', $response->getData()['uid']);
    }

    public function testCreateBookingConflict(): void {
        $this->roomService->method('getRoom')->willReturn($this->testRoom);
        $this->calDAVService->method('hasConflict')->willReturn(true);

        $this->request->method('getParam')->willReturnCallback(function (string $key, $default = '') {
            return match ($key) {
                'summary' => 'Team Meeting',
                'start' => '2026-02-20T10:00:00',
                'end' => '2026-02-20T11:00:00',
                default => $default,
            };
        });

        $response = $this->controller->create('room1');

        $this->assertSame(409, $response->getStatus());
    }

    public function testCreateBookingNoPermission(): void {
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->permissionService->method('canBook')->willReturn(false);

        $this->roomService->method('getRoom')->willReturn($this->testRoom);

        // Recreate controller with non-admin user
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn($user);

        $logger = $this->createMock(LoggerInterface::class);

        $controller = new BookingApiController(
            'roomvox',
            $this->request,
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->exchangeSyncService,
            $this->userSession,
            $this->groupManager,
            $logger,
        );

        $response = $controller->create('room1');

        $this->assertSame(403, $response->getStatus());
    }

    public function testCreateBookingRoomNotFound(): void {
        $this->roomService->method('getRoom')->willReturn(null);

        $response = $this->controller->create('nonexistent');

        $this->assertSame(404, $response->getStatus());
    }

    public function testCreateBookingMissingSummary(): void {
        $this->roomService->method('getRoom')->willReturn($this->testRoom);

        $this->request->method('getParam')->willReturnCallback(function (string $key, $default = '') {
            return match ($key) {
                'summary' => '',
                'start' => '2026-02-20T10:00:00',
                'end' => '2026-02-20T11:00:00',
                default => $default,
            };
        });

        $response = $this->controller->create('room1');

        $this->assertSame(400, $response->getStatus());
    }

    public function testCreateBookingMissingTimes(): void {
        $this->roomService->method('getRoom')->willReturn($this->testRoom);

        $this->request->method('getParam')->willReturnCallback(function (string $key, $default = '') {
            return match ($key) {
                'summary' => 'Meeting',
                'start' => '',
                'end' => '',
                default => $default,
            };
        });

        $response = $this->controller->create('room1');

        $this->assertSame(400, $response->getStatus());
    }

    // ── Update (reschedule) ────────────────────────────────────────

    public function testUpdateBookingSameRoomSuccess(): void {
        $this->roomService->method('getRoom')->willReturn($this->testRoom);
        $this->calDAVService->method('getBookingByUid')->willReturn([
            'uid' => 'booking-1',
            'summary' => 'Meeting',
            'organizer' => 'testuser',
        ]);
        $this->calDAVService->method('hasConflict')->willReturn(false);
        $this->calDAVService->method('updateBookingTimes')->willReturn(true);

        $this->request->method('getParam')->willReturnCallback(function (string $key, $default = '') {
            return match ($key) {
                'start' => '2026-02-20T11:00:00',
                'end' => '2026-02-20T12:00:00',
                'roomId' => null,
                default => $default,
            };
        });

        $response = $this->controller->update('room1', 'booking-1');

        $this->assertSame(200, $response->getStatus());
    }

    public function testUpdateBookingConflict(): void {
        $this->roomService->method('getRoom')->willReturn($this->testRoom);
        $this->calDAVService->method('getBookingByUid')->willReturn([
            'uid' => 'booking-1',
            'summary' => 'Meeting',
            'organizer' => 'testuser',
        ]);
        // Conflict with another booking
        $this->calDAVService->method('hasConflict')->willReturn(true);

        $this->request->method('getParam')->willReturnCallback(function (string $key, $default = '') {
            return match ($key) {
                'start' => '2026-02-20T11:00:00',
                'end' => '2026-02-20T12:00:00',
                'roomId' => null,
                default => $default,
            };
        });

        $response = $this->controller->update('room1', 'booking-1');

        $this->assertSame(409, $response->getStatus());
    }

    public function testUpdateBookingCrossRoomSuccess(): void {
        $newRoom = array_merge($this->testRoom, ['id' => 'room2', 'userId' => 'rb_room2']);

        $this->roomService->method('getRoom')->willReturnCallback(function (string $id) use ($newRoom) {
            return match ($id) {
                'room1' => $this->testRoom,
                'room2' => $newRoom,
                default => null,
            };
        });

        $this->calDAVService->method('getBookingByUid')->willReturn([
            'uid' => 'booking-1',
            'summary' => 'Meeting',
            'organizer' => 'testuser',
        ]);
        $this->calDAVService->method('hasConflict')->willReturn(false);
        $this->calDAVService->method('createBooking')->willReturn('new-uid');

        $this->request->method('getParam')->willReturnCallback(function (string $key, $default = '') {
            return match ($key) {
                'start' => '2026-02-20T10:00:00',
                'end' => '2026-02-20T11:00:00',
                'roomId' => 'room2',
                default => $default,
            };
        });

        $response = $this->controller->update('room1', 'booking-1');

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($response->getData()['moved']);
    }

    // ── Respond (approve/decline) ──────────────────────────────────

    public function testRespondAccept(): void {
        $this->roomService->method('getRoom')->willReturn($this->testRoom);
        $this->calDAVService->method('updateBookingPartstat')->willReturn(true);

        $this->request->method('getParam')->willReturnCallback(function (string $key, $default = '') {
            return match ($key) {
                'action' => 'accept',
                default => $default,
            };
        });

        $response = $this->controller->respond('room1', 'booking-1');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('accept', $response->getData()['action']);
    }

    public function testRespondDecline(): void {
        $this->roomService->method('getRoom')->willReturn($this->testRoom);
        $this->calDAVService->method('updateBookingPartstat')->willReturn(true);

        $this->request->method('getParam')->willReturnCallback(function (string $key, $default = '') {
            return match ($key) {
                'action' => 'decline',
                default => $default,
            };
        });

        $response = $this->controller->respond('room1', 'booking-1');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('decline', $response->getData()['action']);
    }

    // ── Delete ─────────────────────────────────────────────────────

    public function testDeleteBooking(): void {
        $this->roomService->method('getRoom')->willReturn($this->testRoom);
        $this->calDAVService->method('getBookingByUid')->willReturn([
            'uid' => 'booking-1',
            'organizer' => 'testuser',
        ]);
        $this->calDAVService->method('deleteBooking')->willReturn(true);

        $response = $this->controller->destroy('room1', 'booking-1');

        $this->assertSame(200, $response->getStatus());
    }
}
