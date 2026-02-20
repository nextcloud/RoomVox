<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\CalDAVService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * Tests for CalDAVService::hasConflict() — the core of double-booking prevention.
 *
 * Mocks CalDavBackend so calendarQuery() returns URIs and getCalendarObject()
 * returns iCal data. Reader::read() is overridden via setTestParser() to return
 * controllable VObject stubs.
 */
class CalDAVServiceConflictTest extends TestCase {
    private CalDAVService $service;
    private CalDavBackend $calDavBackend;

    protected function setUp(): void {
        $this->calDavBackend = $this->createMock(CalDavBackend::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new CalDAVService($this->calDavBackend, $logger);

        // Reset test parser
        Reader::setTestParser(null);
    }

    protected function tearDown(): void {
        Reader::setTestParser(null);
    }

    /**
     * Helper: configure CalDavBackend to return a calendar for the room user
     * and set up events that will be found by calendarQuery.
     *
     * @param array $events Array of ['uid' => string, 'start' => DateTime, 'end' => DateTime, 'status' => string]
     */
    private function setupCalendarWithEvents(array $events): void {
        // getCalendarsForUser returns a calendar with id=1
        $this->calDavBackend->method('getCalendarsForUser')
            ->willReturn([['id' => 1, 'uri' => 'personal']]);

        // calendarQuery returns URIs for all events
        $uris = array_map(fn($e) => ($e['uid'] ?? 'event') . '.ics', $events);
        $this->calDavBackend->method('calendarQuery')
            ->willReturn($uris);

        // getCalendarObject returns iCal data keyed by URI
        $objectMap = [];
        foreach ($events as $event) {
            $uri = ($event['uid'] ?? 'event') . '.ics';
            $objectMap[$uri] = ['calendardata' => 'ics-data-for-' . $uri];
        }

        $this->calDavBackend->method('getCalendarObject')
            ->willReturnCallback(function (int $calId, string $uri) use ($objectMap) {
                return $objectMap[$uri] ?? null;
            });

        // Reader::read() returns a VObject with the correct VEVENT data
        $eventsByUri = [];
        foreach ($events as $event) {
            $uri = ($event['uid'] ?? 'event') . '.ics';
            $eventsByUri['ics-data-for-' . $uri] = $event;
        }

        Reader::setTestParser(function (string $data) use ($eventsByUri) {
            $eventData = $eventsByUri[$data] ?? null;
            if ($eventData === null) {
                return new VCalendar();
            }

            $vEvent = new VEvent();
            $vEvent->DTSTART = new Property($eventData['start']);
            $vEvent->DTEND = new Property($eventData['end']);
            $vEvent->UID = new Property($eventData['uid'] ?? 'test-uid');

            if (isset($eventData['status'])) {
                $vEvent->STATUS = new Property($eventData['status']);
            }

            $vCalendar = new VCalendar();
            $vCalendar->VEVENT = $vEvent;

            return $vCalendar;
        });
    }

    // ── Exact overlap ──────────────────────────────────────────────

    public function testConflictExactOverlap(): void {
        $this->setupCalendarWithEvents([
            ['uid' => 'existing', 'start' => new \DateTime('2026-02-20 10:00'), 'end' => new \DateTime('2026-02-20 11:00')],
        ]);

        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertTrue($result);
    }

    // ── Partial overlaps ───────────────────────────────────────────

    public function testConflictPartialOverlapStart(): void {
        $this->setupCalendarWithEvents([
            ['uid' => 'existing', 'start' => new \DateTime('2026-02-20 10:00'), 'end' => new \DateTime('2026-02-20 11:00')],
        ]);

        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 09:30'),
            new \DateTime('2026-02-20 10:30'),
        );

        $this->assertTrue($result);
    }

    public function testConflictPartialOverlapEnd(): void {
        $this->setupCalendarWithEvents([
            ['uid' => 'existing', 'start' => new \DateTime('2026-02-20 10:00'), 'end' => new \DateTime('2026-02-20 11:00')],
        ]);

        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 10:30'),
            new \DateTime('2026-02-20 11:30'),
        );

