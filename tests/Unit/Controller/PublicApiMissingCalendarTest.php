<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\PublicApiController;
use OCA\RoomVox\Middleware\ApiTokenMiddleware;
use OCA\RoomVox\Service\ApiTokenService;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\InstanceTimezone;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\RoomService;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Issue #44 — a room can exist without a CalDAV calendar, and the public API
 * then reports it as free whatever is booked.
 *
 * CalDAVService::getBookings() returns [] both for "calendar exists and is
 * empty" and for "there is no calendar at all". roomStatus() initialises
 * $status = 'free' and only ever overwrites it, so the second case is served
 * as a confident 'free' with HTTP 200 and nothing in the payload that lets a
 * room display tell the difference.
 *
 * Unlike PublicApiConflictTest, these tests drive the real controller method:
 * both guards (requireScope via ApiTokenMiddleware::getValidatedToken and
 * getAuthorizedRoom via ApiTokenService::hasRoomAccess) are mockable.
 *
 * Decision: such a room is reported as 'unavailable' with reason
 * 'no_calendar'. That stays inside the documented free/busy/unavailable
 * contract, so room displays keep working, and errs on the safe side: the
 * room shows as not bookable rather than as free.
 */
class PublicApiMissingCalendarTest extends TestCase {
    private IRequest $request;
    private RoomService $roomService;
    private CalDAVService $calDAVService;
    private ApiTokenMiddleware $tokenMiddleware;
    private ApiTokenService $tokenService;

    private array $testRoom = [
        'id' => 'office-103',
        'userId' => 'rb_office-103',
        'name' => 'Office 103',
        'email' => 'office-103@roomvox.local',
        'autoAccept' => true,
        'active' => true,
        'availabilityRules' => ['enabled' => false, 'rules' => []],
        'maxBookingHorizon' => 0,
    ];

    private function createController(): PublicApiController {
        $this->request = $this->createMock(IRequest::class);
        $this->roomService = $this->createMock(RoomService::class);
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->tokenMiddleware = $this->createMock(ApiTokenMiddleware::class);
        $this->tokenService = $this->createMock(ApiTokenService::class);

        $this->tokenMiddleware->method('getValidatedToken')
            ->willReturn(['id' => 'token1', 'scope' => 'book', 'roomIds' => []]);
        $this->tokenService->method('hasRoomAccess')->willReturn(true);
        $this->roomService->method('getRoom')->willReturn($this->testRoom);
        $this->roomService->method('buildRoomLocation')->willReturn('');

        return new PublicApiController(
            'roomvox',
            $this->request,
            $this->roomService,
            $this->calDAVService,
            $this->createMock(ExchangeSyncService::class),
            $this->createMock(MailService::class),
            $this->tokenMiddleware,
            $this->tokenService,
            $this->createMock(LoggerInterface::class),
            new InstanceTimezone($this->createMock(IConfig::class)),
        );
    }

    /**
     * A room whose calendar is missing must not be reported as 'free'.
     *
     * getBookings() returning [] is the only signal the controller gets, and
     * today that is indistinguishable from an empty calendar. The status has
     * to say "not known" rather than assert availability, otherwise a kiosk
     * shows a bookable room that cannot accept a booking at all.
     */
    public function testMissingCalendarIsNotReportedAsFree(): void {
        $controller = $this->createController();

        // No calendar: every read path returns empty.
        $this->calDAVService->method('getBookings')->willReturn([]);
        $this->calDAVService->method('getRoomCalendarId')->willReturn(null);

        $response = $controller->roomStatus('office-103');
        $data = $response->getData();

        $this->assertNotSame(
            'free',
            $data['status'],
            'A room without a calendar must not be advertised as free — nothing can be booked in it.',
        );
    }

