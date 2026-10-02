<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\CalDAVService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * CalDAVService::createBooking() writes DTSTART/DTEND with a `Z` suffix. It
 * used to format the caller's DateTime as-is, so a request for 09:00+02:00 was
 * stored as 09:00Z and showed up two hours late everywhere (issue #45).
 */
class CalDAVServiceCreateBookingTest extends TestCase {
    private CalDavBackend $backend;
    private CalDAVService $service;
    private string $written = '';

    protected function setUp(): void {
        $this->backend = $this->createMock(CalDavBackend::class);
        $this->backend->method('getCalendarsForUser')->willReturn([['id' => 7]]);
        $this->backend->method('createCalendarObject')->willReturnCallback(
            function (int $calendarId, string $uri, string $data): void {
                $this->written = $data;
            }
        );

        $this->service = new CalDAVService(
            $this->backend,
            $this->createMock(IUserManager::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function create(\DateTime $start, \DateTime $end): void {
        $this->service->createBooking('rb_room1', [
            'summary' => 'Standup',
            'start' => $start,
            'end' => $end,
        ]);
    }

    public function testOffsetTimesAreStoredAsTheSameInstantInUtc(): void {
        $this->create(new \DateTime('2026-09-28T09:00:00+02:00'), new \DateTime('2026-09-28T10:00:00+02:00'));

        $this->assertStringContainsString("DTSTART:20260928T070000Z\r\n", $this->written);
        $this->assertStringContainsString("DTEND:20260928T080000Z\r\n", $this->written);
    }

    public function testNamedTimezoneIsConvertedAcrossMidnight(): void {
        $vienna = new \DateTimeZone('Europe/Vienna');
        $this->create(new \DateTime('2026-09-28 00:30', $vienna), new \DateTime('2026-09-28 01:30', $vienna));

        $this->assertStringContainsString("DTSTART:20260927T223000Z\r\n", $this->written);
        $this->assertStringContainsString("DTEND:20260927T233000Z\r\n", $this->written);
    }

    public function testUtcTimesAreWrittenUnchanged(): void {
        $this->create(new \DateTime('2026-09-28T09:00:00Z'), new \DateTime('2026-09-28T10:00:00Z'));

        $this->assertStringContainsString("DTSTART:20260928T090000Z\r\n", $this->written);
    }

    /**
     * The Public API echoes the request back from the same DateTime objects,
     * so converting them in place would change its response.
     */
    public function testCallerDateTimesAreNotModified(): void {
        $start = new \DateTime('2026-09-28T09:00:00+02:00');
        $end = new \DateTime('2026-09-28T10:00:00+02:00');

        $this->create($start, $end);

        $this->assertSame('2026-09-28T09:00:00+02:00', $start->format(\DateTimeInterface::ATOM));
        $this->assertSame('2026-09-28T10:00:00+02:00', $end->format(\DateTimeInterface::ATOM));
    }
}
