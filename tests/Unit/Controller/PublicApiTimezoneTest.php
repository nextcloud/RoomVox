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
 * Booking hours are local wall-clock values, but the Public API used to read
 * them in whatever offset the client sent (issue #45). A client sending UTC
 * had its 09:00 local booking checked as 07:00 and refused, while the same
 * instant sent as +02:00 passed: no input worked both for storage and for the
 * hours check. These pin the check to the instance timezone.
 *
 * PHP runs in UTC here, as it does under Nextcloud, so the instance timezone
 * is the only thing that can make these pass.
 */
class PublicApiTimezoneTest extends TestCase {
    private CalDAVService $calDAVService;
    private RoomService $roomService;
    private IRequest $request;

    /** Mon-Fri 08:00-18:00, as written by the "Weekdays 08-18" preset. */
    private array $room = [
        'id' => 'room1',
        'userId' => 'rb_room1',
        'name' => 'Conference Room',
        'email' => 'room1@example.com',
        'autoAccept' => true,
        'active' => true,
        'availabilityRules' => [
            'enabled' => true,
            'rules' => [['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00']],
        ],
        'maxBookingHorizon' => 0,
    ];

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->roomService = $this->createMock(RoomService::class);
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->roomService->method('getRoom')->willReturn($this->room);
        $this->calDAVService->method('hasConflict')->willReturn(false);
        $this->calDAVService->method('getRoomCalendarId')->willReturn(7);
        $this->calDAVService->method('createBooking')->willReturn('uid-tz');
    }

    private function controller(string $timezone): PublicApiController {
        $tokenMiddleware = $this->createMock(ApiTokenMiddleware::class);
        $tokenMiddleware->method('getValidatedToken')->willReturn([
            'id' => 'tok1',
            'scope' => 'book',
            'roomIds' => [],
        ]);
        $tokenService = $this->createMock(ApiTokenService::class);
        $tokenService->method('hasRoomAccess')->willReturn(true);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')
            ->with('default_timezone', 'UTC')
            ->willReturn($timezone);

        return new PublicApiController(
            'roomvox',
            $this->request,
            $this->roomService,
            $this->calDAVService,
            $this->createMock(ExchangeSyncService::class),
            $this->createMock(MailService::class),
            $tokenMiddleware,
            $tokenService,
            $this->createMock(LoggerInterface::class),
            new InstanceTimezone($config),
        );
    }

    private function book(string $timezone, string $start, string $end): int {
        $this->request->method('getParam')->willReturnCallback(
            fn(string $key, $default = '') => [
                'title' => 'Standup',
                'start' => $start,
                'end' => $end,
            ][$key] ?? $default
        );

        return $this->controller($timezone)->createBooking('room1')->getStatus();
    }

    /**
     * Monday 28 September 2026, 09:00-10:00 in Vienna (CEST, +02:00), sent as
     * UTC. Read in the client's offset this was 07:00 and refused.
     */
    public function testUtcRequestInsideLocalHoursIsAccepted(): void {
        $this->assertSame(201, $this->book('Europe/Vienna', '2026-09-28T07:00:00Z', '2026-09-28T08:00:00Z'));
    }

    public function testOffsetRequestInsideLocalHoursIsAccepted(): void {
        $this->assertSame(201, $this->book('Europe/Vienna', '2026-09-28T09:00:00+02:00', '2026-09-28T10:00:00+02:00'));
    }

    /**
     * 17:00-17:30Z is 19:00-19:30 in Vienna, after closing. Read in the
     * client's offset it fell inside 08:00-18:00 and was let through.
     */
    public function testUtcRequestAfterLocalClosingIsRefused(): void {
        $this->assertSame(422, $this->book('Europe/Vienna', '2026-09-28T17:00:00Z', '2026-09-28T17:30:00Z'));
    }

    /**
     * Sunday 27 September 21:00Z is Monday 10:00 in Auckland (NZDT, +13:00).
     * Read in UTC it is a Sunday evening and refused; the weekday as well as
     * the hour has to come from the local reading.
     */
    public function testWeekdayIsTakenFromTheLocalDate(): void {
        $this->assertSame(201, $this->book('Pacific/Auckland', '2026-09-27T21:00:00Z', '2026-09-27T22:00:00Z'));
    }

    public function testInstanceWithoutTimezoneKeepsUtcBehaviour(): void {
        $this->assertSame(201, $this->book('', '2026-09-28T09:00:00Z', '2026-09-28T10:00:00Z'));
    }

    private function availability(string $timezone, array $bookings): array {
        $this->calDAVService->method('getBookings')->willReturn($bookings);
        $this->request->method('getParam')->willReturnCallback(
            fn(string $key, $default = null) => ['date' => '2026-09-28'][$key] ?? $default
        );

        return $this->controller($timezone)->roomAvailability('room1')->getData();
    }

    /**
     * The slots are local wall-clock times next to local booking hours. A
     * 09:00-10:00 Vienna booking is stored as 07:00Z; read in UTC it fell
     * before the 08:00 opening, was clipped away, and 09:00 was offered as
     * free while createBooking() would refuse a 17:00Z request in the
     * advertised free time.
     */
    public function testAvailabilitySlotsAreInInstanceTime(): void {
        $data = $this->availability('Europe/Vienna', [[
            'summary' => 'Standup',
            'dtstart' => '2026-09-28T07:00:00+00:00',
            'dtend' => '2026-09-28T08:00:00+00:00',
            'partstat' => 'ACCEPTED',
        ]]);

        $this->assertSame([
            ['start' => '08:00', 'end' => '09:00', 'status' => 'free'],
            ['start' => '09:00', 'end' => '10:00', 'status' => 'busy', 'title' => 'Standup'],
            ['start' => '10:00', 'end' => '18:00', 'status' => 'free'],
        ], $data['slots']);
    }

    /** An all-day booking is a bare date and blocks the whole local day. */
    public function testAllDayBookingBlocksTheLocalDay(): void {
        $data = $this->availability('Europe/Vienna', [[
            'summary' => 'Offsite',
            'dtstart' => '2026-09-28',
            'dtend' => '2026-09-29',
            'partstat' => 'ACCEPTED',
        ]]);

        $this->assertSame([
            ['start' => '08:00', 'end' => '18:00', 'status' => 'busy', 'title' => 'Offsite'],
        ], $data['slots']);
    }

    /**
     * A floating booking (no TZID, no Z) comes back from getBookings() with
     * the +00:00 of PHP's UTC. Its clock time is the booking; converting it
     * put a 09:00 booking at 11:00 and offered 09:00 as free.
     */
    public function testFloatingBookingKeepsItsClockTime(): void {
        $data = $this->availability('Europe/Vienna', [[
            'summary' => 'Floating',
            'dtstart' => '2026-09-28T09:00:00+00:00',
            'dtend' => '2026-09-28T10:00:00+00:00',
            'wallClock' => true,
            'partstat' => 'ACCEPTED',
        ]]);

        $this->assertSame([
            ['start' => '08:00', 'end' => '09:00', 'status' => 'free'],
            ['start' => '09:00', 'end' => '10:00', 'status' => 'busy', 'title' => 'Floating'],
            ['start' => '10:00', 'end' => '18:00', 'status' => 'free'],
        ], $data['slots']);
    }

    /**
     * getBookings() sorts by instant, which puts a floating 07:30 (read as
     * 07:30Z) after a CalDAV 08:30+02:00 (06:30Z). Walked in that order the
     * floating booking ended before the cursor, was skipped, and 08:00-08:30
     * was offered as free. The slot builder sorts its own local moments.
     */
    public function testSlotsFollowLocalOrderWhateverTheInputOrder(): void {
        $data = $this->availability('Europe/Vienna', [
            [
                'summary' => 'CalDAV',
                'dtstart' => '2026-09-28T08:30:00+02:00',
                'dtend' => '2026-09-28T09:30:00+02:00',
                'partstat' => 'ACCEPTED',
            ],
            [
                'summary' => 'Floating',
                'dtstart' => '2026-09-28T07:30:00+00:00',
                'dtend' => '2026-09-28T08:30:00+00:00',
                'wallClock' => true,
                'partstat' => 'ACCEPTED',
            ],
        ]);

        $this->assertSame([
            ['start' => '08:00', 'end' => '08:30', 'status' => 'busy', 'title' => 'Floating'],
            ['start' => '08:30', 'end' => '09:30', 'status' => 'busy', 'title' => 'CalDAV'],
            ['start' => '09:30', 'end' => '18:00', 'status' => 'free'],
        ], $data['slots']);
    }

    /**
     * A booking entirely before opening used to be clamped into an inverted
     * busy slot that moved the cursor backwards.
     */
    public function testBookingOutsideTheWindowIsIgnored(): void {
        $data = $this->availability('Europe/Vienna', [[
            'summary' => 'Early',
            'dtstart' => '2026-09-28T04:00:00+00:00',
            'dtend' => '2026-09-28T05:00:00+00:00',
            'partstat' => 'ACCEPTED',
        ]]);

        $this->assertSame([
            ['start' => '08:00', 'end' => '18:00', 'status' => 'free'],
        ], $data['slots']);
    }

    /**
     * roomStatus() derives "today" from now(); under PHP's UTC that window was
     * the UTC day, cutting off the local morning or evening. It now asks for
     * the local day.
     */
    public function testRoomStatusQueriesTheLocalDay(): void {
        $requested = [];
        $this->calDAVService->method('getBookings')->willReturnCallback(
            function (string $userId, string $from, string $to) use (&$requested): array {
                $requested = [$from, $to];
                return [];
            }
        );

        $this->controller('Europe/Vienna')->roomStatus('room1');

        $from = new \DateTimeImmutable($requested[0]);
        $vienna = new \DateTimeZone('Europe/Vienna');
        $this->assertSame('00:00', $from->format('H:i'));
        $this->assertSame(
            $vienna->getOffset(new \DateTimeImmutable('now', $vienna)),
            $from->getOffset(),
            'today starts at local midnight, not UTC midnight',
        );
    }
}
