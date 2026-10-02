<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\InstanceTimezone;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ITip\Message;
use Sabre\VObject\Property;

/**
 * Mails printed event times in the zone they were stored in, so an event
 * stored in UTC was confirmed as 11:30-14:30 while Calendar showed the
 * booked 13:30-16:30 in Vienna (issue #49). Once #45 stores every API
 * booking in UTC, that would have hit every API booking.
 */
class MailServiceTimezoneTest extends TestCase {
    private function service(?string $timezone): MailService {
        $instanceTimezone = null;
        if ($timezone !== null) {
            $config = $this->createMock(IConfig::class);
            $config->method('getSystemValueString')->willReturn($timezone);
            $instanceTimezone = new InstanceTimezone($config);
        }

        return new MailService(
            $this->createMock(IMailer::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(ICrypto::class),
            $this->createMock(PermissionService::class),
            $this->createMock(IUserManager::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(LoggerInterface::class),
            null,
            $instanceTimezone,
        );
    }

    private function call(MailService $service, string $method, ...$args): mixed {
        return (new \ReflectionMethod($service, $method))->invoke($service, ...$args);
    }

    /** The respond flow: approve/decline/cancel from the RoomVox UI. */
    public function testRespondFlowShowsUtcEventInInstanceTime(): void {
        $info = $this->call($this->service('Europe/Vienna'), 'bookingDataToEventInfo', [
            'organizerName' => 'Alice',
            'summary' => 'Workshop',
            'dtstart' => '2026-10-01T11:30:00+00:00',
            'dtend' => '2026-10-01T14:30:00+00:00',
        ]);

        $this->assertSame('Thursday, October 1, 2026 13:30', $info['dtstartFormatted']);
        $this->assertSame('16:30 (Europe/Vienna)', $info['dtendFormatted']);
    }

    /**
     * An all-day booking comes back from Sabre as UTC midnight. Converting
     * it would move it to the previous day west of UTC, and its 00:00-00:00
     * times say nothing, so it is shown as a date. DTEND is exclusive: a
     * single day has no end date.
     */
    public function testSingleAllDayBookingIsShownAsOneDate(): void {
        $service = $this->service('America/New_York');
        $info = $this->call($service, 'bookingDataToEventInfo', [
            'organizerName' => 'Alice',
            'summary' => 'Offsite',
            'dtstart' => '2026-08-13T00:00:00+00:00',
            'dtend' => '2026-08-14T00:00:00+00:00',
            'wallClock' => true,
            'allDay' => true,
        ]);

        $this->assertSame('Thursday, August 13, 2026', $info['dtstartFormatted']);
        $this->assertNull($info['dtendFormatted']);

        $l = $this->call($service, 'getL10n', null);
        $block = $this->call($service, 'buildEventBlock', $l, ['name' => 'Room 1'], $info);
        $this->assertStringContainsString("Date: Thursday, August 13, 2026\n", $block);
    }

    public function testMultiDayAllDayBookingShowsItsLastDay(): void {
        $info = $this->call($this->service('Europe/Vienna'), 'bookingDataToEventInfo', [
            'organizerName' => 'Alice',
            'summary' => 'Conference',
            'dtstart' => '2026-10-05T00:00:00+00:00',
            'dtend' => '2026-10-08T00:00:00+00:00',
            'wallClock' => true,
            'allDay' => true,
        ]);

        $this->assertSame('Monday, October 5, 2026', $info['dtstartFormatted']);
        $this->assertSame('Wednesday, October 7, 2026', $info['dtendFormatted']);
    }

    /** Floating times are wall-clock too, but they do have a time. */
    public function testFloatingTimeIsPrintedAsStoredWithItsTime(): void {
        $info = $this->call($this->service('America/New_York'), 'bookingDataToEventInfo', [
            'organizerName' => 'Alice',
            'summary' => 'Standup',
            'dtstart' => '2026-10-05T09:00:00+00:00',
            'dtend' => '2026-10-05T10:00:00+00:00',
            'wallClock' => true,
        ]);

        $this->assertSame('Monday, October 5, 2026 09:00', $info['dtstartFormatted']);
        $this->assertSame('10:00', $info['dtendFormatted']);
    }

    /** The iTIP flow: accepted/declined/conflict mails via CalDAV. */
    public function testItipFlowShowsUtcEventInInstanceTime(): void {
        $event = new VEvent();
        $event->SUMMARY = 'Workshop';
        $event->DTSTART = new Property(new \DateTimeImmutable('2026-10-01T11:30:00Z'));
        $event->DTEND = new Property(new \DateTimeImmutable('2026-10-01T14:30:00Z'));
        $calendar = new VCalendar();
        $calendar->VEVENT = $event;
        $message = new Message();
        $message->message = $calendar;

        $info = $this->call($this->service('Europe/Vienna'), 'extractEventInfo', $message);

        $this->assertSame('Thursday, October 1, 2026 13:30', $info['dtstartFormatted']);
        $this->assertSame('16:30 (Europe/Vienna)', $info['dtendFormatted']);
    }

    public function testItipAllDayEventIsShownAsADate(): void {
        $start = new Property(new \DateTimeImmutable('2026-08-13T00:00:00Z'));
        $start['VALUE'] = 'DATE';
        $event = new VEvent();
        $event->DTSTART = $start;
        $event->DTEND = new Property(new \DateTimeImmutable('2026-08-14T00:00:00Z'));
        $calendar = new VCalendar();
        $calendar->VEVENT = $event;
        $message = new Message();
        $message->message = $calendar;

        $info = $this->call($this->service('Europe/Vienna'), 'extractEventInfo', $message);

        $this->assertSame('Thursday, August 13, 2026', $info['dtstartFormatted']);
        $this->assertNull($info['dtendFormatted']);
    }

    /** A single cancelled occurrence has no end; the zone goes on the start. */
    public function testStartOnlyCarriesTheZoneName(): void {
        [$start] = $this->call(
            $this->service('Europe/Vienna'),
            'formatEventTimes',
            new \DateTimeImmutable('2026-10-19T08:00:00Z'),
            null,
            false,
        );

        $this->assertSame('Monday, October 19, 2026 10:00 (Europe/Vienna)', $start);
    }

    /**
     * A cancelled occurrence is identified by a recurrence id that always
     * carries an offset (getBookings() uses format('c')), so it cannot tell
     * an all-day occurrence from an instant. Guessing from the string moved a
     * cancelled all-day day to "Sunday 20:00" in New York; the series' flags
     * decide instead.
     */
    public function testCancelledAllDayOccurrenceKeepsItsDate(): void {
        $formatted = $this->call($this->service('America/New_York'), 'formatOccurrence',
            '2026-10-05T00:00:00+00:00', ['allDay' => true, 'wallClock' => true]);

        $this->assertSame('Monday, October 5, 2026', $formatted);
    }

    public function testCancelledFloatingOccurrenceIsPrintedAsStored(): void {
        $formatted = $this->call($this->service('America/New_York'), 'formatOccurrence',
            '2026-10-05T09:00:00+00:00', ['allDay' => false, 'wallClock' => true]);

        $this->assertSame('Monday, October 5, 2026 09:00', $formatted);
    }

    public function testCancelledTimedOccurrenceIsConverted(): void {
        $formatted = $this->call($this->service('Europe/Vienna'), 'formatOccurrence',
            '2026-10-19T08:00:00+00:00', ['allDay' => false, 'wallClock' => false]);

        $this->assertSame('Monday, October 19, 2026 10:00 (Europe/Vienna)', $formatted);
    }

    public function testWithoutInstanceTimezoneTimesAreLabelledUtc(): void {
        $info = $this->call($this->service(null), 'bookingDataToEventInfo', [
            'organizerName' => 'Alice',
            'summary' => 'Workshop',
            'dtstart' => '2026-10-01T13:30:00+02:00',
            'dtend' => '2026-10-01T16:30:00+02:00',
        ]);

        $this->assertSame('Thursday, October 1, 2026 11:30', $info['dtstartFormatted']);
        $this->assertSame('14:30 (UTC)', $info['dtendFormatted']);
    }
}
