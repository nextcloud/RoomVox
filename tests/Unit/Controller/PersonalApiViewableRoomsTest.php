<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\PersonalApiController;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PersonalApiController::viewableRooms() — the permission-filtered
 * allow-list backing the calendar "Show all rooms" dialog (issue #20).
 */
class PersonalApiViewableRoomsTest extends TestCase {
    private RoomService $roomService;
    private PermissionService $permissionService;
    private IUserSession $userSession;
    private IGroupManager $groupManager;

    private array $rooms = [
        'room1' => ['id' => 'room1', 'name' => 'Room 1', 'email' => 'room1@example.com'],
        'room2' => ['id' => 'room2', 'name' => 'Room 2', 'email' => 'room2@example.com'],
        'room3' => ['id' => 'room3', 'name' => 'Room 3', 'email' => 'room3@example.com'],
    ];

    private function makeController(?string $uid, bool $isAdmin, array $viewable): PersonalApiController {
        $this->roomService = $this->createMock(RoomService::class);
        $this->roomService->method('getAllRooms')->willReturn($this->rooms);

        $this->permissionService = $this->createMock(PermissionService::class);
        $this->permissionService->method('canView')
            ->willReturnCallback(fn(string $u, string $roomId) => in_array($roomId, $viewable, true));

        $this->userSession = $this->createMock(IUserSession::class);
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $this->userSession->method('getUser')->willReturn($user);
        } else {
            $this->userSession->method('getUser')->willReturn(null);
        }

        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->groupManager->method('isAdmin')->willReturn($isAdmin);

        return new PersonalApiController(
            'roomvox',
            $this->createMock(IRequest::class),
            $this->roomService,
            $this->permissionService,
            $this->createMock(CalDAVService::class),
            $this->userSession,
            $this->groupManager,
        );
    }

    public function testUnauthenticatedReturns401(): void {
        $controller = $this->makeController(null, false, []);
        $response = $controller->viewableRooms();
        $this->assertSame(401, $response->getStatus());
    }

    public function testAdminSeesAllRooms(): void {
        $controller = $this->makeController('admin', true, []);
        $response = $controller->viewableRooms();
        $emails = array_column($response->getData(), 'email');
        $this->assertEqualsCanonicalizing(
            ['room1@example.com', 'room2@example.com', 'room3@example.com'],
            $emails
        );
    }

    public function testViewerSeesOnlyPermittedRooms(): void {
        // User may view room1 and room3, but not room2.
        $controller = $this->makeController('bob', false, ['room1', 'room3']);
        $response = $controller->viewableRooms();
        $emails = array_column($response->getData(), 'email');
        $this->assertEqualsCanonicalizing(['room1@example.com', 'room3@example.com'], $emails);
        $this->assertNotContains('room2@example.com', $emails);
    }

    public function testUserWithNoPermissionsSeesNoRooms(): void {
        $controller = $this->makeController('nobody', false, []);
        $response = $controller->viewableRooms();
        $this->assertSame([], $response->getData());
    }

    public function testResponseOnlyExposesIdAndEmail(): void {
        $controller = $this->makeController('admin', true, []);
        $response = $controller->viewableRooms();
        foreach ($response->getData() as $entry) {
            $this->assertSame(['id', 'email'], array_keys($entry));
        }
    }
}
