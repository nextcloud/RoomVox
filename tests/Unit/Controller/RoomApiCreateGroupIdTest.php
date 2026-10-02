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
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for issue #41 — RoomApiController::create() built its
 * payload from a whitelist that lacked `groupId`, so a room created with a
 * room group was silently stored without one.
 */
class RoomApiCreateGroupIdTest extends TestCase {
    private RoomApiController $controller;
    private RoomService $roomService;
    private IRequest $request;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->roomService = $this->createMock(RoomService::class);
        $userSession = $this->createMock(IUserSession::class);
        $groupManager = $this->createMock(IGroupManager::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $userSession->method('getUser')->willReturn($user);
        $groupManager->method('isAdmin')->willReturn(true);

        $this->controller = new RoomApiController(
            'roomvox',
            $this->request,
            $this->roomService,
            $this->createMock(PermissionService::class),
            $this->createMock(CalDAVService::class),
            $this->createMock(MailService::class),
            $this->createMock(ImportExportService::class),
            $this->createMock(IRoomManager::class),
            $userSession,
            $this->createMock(IUserManager::class),
            $groupManager,
            $this->createMock(IJobList::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function setupParams(array $params): void {
        $this->request->method('getParam')->willReturnCallback(
            fn(string $key, $default = null) => $params[$key] ?? $default,
        );
        $this->request->method('getParams')->willReturn($params);
    }

    /**
     * Runs create() and returns the payload it handed to the room service.
     */
    private function createAndCapturePayload(): array {
        $captured = null;
        $this->roomService->expects($this->once())
            ->method('createRoomWithCalendar')
            ->willReturnCallback(function (array $data) use (&$captured): array {
                $captured = $data;
                return ['id' => 'boardroom', 'userId' => 'rb_boardroom', 'name' => $data['name']];
            });

        $response = $this->controller->create();

        $this->assertSame(201, $response->getStatus());
        return $captured;
    }

    public function testCreatePassesGroupIdToRoomService(): void {
        $this->setupParams(['name' => 'Boardroom', 'groupId' => 'building-a']);

        $data = $this->createAndCapturePayload();

        $this->assertSame('building-a', $data['groupId'] ?? null);
    }

    public function testCreateWithoutGroupIdLeavesRoomUngrouped(): void {
        $this->setupParams(['name' => 'Boardroom']);

        $data = $this->createAndCapturePayload();

        $this->assertNull($data['groupId'] ?? null);
    }
}
