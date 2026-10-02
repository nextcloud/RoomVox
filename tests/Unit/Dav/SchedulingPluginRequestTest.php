<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Dav;

use OCA\RoomVox\Dav\SchedulingPlugin;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ITip;
use Sabre\VObject\Property;

/**
 * Tests for SchedulingPlugin::handleScheduleRequest() — the full iTIP
 * booking flow from CalDAV clients (Apple Calendar, Thunderbird, etc.).
 *
 * Tests the complete chain: permission → availability → horizon → conflict → PARTSTAT → delivery.
 */
class SchedulingPluginRequestTest extends TestCase {
    private SchedulingPlugin $plugin;
    private RoomService $roomService;
    private PermissionService $permissionService;
    private CalDAVService $calDAVService;
    private MailService $mailService;
    private ExchangeSyncService $exchangeSyncService;
    private IUserManager $userManager;

    private array $testRoom = [
        'id' => 'room1',
        'userId' => 'rb_room1',
        'name' => 'Conference Room',
        'email' => 'room1@example.com',
        'autoAccept' => true,
        'active' => true,
        'availabilityRules' => ['enabled' => false, 'rules' => []],
        'maxBookingHorizon' => 0,
    ];

    protected function setUp(): void {
        $this->roomService = $this->createMock(RoomService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->calDAVService->method('getRoomCalendarId')->willReturn(1);
        $this->mailService = $this->createMock(MailService::class);
        $this->exchangeSyncService = $this->createMock(ExchangeSyncService::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Default: room is recognized and active
        $this->roomService->method('isRoomPrincipal')->willReturn(true);
        $this->roomService->method('getRoomIdByPrincipal')->willReturn('room1');
        $this->roomService->method('getRoom')->willReturn($this->testRoom);

        // Default: no permissions configured (anyone can book)
        $this->permissionService->method('getEffectivePermissions')->willReturn([
            'viewers' => [], 'bookers' => [], 'managers' => [],
        ]);

        // Default: no conflicts
        $this->calDAVService->method('hasConflict')->willReturn(false);
        $this->calDAVService->method('deliverToRoomCalendar')->willReturn(true);

        $this->plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );
    }

    /**
     * Build an iTIP REQUEST message with a VEVENT for testing.
     */
    private function buildRequestMessage(
        string $sender = 'principals/users/testuser',
        string $uid = 'test-booking-uid',
        ?\DateTimeInterface $start = null,
        ?\DateTimeInterface $end = null,
    ): ITip\Message {
        $start ??= new \DateTimeImmutable('2026-02-20 10:00:00');
        $end ??= new \DateTimeImmutable('2026-02-20 11:00:00');

        $vEvent = new VEvent();
        $vEvent->DTSTART = new Property($start);
        $vEvent->DTEND = new Property($end);
        $vEvent->UID = new Property($uid);
        $vEvent->SUMMARY = new Property('Test Booking');

        $vCalendar = new VCalendar();
        $vCalendar->VEVENT = $vEvent;

        $message = new ITip\Message();
        $message->method = 'REQUEST';
        $message->sender = $sender;
        $message->recipient = 'principals/users/rb_room1';
        $message->message = $vCalendar;

        return $message;
    }

    // ── Successful bookings ────────────────────────────────────────

    public function testRequestAccepted(): void {
        $message = $this->buildRequestMessage();

        $result = $this->plugin->handleScheduleRequest($message);

        $this->assertFalse($result); // false = stop Sabre propagation
        $this->assertSame('1.2', $message->scheduleStatus);
    }

    public function testRequestTentative(): void {
        // Room requires manual approval
        $room = array_merge($this->testRoom, ['autoAccept' => false]);
        $this->roomService = $this->createMock(RoomService::class);
        $this->roomService->method('isRoomPrincipal')->willReturn(true);
        $this->roomService->method('getRoomIdByPrincipal')->willReturn('room1');
        $this->roomService->method('getRoom')->willReturn($room);

        $this->permissionService = $this->createMock(PermissionService::class);
        $this->permissionService->method('getEffectivePermissions')->willReturn([
            'viewers' => [], 'bookers' => [], 'managers' => [],
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage();
        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('1.2', $message->scheduleStatus);
    }

    // ── Conflict rejection ─────────────────────────────────────────

    public function testRequestConflict(): void {
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->calDAVService->method('getRoomCalendarId')->willReturn(1);
        $this->calDAVService->method('findConflicts')->willReturnCallback(fn (string $u, array $occurrences) => $occurrences);

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage();
        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('3.0', $message->scheduleStatus);
    }

    /**
     * A room without a calendar declines the invitation without the conflict
     * mail, which would tell the organizer the room is already booked. Since
     * hasConflict() fails closed for such a room, that is what happened
     * otherwise (issue #44).
     */
    public function testRequestForRoomWithoutCalendarDeclinesWithoutConflictMail(): void {
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->calDAVService->method('getRoomCalendarId')->willReturn(null);
        $this->calDAVService->method('hasConflict')->willReturn(true);
        $this->calDAVService->expects($this->never())->method('deliverToRoomCalendar');
        $this->mailService->expects($this->never())->method('sendConflict');

        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $this->createMock(LoggerInterface::class),
        );

        $message = $this->buildRequestMessage();
        $plugin->handleScheduleRequest($message);

        $this->assertSame('5.3', $message->scheduleStatus);
    }

    /**
     * An inactive room used to be passed on to Sabre's own delivery. Now that
     * inactive rooms stay known to Nextcloud, RoomVox declines them itself.
     */
    public function testRequestForInactiveRoomIsDeclined(): void {
        $calDAVService = $this->createMock(CalDAVService::class);
        $calDAVService->method('getRoomCalendarId')->willReturn(1);
        $calDAVService->expects($this->never())->method('deliverToRoomCalendar');

        $message = $this->buildRequestMessage();
        $result = $this->pluginWith($calDAVService, array_merge($this->testRoom, ['active' => false]))->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('3.7', $message->scheduleStatus);
    }

    // ── Recurring bookings (issue #46) ─────────────────────────────
    //
    // Dates are relative to today: the plugin only checks occurrences that
    // still lie ahead, so fixed dates would turn these tests stale.

    /** Monday of next week, 10:00, plus $weeks weeks */
    private static function monday(int $weeks = 0): \DateTimeImmutable {
        return (new \DateTimeImmutable('monday next week 10:00'))->modify(($weeks >= 0 ? '+' : '') . $weeks . ' weeks');
    }

    /**
     * A weekly series on the given occurrences, 1 hour each. The EventIterator
     * stub reads them from __testOccurrences, as Sabre would expand the RRULE.
     *
     * @param list<\DateTimeImmutable> $starts
     */
    private function buildSeriesMessage(array $starts, string $rrule = 'FREQ=WEEKLY;COUNT=10'): ITip\Message {
        $message = $this->buildRequestMessage('principals/users/testuser', 'series-uid', $starts[0], $starts[0]->modify('+1 hour'));
        $message->message->VEVENT->RRULE = new Property($rrule);
        $message->message->__set('__testOccurrences', array_map(fn (\DateTimeImmutable $d) => [
            'start' => $d,
            'end' => $d->modify('+1 hour'),
        ], $starts));

        return $message;
    }

    private function pluginWith(CalDAVService $calDAVService, ?array $room = null): SchedulingPlugin {
        if ($room !== null) {
            $this->roomService = $this->createMock(RoomService::class);
            $this->roomService->method('isRoomPrincipal')->willReturn(true);
            $this->roomService->method('getRoomIdByPrincipal')->willReturn('room1');
            $this->roomService->method('getRoom')->willReturn($room);
        }

        return new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * A CalDAVService whose findConflicts() records the dates it is asked
     * about and reports those on $conflictDates as taken.
     *
     * @param list<string> $conflictDates Y-m-d
     * @param list<string> $checked filled with the Y-m-d dates checked
     */
    private function recordingCalDav(array $conflictDates, array &$checked): CalDAVService {
        $calDAVService = $this->createMock(CalDAVService::class);
        $calDAVService->method('getRoomCalendarId')->willReturn(1);
        $calDAVService->method('deliverToRoomCalendar')->willReturn(true);
        $calDAVService->method('findConflicts')->willReturnCallback(
            function (string $user, array $occurrences) use ($conflictDates, &$checked) {
                foreach ($occurrences as $o) {
                    $checked[] = $o[0]->format('Y-m-d');
                }
                return array_values(array_filter($occurrences, fn ($o) => in_array($o[0]->format('Y-m-d'), $conflictDates, true)));
            }
        );
        return $calDAVService;
    }

    /**
     * The reported case: the third date of the series collides with an
     * existing booking. Only the first date used to be checked, so the series
     * was accepted and the room double-booked on that day.
     */
    public function testSeriesWithAConflictOnALaterDateIsDeclined(): void {
        $checked = [];
        $third = self::monday(2)->format('Y-m-d');
        $calDAVService = $this->recordingCalDav([$third], $checked);

        $this->mailService->expects($this->once())
            ->method('sendConflict')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $dates) => array_map(fn ($d) => $d->format('Y-m-d'), $dates) === [$third]),
            );

        $message = $this->buildSeriesMessage([self::monday(0), self::monday(1), self::monday(2), self::monday(3)]);
        $this->pluginWith($calDAVService)->handleScheduleRequest($message);

        $this->assertSame('3.0', $message->scheduleStatus);
        $this->assertCount(4, $checked);
    }

