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
 * Regression tests for issue #15A — `responsibleContact` was dropped by
 * RoomApiController::create/update because the field was missing from the
 * payload whitelist. Verifies that the value reaches RoomService unchanged.
 */
class RoomApiResponsibleContactTest extends TestCase {
    private RoomApiController $controller;
    private RoomService $roomService;
    private PermissionService $permissionService;
    private CalDAVService $calDAVService;
    private IRequest $request;
    private IGroupManager $groupManager;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->roomService = $this->createMock(RoomService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $mailService = $this->createMock(MailService::class);
        $importExportService = $this->createMock(ImportExportService::class);
        $roomManager = $this->createMock(IRoomManager::class);
        $userSession = $this->createMock(IUserSession::class);
        $userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $jobList = $this->createMock(IJobList::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $logger = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->controller = new RoomApiController(
            'roomvox',
            $this->request,
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $mailService,
            $importExportService,
            $roomManager,
            $userSession,
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
        $this->request->method('getParams')->willReturn($params);
    }

    public function testCreatePassesResponsibleContactToRoomService(): void {
        $this->setupParams([
            'name' => 'New Room',
            'responsibleContact' => 'Anne Janssen (anne@voxcloud.nl)',
        ]);

        $this->roomService->expects($this->once())
            ->method('createRoom')
            ->with($this->callback(
                fn(array $data): bool => ($data['responsibleContact'] ?? null) === 'Anne Janssen (anne@voxcloud.nl)',
            ))
            ->willReturn([
                'id' => 'room1',
                'userId' => 'rb_room1',
                'name' => 'New Room',
                'responsibleContact' => 'Anne Janssen (anne@voxcloud.nl)',
            ]);

        $this->calDAVService->method('provisionCalendar')->willReturn('rb_room1');

        $response = $this->controller->create();

        $this->assertSame(201, $response->getStatus());
    }

    public function testUpdatePassesResponsibleContactToRoomService(): void {
        $this->setupParams([
            'responsibleContact' => 'Ask building manager',
        ]);

        $this->roomService->method('getRoom')->willReturn([
            'id' => 'room1',
            'userId' => 'rb_room1',
            'name' => 'Existing Room',
        ]);

        $this->roomService->expects($this->once())
            ->method('updateRoom')
            ->with(
                'room1',
                $this->callback(
                    fn(array $data): bool => ($data['responsibleContact'] ?? null) === 'Ask building manager',
                ),
            )
            ->willReturn([
                'id' => 'room1',
                'userId' => 'rb_room1',
                'responsibleContact' => 'Ask building manager',
            ]);

        $response = $this->controller->update('room1');

        $this->assertSame(200, $response->getStatus());
    }
}
