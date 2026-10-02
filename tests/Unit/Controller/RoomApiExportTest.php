<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\RoomApiController;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\ImportExportService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
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
 * Regression tests for issue #42 — GET /api/rooms/export refused a non-admin
 * with a 200 and an empty `error.csv` instead of a 403, so a permission
 * problem looked like an instance without rooms.
 */
class RoomApiExportTest extends TestCase {
    private ImportExportService $importExportService;

    private function buildController(bool $isAdmin): RoomApiController {
        $this->importExportService = $this->createMock(ImportExportService::class);
        $userSession = $this->createMock(IUserSession::class);
        $groupManager = $this->createMock(IGroupManager::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($isAdmin ? 'admin' : 'alice');
        $userSession->method('getUser')->willReturn($user);
        $groupManager->method('isAdmin')->willReturn($isAdmin);

        return new RoomApiController(
            'roomvox',
            $this->createMock(IRequest::class),
            $this->createMock(RoomService::class),
            $this->createMock(PermissionService::class),
            $this->createMock(CalDAVService::class),
            $this->createMock(MailService::class),
            $this->importExportService,
            $this->createMock(IRoomManager::class),
            $userSession,
            $this->createMock(IUserManager::class),
            $groupManager,
            $this->createMock(IJobList::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testNonAdminGetsForbidden(): void {
        $controller = $this->buildController(false);
        $this->importExportService->expects($this->never())->method('exportCsv');

        $response = $controller->exportRooms();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(403, $response->getStatus());
        $this->assertSame(['error' => 'Admin access required'], $response->getData());
    }

    public function testAdminGetsCsvDownload(): void {
        $controller = $this->buildController(true);
        $this->importExportService->method('exportCsv')->willReturn("name,email\nBoardroom,\n");

        $response = $controller->exportRooms();

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
        $this->assertSame("name,email\nBoardroom,\n", $response->getData());
        $this->assertStringStartsWith('roomvox-rooms-', $response->getFilename());
    }
}
