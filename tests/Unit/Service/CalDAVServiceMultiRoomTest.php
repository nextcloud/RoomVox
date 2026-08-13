<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\RoomService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * Regression tests for issue #22 — booking two rooms on one event without
 * auto-accept.
 *
 * An event booked into two rooms carries BOTH room ATTENDEE lines, and each
 * room calendar holds its own copy of that same event. Reading or writing "the
 * first CUTYPE=ROOM attendee" therefore addressed the *other* room once a
 * second room was added: room B reported room A's PARTSTAT (so B never showed
 * up in the approval queue, staying on "Needs action") and approving B wrote
 * ACCEPTED onto A's line.
 */
class CalDAVServiceMultiRoomTest extends TestCase {
    private const ROOM_A = 'room-a@example.com';
    private const ROOM_B = 'room-b@example.com';
    private const ORGANIZER = 'alice@example.com';

    private CalDAVService $service;
    private CalDavBackend $calDavBackend;

    protected function setUp(): void {
        $this->calDavBackend = $this->createMock(CalDavBackend::class);
        $userManager = $this->createMock(IUserManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new CalDAVService($this->calDavBackend, $userManager, $logger);

        // Late-inject a RoomService that maps the two room user ids to their
        // email addresses — exactly what Application::boot() wires up.
        $roomService = $this->createMock(RoomService::class);
        $roomService->method('getRoomByUserId')->willReturnCallback(
            fn (string $userId) => match ($userId) {
                'rb_room-a' => ['id' => 'room-a', 'userId' => 'rb_room-a', 'email' => self::ROOM_A],
                'rb_room-b' => ['id' => 'room-b', 'userId' => 'rb_room-b', 'email' => self::ROOM_B],
                default => null,
            }
        );
        $this->service->setRoomService($roomService);

        Reader::setTestParser(null);
    }

    protected function tearDown(): void {
        Reader::setTestParser(null);
    }

    /**
     * One event, two room attendees. Room A is TENTATIVE (added first, so it is
     * the one the old "first CUTYPE=ROOM" scan always found); room B carries the
     * PARTSTAT under test.
     *
     * @param array<string, string> $partstatByRoomEmail
     */
    private function setupTwoRoomEvent(array $partstatByRoomEmail): void {
        $data = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:two-rooms\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $this->calDavBackend->method('getCalendarsForUser')
            ->willReturn([['id' => 7, 'uri' => 'room-cal']]);
        $this->calDavBackend->method('getCalendarObjects')
            ->willReturn([['uri' => 'two-rooms.ics']]);
        $this->calDavBackend->method('getCalendarObject')
            ->willReturn(['calendardata' => $data]);

        Reader::setTestParser(function () use ($partstatByRoomEmail) {
            $vEvent = new VEvent();
            $vEvent->UID = new Property('two-rooms');
            $vEvent->ORGANIZER = new Property('mailto:' . self::ORGANIZER);

            // Order matters: room A first, mirroring the reported scenario.
            foreach ($partstatByRoomEmail as $email => $partstat) {
                $attendee = new Property('mailto:' . $email);
                $attendee->CUTYPE = 'ROOM';
                $attendee->PARTSTAT = $partstat;
                $vEvent->addChild('ATTENDEE', $attendee);
            }

            $vCalendar = new VCalendar();
            $vCalendar->VEVENT = $vEvent;
            return $vCalendar;
        });
    }

    public function testSecondRoomReportsItsOwnPartstatNotTheFirstRoomsInBookings(): void {
        $this->setupTwoRoomEvent([
            self::ROOM_A => 'ACCEPTED',
            self::ROOM_B => 'TENTATIVE',
        ]);

        $bookings = $this->service->getBookings('rb_room-b');

        $this->assertCount(1, $bookings);
        $this->assertSame(
            'TENTATIVE',
            $bookings[0]['partstat'],
            'Room B must report its own TENTATIVE, not room A ACCEPTED (#22)'
        );
    }

    public function testFirstRoomStillReportsItsOwnPartstatInBookings(): void {
        $this->setupTwoRoomEvent([
            self::ROOM_A => 'ACCEPTED',
            self::ROOM_B => 'TENTATIVE',
        ]);

        $bookings = $this->service->getBookings('rb_room-a');

        $this->assertCount(1, $bookings);
        $this->assertSame('ACCEPTED', $bookings[0]['partstat']);
    }

    /**
     * The approval queue reads getRawCalendarObjects()/getBookings() and filters
     * on PARTSTAT === 'TENTATIVE'. Room B reporting room A's status is precisely
     * why the second room never appeared for a manager to act on.
     */
    public function testPendingSecondRoomIsVisibleToApprovalQueue(): void {
        $this->setupTwoRoomEvent([
            self::ROOM_A => 'ACCEPTED',
            self::ROOM_B => 'TENTATIVE',
        ]);

        $pending = array_filter(
            $this->service->getBookings('rb_room-b'),
            fn (array $b) => ($b['partstat'] ?? '') === 'TENTATIVE'
        );

        $this->assertCount(1, $pending, 'Second room must appear in the approval queue (#22)');
    }

    public function testRawCalendarObjectsMatchesTheOwningRoomsAttendee(): void {
        $this->setupTwoRoomEvent([
            self::ROOM_A => 'ACCEPTED',
            self::ROOM_B => 'TENTATIVE',
        ]);

        $result = $this->service->getRawCalendarObjects('rb_room-b', null);

        $this->assertCount(1, $result);
        $this->assertSame('TENTATIVE', $result[0]['partstat']);
    }

    /**
     * The iCal feed asks for ACCEPTED-only. Room B is still pending, so its feed
     * must stay empty even though room A already accepted the same event.
     */
    public function testAcceptedOnlyFilterUsesTheOwningRoomsPartstat(): void {
        $this->setupTwoRoomEvent([
            self::ROOM_A => 'ACCEPTED',
            self::ROOM_B => 'TENTATIVE',
        ]);

        $this->assertSame([], $this->service->getRawCalendarObjects('rb_room-b', 'ACCEPTED'));
        $this->assertCount(1, $this->service->getRawCalendarObjects('rb_room-a', 'ACCEPTED'));
    }

    /**
     * A manager approving room B must write ACCEPTED onto room B's attendee
     * line and leave room A untouched.
     */
    public function testApprovingSecondRoomUpdatesItsOwnAttendeeLine(): void {
        $this->setupTwoRoomEvent([
            self::ROOM_A => 'TENTATIVE',
            self::ROOM_B => 'TENTATIVE',
        ]);

        $result = $this->service->updateBookingPartstat('rb_room-b', 'two-rooms', 'ACCEPTED');

        $this->assertNotNull($result);
        $this->assertSame(
            self::ROOM_B,
            $result['roomEmail'],
            'Approving room B must report room B as the updated room, not room A (#22)'
        );
    }

    public function testApprovingFirstRoomStillTargetsTheFirstRoom(): void {
        $this->setupTwoRoomEvent([
            self::ROOM_A => 'TENTATIVE',
            self::ROOM_B => 'TENTATIVE',
        ]);

        $result = $this->service->updateBookingPartstat('rb_room-a', 'two-rooms', 'ACCEPTED');

        $this->assertNotNull($result);
        $this->assertSame(self::ROOM_A, $result['roomEmail']);
    }

    /**
     * Single-room events must keep working when RoomService cannot resolve the
     * room (no late injection, or an unknown user id): the CUTYPE=ROOM
     * heuristic remains as the fallback.
     */
    public function testFallsBackToCutypeRoomWhenRoomCannotBeResolved(): void {
        $this->setupTwoRoomEvent([self::ROOM_A => 'TENTATIVE']);

        $bookings = $this->service->getBookings('rb_unknown-room');

        $this->assertCount(1, $bookings);
        $this->assertSame('TENTATIVE', $bookings[0]['partstat']);
    }
}
