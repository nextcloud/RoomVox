<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\PublicApiController;
use OCA\RoomVox\Middleware\ApiTokenMiddleware;
use OCA\RoomVox\Service\ApiTokenService;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PublicApiController::roomFeed() — the public, secret-authenticated
 * iCal feed (issue #16). Verifies that a valid feed secret yields the room's
 * VCALENDAR, and that any mismatch returns no booking data and is throttled.
 */
class PublicApiFeedTest extends TestCase {

    private const ROOM = [
        'id' => 'meeting-room-1',
        'userId' => 'rb_meeting-room-1',
        'name' => 'Meeting Room 1',
        'feedEnabled' => true,
        'feedSecret' => 'rvxf_secret',
    ];

    private const RAW_EVENT = [
        'calendardata' => "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:abc\r\nSUMMARY:Standup\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
    ];

    private function createController(RoomService $roomService, CalDAVService $calDAVService): PublicApiController {
        return new PublicApiController(
            'roomvox',
            $this->createMock(IRequest::class),
            $roomService,
            $calDAVService,
            $this->createMock(ExchangeSyncService::class),
            $this->createMock(MailService::class),
            $this->createMock(ApiTokenMiddleware::class),
            $this->createMock(ApiTokenService::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testValidSecretReturnsRoomCalendar(): void {
        $roomService = $this->createMock(RoomService::class);
        $roomService->method('findRoomByFeedSecret')->with('rvxf_secret')->willReturn(self::ROOM);
        $roomService->method('buildRoomLocation')->willReturn('Building A');

        $calDAVService = $this->createMock(CalDAVService::class);
        $calDAVService->expects($this->once())
            ->method('getRawCalendarObjects')
            ->with('rb_meeting-room-1', 'ACCEPTED')
            ->willReturn([self::RAW_EVENT]);

        $controller = $this->createController($roomService, $calDAVService);
        $response = $controller->roomFeed('meeting-room-1', 'rvxf_secret');

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
        $body = $response->getData();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('SUMMARY:Standup', $body);
        $this->assertStringContainsString('STATUS:CONFIRMED', $body);
        $this->assertStringContainsString('LOCATION:Building A', $body);
        $this->assertNull($response->getThrottleMetadata());
        $this->assertSame('roomvox-meeting-room-1.ics', $response->getFilename());
    }

    public function testUnknownSecretReturnsNoDataAndThrottles(): void {
        $roomService = $this->createMock(RoomService::class);
        $roomService->method('findRoomByFeedSecret')->willReturn(null);

        $calDAVService = $this->createMock(CalDAVService::class);
        $calDAVService->expects($this->never())->method('getRawCalendarObjects');

        $controller = $this->createController($roomService, $calDAVService);
        $response = $controller->roomFeed('meeting-room-1', 'rvxf_wrong');

        $this->assertSame('', $response->getData());
        $this->assertSame(['action' => 'roomvox_feed'], $response->getThrottleMetadata());
    }

    public function testSecretForDifferentRoomIsRejected(): void {
        // Secret resolves to a room, but the {id} in the URL names another room.
        $roomService = $this->createMock(RoomService::class);
        $roomService->method('findRoomByFeedSecret')->willReturn(self::ROOM);

        $calDAVService = $this->createMock(CalDAVService::class);
        $calDAVService->expects($this->never())->method('getRawCalendarObjects');

        $controller = $this->createController($roomService, $calDAVService);
        $response = $controller->roomFeed('some-other-room', 'rvxf_secret');

        $this->assertSame('', $response->getData());
        $this->assertSame(['action' => 'roomvox_feed'], $response->getThrottleMetadata());
    }
}
