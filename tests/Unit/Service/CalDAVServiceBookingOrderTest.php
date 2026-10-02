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
 * getBookings() promised chronological order but compared the serialised
 * dtstart strings. Since #45 stores API bookings in UTC they come back as
 * +00:00 next to CalDAV bookings that keep +02:00, and "07:00+00:00" sorts
 * before "08:30+02:00" although it is half an hour later. The availability
 * slot builder walks the list in order and dropped the earlier booking.
 */
class CalDAVServiceBookingOrderTest extends TestCase {
    protected function tearDown(): void {
        Reader::setTestParser(null);
    }

    public function testBookingsAreOrderedByInstantNotByString(): void {
        $events = [
            'api.ics' => ['api', new \DateTimeImmutable('2026-09-28T07:00:00Z'), new \DateTimeImmutable('2026-09-28T08:00:00Z')],
            'caldav.ics' => ['caldav', new \DateTimeImmutable('2026-09-28T08:30:00+02:00'), new \DateTimeImmutable('2026-09-28T09:30:00+02:00')],
        ];

        $backend = $this->createMock(CalDavBackend::class);
        $backend->method('getCalendarsForUser')->willReturn([['id' => 7]]);
        $backend->method('getCalendarObjects')->willReturn([['uri' => 'api.ics'], ['uri' => 'caldav.ics']]);
        $backend->method('getCalendarObject')->willReturnCallback(
            fn(int $id, string $uri) => ['calendardata' => $uri]
        );

        Reader::setTestParser(function (string $data) use ($events) {
            [$uid, $start, $end] = $events[$data];
            $vEvent = new VEvent();
            $vEvent->UID = new Property($uid);
            $vEvent->SUMMARY = new Property($uid);
            $vEvent->DTSTART = new Property($start);
            $vEvent->DTEND = new Property($end);
            $vCalendar = new VCalendar();
            $vCalendar->VEVENT = $vEvent;
            return $vCalendar;
        });

        $service = new CalDAVService($backend, $this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class));
        $bookings = $service->getBookings('rb_room1');

        $this->assertSame(['caldav', 'api'], array_column($bookings, 'uid'));
        $this->assertSame([false, false], array_column($bookings, 'wallClock'));
    }
}
