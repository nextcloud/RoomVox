<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\RoomApiController;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\ImportExportService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\BackgroundJob\IJobList;
use OCP\IURLGenerator;
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RoomApiController::allBookings() — focused on the `?scope=manage`
 * query parameter introduced for issue #12 (manager Bookings overview tab).
 */
class RoomApiAllBookingsTest extends TestCase {
    private RoomApiController $controller;
    private RoomService $roomService;
    private PermissionService $permissionService;
    private CalDAVService $calDAVService;
    private IRequest $request;
    private IUserSession $userSession;
    private IGroupManager $groupManager;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->roomService = $this->createMock(RoomService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $mailService = $this->createMock(MailService::class);
        $importExportService = $this->createMock(ImportExportService::class);
        $roomManager = $this->createMock(IRoomManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $jobList = $this->createMock(IJobList::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $logger = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->roomService->method('buildRoomLocation')->willReturn('Test Location');

        $this->controller = new RoomApiController(
            'roomvox',
            $this->request,
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $mailService,
            $importExportService,
            $roomManager,
            $this->userSession,
            $userManager,
            $this->groupManager,
            $jobList,
            $urlGenerator,
            $logger,
        );
    }

    private function setupParams(array $params): void {
        $this->request->method('getParam')->willReturnCallback(
            fn(string $key, $default = null) => $params[$key] ?? $default,
        );
    }

    private function setupRooms(array $rooms): void {
        $this->roomService->method('getAllRooms')->willReturn($rooms);
    }

    public function testScopeManageRestrictsToManagedRooms(): void {
        // Manager can manage room1 but only view room2; scope=manage should
        // surface bookings for room1 only.
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->setupRooms([
            ['id' => 'room1', 'userId' => 'rb_room1', 'name' => 'Room 1'],
            ['id' => 'room2', 'userId' => 'rb_room2', 'name' => 'Room 2'],
        ]);
        $this->permissionService->method('canManage')->willReturnCallback(
            fn(string $u, string $r) => $r === 'room1',
        );
        $this->permissionService->method('canView')->willReturn(true);
        $this->setupParams(['scope' => 'manage']);

        $callsByUserId = [];
        $this->calDAVService->method('getBookings')->willReturnCallback(
            function (string $userId) use (&$callsByUserId) {
                $callsByUserId[] = $userId;
                return [];
            },
        );

        $response = $this->controller->allBookings();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['rb_room1'], $callsByUserId);
    }

    public function testScopeViewDefaultsToCanView(): void {
        // No scope param → falls back to canView, sees all viewable rooms.
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->setupRooms([
            ['id' => 'room1', 'userId' => 'rb_room1', 'name' => 'Room 1'],
            ['id' => 'room2', 'userId' => 'rb_room2', 'name' => 'Room 2'],
        ]);
        $this->permissionService->method('canView')->willReturn(true);
        $this->permissionService->method('canManage')->willReturn(false);
        $this->setupParams([]);

        $callsByUserId = [];
        $this->calDAVService->method('getBookings')->willReturnCallback(
            function (string $userId) use (&$callsByUserId) {
                $callsByUserId[] = $userId;
                return [];
            },
        );

        $this->controller->allBookings();

        $this->assertSame(['rb_room1', 'rb_room2'], $callsByUserId);
    }

    public function testAdminSeesAllRoomsRegardlessOfScope(): void {
        // Admin bypasses both canView and canManage filters.
        $this->groupManager->method('isAdmin')->willReturn(true);
        $this->setupRooms([
            ['id' => 'room1', 'userId' => 'rb_room1', 'name' => 'Room 1'],
            ['id' => 'room2', 'userId' => 'rb_room2', 'name' => 'Room 2'],
        ]);
        $this->permissionService->expects($this->never())->method('canManage');
        $this->permissionService->expects($this->never())->method('canView');
        $this->setupParams(['scope' => 'manage']);

        $callsByUserId = [];
        $this->calDAVService->method('getBookings')->willReturnCallback(
            function (string $userId) use (&$callsByUserId) {
                $callsByUserId[] = $userId;
                return [];
            },
        );

        $this->controller->allBookings();

        $this->assertSame(['rb_room1', 'rb_room2'], $callsByUserId);
    }
}