        $this->assertTrue($result);
    }

    // ── Containment ────────────────────────────────────────────────

    public function testConflictNewContainedInExisting(): void {
        $this->setupCalendarWithEvents([
            ['uid' => 'existing', 'start' => new \DateTime('2026-02-20 09:00'), 'end' => new \DateTime('2026-02-20 12:00')],
        ]);

        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertTrue($result);
    }

    public function testConflictNewContainsExisting(): void {
        $this->setupCalendarWithEvents([
            ['uid' => 'existing', 'start' => new \DateTime('2026-02-20 10:00'), 'end' => new \DateTime('2026-02-20 11:00')],
        ]);

        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 09:00'),
            new \DateTime('2026-02-20 12:00'),
        );

        $this->assertTrue($result);
    }

    // ── Adjacent bookings (no conflict) ────────────────────────────

    public function testNoConflictAdjacentAfter(): void {
        $this->setupCalendarWithEvents([
            ['uid' => 'existing', 'start' => new \DateTime('2026-02-20 10:00'), 'end' => new \DateTime('2026-02-20 11:00')],
        ]);

        // New booking starts exactly when existing ends
        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 11:00'),
            new \DateTime('2026-02-20 12:00'),
        );

        $this->assertFalse($result);
    }

    public function testNoConflictAdjacentBefore(): void {
        $this->setupCalendarWithEvents([
            ['uid' => 'existing', 'start' => new \DateTime('2026-02-20 10:00'), 'end' => new \DateTime('2026-02-20 11:00')],
        ]);

        // New booking ends exactly when existing starts
        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 09:00'),
            new \DateTime('2026-02-20 10:00'),
        );

        $this->assertFalse($result);
    }

    public function testNoConflictSameDay(): void {
        $this->setupCalendarWithEvents([
            ['uid' => 'existing', 'start' => new \DateTime('2026-02-20 10:00'), 'end' => new \DateTime('2026-02-20 11:00')],
        ]);

        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 14:00'),
            new \DateTime('2026-02-20 15:00'),
        );

        $this->assertFalse($result);
    }

    // ── Event status handling ──────────────────────────────────────

    public function testConflictSkipsCancelledEvents(): void {
        $this->setupCalendarWithEvents([
            ['uid' => 'cancelled', 'start' => new \DateTime('2026-02-20 10:00'), 'end' => new \DateTime('2026-02-20 11:00'), 'status' => 'CANCELLED'],
        ]);

        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertFalse($result);
    }

    public function testConflictTentativeBlocks(): void {
        $this->setupCalendarWithEvents([
            ['uid' => 'tentative', 'start' => new \DateTime('2026-02-20 10:00'), 'end' => new \DateTime('2026-02-20 11:00'), 'status' => 'TENTATIVE'],
        ]);

        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertTrue($result);
    }

    public function testConflictNeedsActionBlocks(): void {
        // No STATUS property = not cancelled, so it blocks
        $this->setupCalendarWithEvents([
            ['uid' => 'nostat', 'start' => new \DateTime('2026-02-20 10:00'), 'end' => new \DateTime('2026-02-20 11:00')],
        ]);

        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertTrue($result);
    }

    // ── Exclude UID (for reschedule) ───────────────────────────────

    public function testConflictExcludeUid(): void {
        $this->setupCalendarWithEvents([
            ['uid' => 'booking-x', 'start' => new \DateTime('2026-02-20 10:00'), 'end' => new \DateTime('2026-02-20 11:00')],
        ]);

        // Reschedule booking-x to same time: exclude itself
        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
            'booking-x',
        );

        $this->assertFalse($result);
    }

    public function testConflictExcludeUidOtherStillBlocks(): void {
        $this->setupCalendarWithEvents([
            ['uid' => 'booking-x', 'start' => new \DateTime('2026-02-20 10:00'), 'end' => new \DateTime('2026-02-20 11:00')],
            ['uid' => 'booking-y', 'start' => new \DateTime('2026-02-20 10:00'), 'end' => new \DateTime('2026-02-20 11:00')],
        ]);

        // Exclude booking-x, but booking-y still blocks
        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
            'booking-x',
        );

        $this->assertTrue($result);
    }

    // ── Empty / no calendar ────────────────────────────────────────

    public function testConflictEmptyCalendar(): void {
        $this->calDavBackend->method('getCalendarsForUser')
            ->willReturn([['id' => 1, 'uri' => 'personal']]);
        $this->calDavBackend->method('calendarQuery')
            ->willReturn([]);

        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertFalse($result);
    }

    public function testConflictNoCalendar(): void {
        // No calendars for this user
        $this->calDavBackend->method('getCalendarsForUser')
            ->willReturn([]);

        $result = $this->service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertFalse($result);
    }
}
