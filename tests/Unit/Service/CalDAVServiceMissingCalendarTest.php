<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\CalDAVService;
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Issue #44, wider blast radius — "there is no calendar" is currently
 * indistinguishable from "nothing is booked" throughout CalDAVService.
 *
 * 16 call sites swallow a null calendar id; 11 do so completely silently.
 * The two that matter most are pinned here.
 *
 * CalDAVServiceConflictTest::testConflictNoCalendar() used to assert the
 * double-booking-permitting behaviour as correct; it was reversed with the fix.
 */
class CalDAVServiceMissingCalendarTest extends TestCase {
    private CalDAVService $service;
    private CalDavBackend $calDavBackend;

    protected function setUp(): void {
        $this->calDavBackend = $this->createMock(CalDavBackend::class);
        $this->service = new CalDAVService(
            $this->calDavBackend,
            $this->createMock(IUserManager::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /** No calendar under either the calendar-rooms or the user principal. */
    private function withNoCalendar(): void {
        $this->calDavBackend->method('getCalendarsForUser')->willReturn([]);
    }

    /**
     * For a list, "no bookings" is the truth about a room without a calendar:
     * nothing can be stored in it. What must not happen is that it passes
     * silently. The callers that would turn an empty list into "free" (status,
     * availability, booking) check getRoomCalendarId() themselves; see
     * PublicApiMissingCalendarTest.
     */
    public function testGetBookingsOnAMissingCalendarIsEmptyButLogged(): void {
        $this->withNoCalendar();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('has no calendar'));
        $service = new CalDAVService($this->calDavBackend, $this->createMock(IUserManager::class), $logger);

        $this->assertSame([], $service->getBookings('rb_office-103'));
    }

    /**
     * A new room's calendar is the one Nextcloud keeps for the room resource.
     * RoomVox asks Nextcloud to sync its room backends instead of creating the
     * calendar itself: Nextcloud's sync fails when the calendar already exists.
     */
    public function testProvisioningLetsNextcloudCreateTheRoomCalendar(): void {
        $synced = false;
        $roomManager = $this->createMock(IRoomManager::class);
        $roomManager->expects($this->once())->method('update')->willReturnCallback(function () use (&$synced) {
            $synced = true;
        });
        $this->service->setRoomManager($roomManager);
        $this->calDavBackend->method('getCalendarsForUser')->willReturnCallback(
            function (string $principal) use (&$synced) {
                return $principal === 'principals/calendar-rooms/roomvox-office-103' && $synced
                    ? [['id' => 21, 'uri' => 'calendar']]
                    : [];
            }
        );
        $this->calDavBackend->expects($this->never())->method('createCalendar');

        $this->assertSame('calendar', $this->service->provisionCalendar('rb_office-103'));
    }

    public function testProvisioningFailsWhenNextcloudCreatesNoCalendar(): void {
        $this->service->setRoomManager($this->createMock(IRoomManager::class));
        $this->withNoCalendar();

        $this->expectException(\RuntimeException::class);
        $this->service->provisionCalendar('rb_office-103');
    }

    /**
     * A calendar that is already there for a new room belongs to an earlier
     * room with the same id; it is emptied, not inherited with its bookings.
     */
    public function testProvisioningEmptiesAnInheritedCalendar(): void {
        $this->service->setRoomManager($this->createMock(IRoomManager::class));
        $this->calDavBackend->method('getCalendarsForUser')->willReturn([['id' => 21, 'uri' => 'calendar']]);
        $this->calDavBackend->method('getCalendarObjects')->willReturn([['uri' => 'old-1.ics'], ['uri' => 'old-2.ics']]);
        $deleted = [];
        $this->calDavBackend->method('deleteCalendarObject')->willReturnCallback(
            function (int $calendarId, string $uri, int $type, bool $permanent) use (&$deleted) {
                $deleted[] = [$calendarId, $uri, $permanent];
            }
        );

        $this->service->provisionCalendar('rb_office-103');

        $this->assertSame([[21, 'old-1.ics', true], [21, 'old-2.ics', true]], $deleted);
    }

    /**
     * Reading the room calendar and, failing that, the calendar RoomVox used
     * to provision split a room's bookings between the two.
     */
    public function testNoFallbackToTheLegacyCalendar(): void {
        $this->calDavBackend->method('getCalendarsForUser')->willReturnMap([
            ['principals/calendar-rooms/roomvox-office-103', []],
            ['principals/users/rb_office-103', [['id' => 4, 'uri' => 'room-rb_office-103']]],
        ]);

        $this->assertNull($this->service->getRoomCalendarId('rb_office-103'));
    }

    private function withLegacyAndRoomCalendar(): void {
        $this->calDavBackend->method('getCalendarsForUser')->willReturnMap([
            ['principals/calendar-rooms/roomvox-office-103', [['id' => 21, 'uri' => 'calendar']]],
            ['principals/users/rb_office-103', [['id' => 4, 'uri' => 'room-rb_office-103']]],
        ]);
        $this->calDavBackend->method('getCalendarObjects')->willReturnCallback(
            fn (int $id) => $id === 21 ? [['uri' => 'kept.ics']] : [['uri' => 'a.ics'], ['uri' => 'dup.ics']]
        );
        // The UID as Nextcloud parsed and stored it. dup.ics carries the same
        // UID as kept.ics but folded differently in its text, as long Outlook
        // UIDs are; a text match would miss that.
        $this->calDavBackend->method('getCalendarObject')->willReturnCallback(fn (int $id, string $uri) => match ($uri) {
            'kept.ics' => ['uid' => 'outlook-uid-0123456789', 'calendardata' => "UID:outlook-uid-01234\r\n 56789\r\n"],
            'a.ics' => ['uid' => 'only-legacy', 'calendardata' => "UID:only-legacy\r\n"],
            'dup.ics' => ['uid' => 'outlook-uid-0123456789', 'calendardata' => "UID:outlook-uid-0123\r\n 456789\r\n"],
        });
    }

    /**
     * Bookings left in the legacy calendar move into the room calendar, a UID
     * already there is kept as it is, and the legacy calendar goes.
     */
    public function testLegacyBookingsMoveIntoTheRoomCalendar(): void {
        $this->withLegacyAndRoomCalendar();
        $created = [];
        $this->calDavBackend->method('createCalendarObject')->willReturnCallback(
            function (int $id, string $uri) use (&$created) {
                $created[] = [$id, $uri];
            }
        );
        $this->calDavBackend->expects($this->once())->method('deleteCalendar')->with(4, true);

        $result = $this->service->moveLegacyBookings('rb_office-103');

        $this->assertSame(['moved' => 1, 'skipped' => 1, 'failed' => 0], $result);
        $this->assertSame([[21, 'a.ics']], $created);
    }

    /**
     * A booking that cannot be copied stays in the old calendar, which is
     * then not deleted, so nothing is lost and a later run can retry.
     */
    public function testABookingThatCannotMoveStaysAndKeepsTheOldCalendar(): void {
        $this->withLegacyAndRoomCalendar();
        $this->calDavBackend->method('createCalendarObject')->willThrowException(new \Exception('UidConflict'));
        $deleted = [];
        $this->calDavBackend->method('deleteCalendarObject')->willReturnCallback(
            function (int $id, string $uri) use (&$deleted) {
                $deleted[] = $uri;
            }
        );
        $this->calDavBackend->expects($this->never())->method('deleteCalendar');

        $result = $this->service->moveLegacyBookings('rb_office-103');

        $this->assertSame(['moved' => 0, 'skipped' => 1, 'failed' => 1], $result);
        $this->assertSame(['dup.ics'], $deleted, 'only the duplicate is removed; a.ics stays');
    }

    /**
     * Deleting a room empties Nextcloud's room calendar, which Nextcloud then
     * deletes itself, and deletes the legacy calendar for good.
     */
    public function testDeleteCalendarEmptiesTheRoomCalendarAndDropsTheLegacyOne(): void {
        $this->calDavBackend->method('getCalendarsForUser')->willReturnMap([
            ['principals/calendar-rooms/roomvox-office-103', [['id' => 21, 'uri' => 'calendar']]],
            ['principals/users/rb_office-103', [['id' => 4, 'uri' => 'room-rb_office-103']]],
        ]);
        $this->calDavBackend->method('getCalendarObjects')->willReturn([['uri' => 'b.ics']]);
        $this->calDavBackend->expects($this->once())->method('deleteCalendarObject')->with(21, 'b.ics', 0, true);
        $this->calDavBackend->expects($this->once())->method('deleteCalendar')->with(4, true);

        $this->service->deleteCalendar('rb_office-103');
    }

    /**
     * Nextcloud lists trashed calendars too. A room whose calendars are all in
     * the trashbin has none, and must be treated that way.
     */
    public function testTrashedCalendarsDoNotCount(): void {
        $trashed = ['{http://nextcloud.com/ns}deleted-at' => 1790872256];
        $this->calDavBackend->method('getCalendarsForUser')->willReturnMap([
            ['principals/calendar-rooms/roomvox-office-103', [['id' => 3, 'uri' => 'personal'] + $trashed]],
            ['principals/users/rb_office-103', [['id' => 4, 'uri' => 'room-rb_office-103'] + $trashed]],
        ]);

        $this->assertNull($this->service->getRoomCalendarId('rb_office-103'));
        $this->assertNull($this->service->getCalendarId('rb_office-103'));
    }

    public function testALiveCalendarNextToATrashedOneIsFound(): void {
        $this->calDavBackend->method('getCalendarsForUser')->willReturnMap([
            ['principals/calendar-rooms/roomvox-office-103', [
                ['id' => 3, 'uri' => 'old', '{http://nextcloud.com/ns}deleted-at' => 1790872256],
                ['id' => 5, 'uri' => 'personal', '{http://nextcloud.com/ns}deleted-at' => null],
            ]],
        ]);

        $this->assertSame(5, $this->service->getRoomCalendarId('rb_office-103'));
    }


    /**
     * hasConflict() must not report "no conflict" when it cannot look.
     *
     * Every booking path gates on this: PublicApiController::createBooking(),
     * BookingApiController::create()/update() and SchedulingPlugin. Returning
     * false means a calendar-less room passes the double-booking gate, and the
     * write then fails further downstream (or, on the LOCATION-match path in
     * SchedulingPlugin, is discarded without the organizer being told).
     *
     * Failing closed is the safe direction: refusing a booking for a room that
     * has no calendar costs nothing, since the booking could not be stored.
     */
    public function testHasConflictFailsClosedWhenCalendarIsMissing(): void {
        $this->withNoCalendar();

        $result = $this->service->hasConflict(
            'rb_office-103',
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertTrue(
            $result,
            'A missing calendar must not read as "no conflict" — that lets the double-booking gate pass '
            . 'for a room in which nothing can be stored at all.',
        );
    }
}
