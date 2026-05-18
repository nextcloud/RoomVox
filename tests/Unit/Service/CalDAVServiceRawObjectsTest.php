<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\CalDAVService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * Tests for CalDAVService::getRawCalendarObjects() — the path used by the
 * iCal feed to pass through master VEVENTs with RRULE intact (issue #4).
 */
class CalDAVServiceRawObjectsTest extends TestCase {
    private CalDAVService $service;
    private CalDavBackend $calDavBackend;

    protected function setUp(): void {
        $this->calDavBackend = $this->createMock(CalDavBackend::class);
        $userManager = $this->createMock(IUserManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new CalDAVService($this->calDavBackend, $userManager, $logger);

        Reader::setTestParser(null);
    }

    protected function tearDown(): void {
        Reader::setTestParser(null);
    }

    /**
     * Wire up the calendar-rooms principal lookup used by getRoomCalendarId()
     * and the getCalendarObjects/getCalendarObject calls used to enumerate
     * events.
     *
     * @param array<int, array{uri: string, calendardata: string, partstat: string, isRoomAttendee?: bool, organizer?: string}> $events
     */
    private function setupCalendarWithRawEvents(array $events): void {
        $this->calDavBackend->method('getCalendarsForUser')
            ->willReturn([['id' => 7, 'uri' => 'room-cal']]);

        $objectList = array_map(fn($e) => ['uri' => $e['uri']], $events);
        $this->calDavBackend->method('getCalendarObjects')
            ->willReturn($objectList);

        $byUri = [];
        foreach ($events as $e) {
            $byUri[$e['uri']] = ['calendardata' => $e['calendardata']];
        }
        $this->calDavBackend->method('getCalendarObject')
            ->willReturnCallback(function (int $calId, string $uri) use ($byUri) {
                return $byUri[$uri] ?? null;
            });

        $byData = [];
        foreach ($events as $e) {
            $byData[$e['calendardata']] = $e;
        }
        Reader::setTestParser(function (string $data) use ($byData) {
            $event = $byData[$data] ?? null;
            if ($event === null) {
                return new VCalendar();
            }

            $vEvent = new VEvent();
            $vEvent->UID = new Property($event['uri']);
            if (isset($event['organizer'])) {
                $vEvent->ORGANIZER = new Property('mailto:' . $event['organizer']);
            }

            $attendee = new Property('mailto:room@example.com');
            $isRoom = $event['isRoomAttendee'] ?? true;
            if ($isRoom) {
                $attendee->CUTYPE = 'ROOM';
            }
            $attendee->PARTSTAT = $event['partstat'];
            $vEvent->addChild('ATTENDEE', $attendee);

            $vCalendar = new VCalendar();
            $vCalendar->VEVENT = $vEvent;
            return $vCalendar;
        });
    }

    public function testReturnsRawCalendarDataForAcceptedRoomAttendee(): void {
        $rrule = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:abc\r\nRRULE:FREQ=WEEKLY;BYDAY=FR\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->setupCalendarWithRawEvents([
            ['uri' => 'abc.ics', 'calendardata' => $rrule, 'partstat' => 'ACCEPTED'],
        ]);

        $result = $this->service->getRawCalendarObjects('rb_room1', 'ACCEPTED');

        $this->assertCount(1, $result);
        $this->assertSame('abc.ics', $result[0]['uri']);
        $this->assertSame('ACCEPTED', $result[0]['partstat']);
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;BYDAY=FR', $result[0]['calendardata']);
    }

    public function testFiltersOutDeclinedSeriesWhenAcceptedOnly(): void {
        $data = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->setupCalendarWithRawEvents([
            ['uri' => 'x.ics', 'calendardata' => $data, 'partstat' => 'DECLINED'],
        ]);

        $result = $this->service->getRawCalendarObjects('rb_room1', 'ACCEPTED');

        $this->assertSame([], $result);
    }

    public function testReturnsBothEntriesWhenNoFilter(): void {
        $a = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:a\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $b = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:b\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->setupCalendarWithRawEvents([
            ['uri' => 'a.ics', 'calendardata' => $a, 'partstat' => 'ACCEPTED'],
            ['uri' => 'b.ics', 'calendardata' => $b, 'partstat' => 'TENTATIVE'],
        ]);

        $result = $this->service->getRawCalendarObjects('rb_room1', null);

        $this->assertCount(2, $result);
    }

    public function testReturnsEmptyWhenNoCalendar(): void {
        $this->calDavBackend->method('getCalendarsForUser')->willReturn([]);

        $result = $this->service->getRawCalendarObjects('rb_unknown', 'ACCEPTED');

        $this->assertSame([], $result);
    }
}
