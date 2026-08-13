<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\CalDAVService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for issue #27 — all-day bookings shifted by the timezone.
 *
 * An all-day VEVENT carries `DTSTART;VALUE=DATE:20260813`: a calendar date with
 * no time and no zone. Sabre hands that back as a DateTime at midnight, so
 * serialising it with format('c') invents an instant — rendered in UTC+2 that
 * midnight becomes 02:00 and the booking appears to run into the next day.
 */
class CalDAVServiceAllDayTest extends TestCase {
    private CalDAVService $service;

    protected function setUp(): void {
        $this->service = new CalDAVService(
            $this->createMock(CalDavBackend::class),
            $this->createMock(IUserManager::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function callIsAllDay(?VEvent $vEvent): bool {
        $method = new \ReflectionMethod($this->service, 'isAllDayEvent');
        return $method->invoke($this->service, $vEvent);
    }

    private function callFormat(?\DateTimeInterface $date, bool $isAllDay): ?string {
        $method = new \ReflectionMethod($this->service, 'formatEventDate');
        return $method->invoke($this->service, $date, $isAllDay);
    }

    private function makeEvent(?string $valueType): VEvent {
        $vEvent = new VEvent();
        $dtStart = new Property(new \DateTimeImmutable('2026-08-13 00:00:00'));
        if ($valueType !== null) {
            $dtStart['VALUE'] = $valueType;
        }
        $vEvent->DTSTART = $dtStart;
        return $vEvent;
    }

    public function testDateValueTypeIsRecognisedAsAllDay(): void {
        $this->assertTrue($this->callIsAllDay($this->makeEvent('DATE')));
    }

    public function testLowercaseValueTypeIsRecognised(): void {
        $this->assertTrue($this->callIsAllDay($this->makeEvent('date')));
    }

    public function testTimedEventIsNotAllDay(): void {
        $this->assertFalse($this->callIsAllDay($this->makeEvent('DATE-TIME')));
        $this->assertFalse($this->callIsAllDay($this->makeEvent(null)));
    }

    public function testMissingEventOrDtstartIsNotAllDay(): void {
        $this->assertFalse($this->callIsAllDay(null));
        $this->assertFalse($this->callIsAllDay(new VEvent()));
    }

    /**
     * The core of #27: an all-day date must serialise without a time or offset,
     * so no client can shift it into an adjacent day.
     */
    public function testAllDayDateSerialisesWithoutTimeOrOffset(): void {
        $midnight = new \DateTimeImmutable('2026-08-13 00:00:00', new \DateTimeZone('Europe/Amsterdam'));

        $formatted = $this->callFormat($midnight, true);

        $this->assertSame('2026-08-13', $formatted);
        $this->assertStringNotContainsString('T', (string)$formatted);
        $this->assertStringNotContainsString('+', (string)$formatted);
    }

    /**
     * Guards the exact symptom from the report: midnight in a UTC+2 zone used
     * to be emitted as an offset timestamp, which renders as 02:00.
     */
    public function testAllDayMidnightIsNotEmittedAsOffsetTimestamp(): void {
        $midnight = new \DateTimeImmutable('2026-08-13 00:00:00', new \DateTimeZone('Europe/Amsterdam'));

        $this->assertSame('2026-08-13T00:00:00+02:00', $this->callFormat($midnight, false));
        $this->assertSame('2026-08-13', $this->callFormat($midnight, true));
    }

    public function testTimedEventKeepsFullIso8601(): void {
        $start = new \DateTimeImmutable('2026-08-13 09:30:00', new \DateTimeZone('Europe/Amsterdam'));

        $this->assertSame('2026-08-13T09:30:00+02:00', $this->callFormat($start, false));
    }

    public function testNullDateStaysNull(): void {
        $this->assertNull($this->callFormat(null, true));
        $this->assertNull($this->callFormat(null, false));
    }
}