    /**
     * The payload carries something a room display can branch on: the room is
     * 'unavailable', within the existing contract, and the additive 'reason'
     * field says why.
     */
    public function testMissingCalendarIsDetectableByAConsumer(): void {
        $controller = $this->createController();

        $this->calDAVService->method('getRoomCalendarId')->willReturn(null);
        $this->calDAVService->expects($this->never())->method('getBookings');

        $data = $controller->roomStatus('office-103')->getData();

        $this->assertSame('unavailable', $data['status']);
        $this->assertSame('no_calendar', $data['reason']);
        $this->assertSame([], $data['todayBookings']);
    }

    /**
     * Availability must not turn an empty booking list into one free slot
     * spanning the whole day.
     */
    public function testMissingCalendarOffersNoFreeSlots(): void {
        $controller = $this->createController();
        $this->request->method('getParam')->willReturnCallback(
            fn(string $key, $default = null) => ['date' => '2026-02-20'][$key] ?? $default
        );

        $this->calDAVService->method('getRoomCalendarId')->willReturn(null);

        $data = $controller->roomAvailability('office-103')->getData();

        $this->assertSame([], $data['slots']);
        $this->assertSame('no_calendar', $data['reason']);
    }

    /**
     * A booking request is refused with a reason that matches the cause, not
     * with the 409 "already booked" the fail-closed conflict check would give.
     */
    public function testBookingARoomWithoutCalendarIsRefusedClearly(): void {
        $controller = $this->createController();
        $this->request->method('getParam')->willReturnCallback(
            fn(string $key, $default = '') => [
                'title' => 'Standup',
                'start' => '2026-02-20T10:00:00Z',
                'end' => '2026-02-20T11:00:00Z',
            ][$key] ?? $default
        );

        $this->calDAVService->method('getRoomCalendarId')->willReturn(null);
        $this->calDAVService->expects($this->never())->method('createBooking');

        $response = $controller->createBooking('office-103');

        $this->assertSame(422, $response->getStatus());
        $this->assertStringContainsString('no calendar', $response->getData()['error']);
    }

    /**
     * Inactive rooms stay known to Nextcloud so their calendars survive, so
     * the API itself has to say they cannot be booked.
     */
    public function testInactiveRoomIsUnavailableAndRefusesBookings(): void {
        $this->testRoom['active'] = false;
        $controller = $this->createController();
        $this->calDAVService->method('getRoomCalendarId')->willReturn(7);
        $this->calDAVService->expects($this->never())->method('createBooking');
        $this->request->method('getParam')->willReturnCallback(
            fn(string $key, $default = '') => [
                'title' => 'Standup',
                'start' => '2026-02-20T10:00:00Z',
                'end' => '2026-02-20T11:00:00Z',
            ][$key] ?? $default
        );

        $status = $controller->roomStatus('office-103')->getData();
        $this->assertSame(['unavailable', 'inactive'], [$status['status'], $status['reason']]);

        $response = $controller->createBooking('office-103');
        $this->assertSame(422, $response->getStatus());
        $this->assertStringContainsString('not active', $response->getData()['error']);
    }

    /** A healthy room reports why it is unavailable outside its hours too. */
    public function testReasonIsNullWhileTheRoomIsFree(): void {
        $controller = $this->createController();

        $this->calDAVService->method('getBookings')->willReturn([]);
        $this->calDAVService->method('getRoomCalendarId')->willReturn(7);

        $data = $controller->roomStatus('office-103')->getData();

        $this->assertSame('free', $data['status']);
        $this->assertNull($data['reason']);
    }

    /**
     * Control case: a calendar that exists and is genuinely empty must still
     * report 'free'. This pins the fix so it cannot be implemented by simply
     * treating every empty booking list as broken.
     */
    public function testEmptyButPresentCalendarStillReportsFree(): void {
        $controller = $this->createController();

        $this->calDAVService->method('getBookings')->willReturn([]);
        $this->calDAVService->method('getRoomCalendarId')->willReturn(7);

        $response = $controller->roomStatus('office-103');
        $data = $response->getData();

        $this->assertSame('free', $data['status']);
    }
}