    public function testSeriesWithoutConflictsIsAccepted(): void {
        $checked = [];
        $message = $this->buildSeriesMessage([self::monday(0), self::monday(1), self::monday(2)]);
        $this->pluginWith($this->recordingCalDav([], $checked))->handleScheduleRequest($message);

        $this->assertSame('1.2', $message->scheduleStatus);
        $this->assertCount(3, $checked);
    }

    /**
     * Booking hours are checked per occurrence too: one date of the series
     * falls on a Saturday, outside Mon-Fri 08:00-18:00.
     */
    public function testSeriesWithADateOutsideTheBookingHoursIsDeclined(): void {
        $room = array_merge($this->testRoom, ['availabilityRules' => [
            'enabled' => true,
            'rules' => [['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00']],
        ]]);
        $checked = [];
        $saturday = self::monday(1)->modify('+5 days');

        $this->mailService->expects($this->once())
            ->method('sendAvailabilityViolation')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $dates) => array_map(fn ($d) => $d->format('Y-m-d'), $dates) === [$saturday->format('Y-m-d')]),
            );

        $message = $this->buildSeriesMessage([self::monday(0), self::monday(1), $saturday]);
        $this->pluginWith($this->recordingCalDav([], $checked), $room)->handleScheduleRequest($message);

        $this->assertSame('3.7', $message->scheduleStatus);
    }

    /**
     * A series without an end is checked up to a year ahead when the room has
     * no booking horizon; later occurrences are not looked at.
     */
    public function testOpenEndedSeriesIsCheckedUpToAYear(): void {
        $checked = [];
        $message = $this->buildSeriesMessage([self::monday(0), self::monday(50), self::monday(60)], 'FREQ=WEEKLY');
        $this->pluginWith($this->recordingCalDav([], $checked))->handleScheduleRequest($message);

        $this->assertSame([self::monday(0)->format('Y-m-d'), self::monday(50)->format('Y-m-d')], $checked);
    }

    /**
     * A series with an end is checked to its end, also beyond a year: a
     * clash in month 18 of a two-year series used to go unnoticed.
     */
    public function testFiniteSeriesIsCheckedToItsEndBeyondAYear(): void {
        $checked = [];
        $late = self::monday(80)->format('Y-m-d');
        $message = $this->buildSeriesMessage([self::monday(0), self::monday(40), self::monday(80)], 'FREQ=WEEKLY;COUNT=104');
        $this->pluginWith($this->recordingCalDav([$late], $checked))->handleScheduleRequest($message);

        $this->assertSame('3.0', $message->scheduleStatus);
        $this->assertContains($late, $checked);
    }

    /**
     * Editing a series that began in the past checks its dates from now on:
     * past dates neither use up the limits nor get the edit refused.
     */
    public function testPastOccurrencesAreNotChecked(): void {
        $checked = [];
        $message = $this->buildSeriesMessage([self::monday(-60), self::monday(-59), self::monday(0)], 'FREQ=WEEKLY');
        $this->pluginWith($this->recordingCalDav([self::monday(-60)->format('Y-m-d')], $checked))->handleScheduleRequest($message);

        $this->assertSame('1.2', $message->scheduleStatus);
        $this->assertSame([self::monday(0)->format('Y-m-d')], $checked);
    }

    /** Editing a series that lies wholly in the past checks nothing. */
    public function testSeriesWhollyInThePastIsNotChecked(): void {
        $checked = [];
        $past = [self::monday(-60), self::monday(-59), self::monday(-58)];
        $calDAVService = $this->recordingCalDav(array_map(fn ($d) => $d->format('Y-m-d'), $past), $checked);

        $message = $this->buildSeriesMessage($past);
        $this->pluginWith($calDAVService)->handleScheduleRequest($message);

        $this->assertSame('1.2', $message->scheduleStatus);
        $this->assertSame([], $checked);
    }

    /**
     * A long-running series down to one date ahead still names that date in
     * the mail; the mail's own date is the series' first, long past.
     */
    public function testSeriesWithOneDateLeftNamesItInTheMail(): void {
        $checked = [];
        $next = self::monday(0)->format('Y-m-d');
        $calDAVService = $this->recordingCalDav([$next], $checked);

        $this->mailService->expects($this->once())
            ->method('sendConflict')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $dates) => array_map(fn ($d) => $d->format('Y-m-d'), $dates) === [$next]),
            );

        $message = $this->buildSeriesMessage([self::monday(-60), self::monday(-59), self::monday(0)]);
        $this->pluginWith($calDAVService)->handleScheduleRequest($message);

        $this->assertSame('3.0', $message->scheduleStatus);
    }

    /**
     * An hourly series that began years ago has tens of thousands of past
     * occurrences. They must not use up any limit before the dates ahead are
     * reached, or the booking would pass without a single check.
     */
    public function testManyPastOccurrencesDoNotHideTheDatesAhead(): void {
        $checked = [];
        $next = self::monday(0)->format('Y-m-d');
        $starts = [];
        $first = self::monday(-150);
        for ($hour = 0; $hour < 25000; $hour++) {
            $starts[] = $first->modify('+' . $hour . ' hours');
        }
        $starts[] = self::monday(0);

        $message = $this->buildSeriesMessage($starts, 'FREQ=HOURLY;COUNT=25001');
        $this->pluginWith($this->recordingCalDav([$next], $checked))->handleScheduleRequest($message);

        $this->assertSame('3.0', $message->scheduleStatus);
        $this->assertContains($next, $checked);
    }

    /** A single booking keeps the old mail: its date is in the event block. */
    public function testSingleBookingConflictListsNoDates(): void {
        $checked = [];
        $calDAVService = $this->recordingCalDav([self::monday(0)->format('Y-m-d')], $checked);

        $this->mailService->expects($this->once())
            ->method('sendConflict')
            ->with($this->anything(), $this->anything(), []);

        $this->pluginWith($calDAVService)->handleScheduleRequest(
            $this->buildRequestMessage('principals/users/testuser', 'single', self::monday(0), self::monday(0)->modify('+1 hour'))
        );
    }

    // ── Permission rejections ──────────────────────────────────────

    public function testRequestNoPermission(): void {
        // Permissions configured: bookers list exists
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->permissionService->method('getEffectivePermissions')->willReturn([
            'viewers' => [],
            'bookers' => [['type' => 'user', 'id' => 'otheruser']],
            'managers' => [],
        ]);
        $this->permissionService->method('canBook')->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage('principals/users/testuser');
        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('3.7', $message->scheduleStatus);
    }

    public function testRequestUnknownSender(): void {
        // Permissions are configured
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->permissionService->method('getEffectivePermissions')->willReturn([
            'viewers' => [],
            'bookers' => [['type' => 'user', 'id' => 'someuser']],
            'managers' => [],
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        // Send from mailto: that doesn't resolve to a user
        $this->userManager->method('getByEmail')->willReturn([]);

        $message = $this->buildRequestMessage('mailto:unknown@example.com');
        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('3.7', $message->scheduleStatus);
    }

    public function testRequestNoPermissionsConfigured(): void {
        // No permissions = anyone can book
        $this->permissionService->method('getEffectivePermissions')->willReturn([
            'viewers' => [], 'bookers' => [], 'managers' => [],
        ]);

        $message = $this->buildRequestMessage();
        $result = $this->plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('1.2', $message->scheduleStatus);
    }

    // ── Availability rejection ─────────────────────────────────────

    public function testRequestOutsideAvailability(): void {
        $room = array_merge($this->testRoom, [
            'availabilityRules' => [
                'enabled' => true,
                'rules' => [
                    ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'],
                ],
            ],
        ]);
        $this->roomService = $this->createMock(RoomService::class);
        $this->roomService->method('isRoomPrincipal')->willReturn(true);
        $this->roomService->method('getRoomIdByPrincipal')->willReturn('room1');
        $this->roomService->method('getRoom')->willReturn($room);

        $this->mailService = $this->createMock(MailService::class);
        $this->mailService->expects($this->once())
            ->method('sendAvailabilityViolation');

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        // Saturday booking — outside Mon-Fri availability
        $message = $this->buildRequestMessage(
            'principals/users/testuser',
            'weekend-booking',
            new \DateTimeImmutable('2026-02-21 10:00:00'), // Saturday
            new \DateTimeImmutable('2026-02-21 11:00:00'),
        );

        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('3.7', $message->scheduleStatus);
    }

    // ── Horizon rejection ──────────────────────────────────────────

    public function testRequestBeyondHorizon(): void {
        $room = array_merge($this->testRoom, ['maxBookingHorizon' => 7]);
        $this->roomService = $this->createMock(RoomService::class);
        $this->mailService = $this->createMock(MailService::class);
        $this->mailService->expects($this->once())
            ->method('sendHorizonExceeded');
        $this->roomService->method('isRoomPrincipal')->willReturn(true);
        $this->roomService->method('getRoomIdByPrincipal')->willReturn('room1');
        $this->roomService->method('getRoom')->willReturn($room);

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        // Booking 60 days from now — exceeds 7-day horizon
        $futureStart = new \DateTimeImmutable('+60 days 10:00:00');
        $futureEnd = new \DateTimeImmutable('+60 days 11:00:00');

        $message = $this->buildRequestMessage(
            'principals/users/testuser',
            'future-booking',
            $futureStart,
            $futureEnd,
        );

        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('3.7', $message->scheduleStatus);
    }

    // ── Delivery failure ───────────────────────────────────────────

    public function testRequestDeliveryFailure(): void {
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->calDAVService->method('getRoomCalendarId')->willReturn(1);
        $this->calDAVService->method('hasConflict')->willReturn(false);
        $this->calDAVService->method('deliverToRoomCalendar')->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage();
        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('5.0', $message->scheduleStatus);
    }

    // ── Cancel ─────────────────────────────────────────────────────

    public function testCancelRemovesFromCalendar(): void {
        $this->calDAVService->expects($this->once())
            ->method('deleteFromRoomCalendar');

        $message = $this->buildRequestMessage();
        $message->method = 'CANCEL';

        $this->plugin->handleScheduleRequest($message);
    }

    // ── Email notifications ────────────────────────────────────────

    public function testRequestSendsAcceptEmail(): void {
        $this->mailService->expects($this->once())
            ->method('sendAccepted');

        $message = $this->buildRequestMessage();
        $this->plugin->handleScheduleRequest($message);
    }

    public function testRequestSendsManagerNotification(): void {
        $room = array_merge($this->testRoom, ['autoAccept' => false]);
        $this->roomService = $this->createMock(RoomService::class);
        $this->roomService->method('isRoomPrincipal')->willReturn(true);
        $this->roomService->method('getRoomIdByPrincipal')->willReturn('room1');
        $this->roomService->method('getRoom')->willReturn($room);

        $this->mailService = $this->createMock(MailService::class);
        $this->mailService->expects($this->once())
            ->method('notifyManagers');

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage();
        $plugin->handleScheduleRequest($message);
    }

    public function testManagerBookingIsAcceptedWithoutApproval(): void {
        // Room requires approval, but the organizer is a manager of the room —
        // their own booking should be accepted directly, not queued (issue #23).
        $room = array_merge($this->testRoom, ['autoAccept' => false]);
        $this->roomService = $this->createMock(RoomService::class);
        $this->roomService->method('isRoomPrincipal')->willReturn(true);
        $this->roomService->method('getRoomIdByPrincipal')->willReturn('room1');
        $this->roomService->method('getRoom')->willReturn($room);

        $this->permissionService = $this->createMock(PermissionService::class);
        $this->permissionService->method('getEffectivePermissions')->willReturn([
            'viewers' => [], 'bookers' => [], 'managers' => [['type' => 'user', 'id' => 'testuser']],
        ]);
        $this->permissionService->method('canBook')->willReturn(true);
        $this->permissionService->method('canManage')
            ->with('testuser', 'room1')
            ->willReturn(true);

        $this->mailService = $this->createMock(MailService::class);
        $this->mailService->expects($this->once())->method('sendAccepted');
        $this->mailService->expects($this->never())->method('notifyManagers');

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        // sender resolves to 'testuser' (see buildRequestMessage default)
        $message = $this->buildRequestMessage();
        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('1.2', $message->scheduleStatus);
    }

    public function testRequestSendsConflictEmail(): void {
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->calDAVService->method('getRoomCalendarId')->willReturn(1);
        $this->calDAVService->method('findConflicts')->willReturnCallback(fn (string $u, array $occurrences) => $occurrences);

        $this->mailService = $this->createMock(MailService::class);
        $this->mailService->expects($this->once())
            ->method('sendConflict');

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage();
        $plugin->handleScheduleRequest($message);
    }

    // ── Exchange push fail-safe ────────────────────────────────────

    public function testRequestExchangePushFailSafe(): void {
        $this->exchangeSyncService = $this->createMock(ExchangeSyncService::class);
        $this->exchangeSyncService->method('pushBookingToExchange')
            ->willThrowException(new \RuntimeException('Exchange unavailable'));

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage();
        $result = $plugin->handleScheduleRequest($message);

        // Booking still succeeds despite Exchange failure
        $this->assertFalse($result);
        $this->assertSame('1.2', $message->scheduleStatus);
    }
}
