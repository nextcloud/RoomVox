<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Dav;

use OCA\RoomVox\Dav\SchedulingPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;

/**
 * Tests for SchedulingPlugin::isWithinHorizon() — validates that bookings
 * too far in the future are rejected, including RRULE handling.
 *
 * Uses reflection to test the private method directly.
 */
class SchedulingPluginHorizonTest extends TestCase {
    private SchedulingPlugin $plugin;
    private \ReflectionMethod $method;

    protected function setUp(): void {
        // Create instance without constructor (needs many dependencies)
        $ref = new \ReflectionClass(SchedulingPlugin::class);
        $this->plugin = $ref->newInstanceWithoutConstructor();

        // Inject a mock logger (needed by isWithinHorizon for infinite RRULE logging)
        $loggerProp = $ref->getProperty('logger');
        $loggerProp->setValue($this->plugin, $this->createMock(LoggerInterface::class));

        $this->method = new \ReflectionMethod($this->plugin, 'isWithinHorizon');
    }

    /**
     * Build a VEvent with optional RRULE for testing.
     */
    private function buildVEvent(
        \DateTimeInterface $start,
        ?\DateTimeInterface $end = null,
        ?string $rrule = null,
    ): VEvent {
        $vEvent = new VEvent();
        $vEvent->DTSTART = new Property($start);

        if ($end !== null) {
            $vEvent->DTEND = new Property($end);
        }

        if ($rrule !== null) {
            $vEvent->RRULE = new Property($rrule);
        }

        return $vEvent;
    }

    // ── No horizon configured ──────────────────────────────────────

    public function testHorizonNoLimit(): void {
        $room = ['maxBookingHorizon' => 0]; // No restriction

        $vEvent = $this->buildVEvent(
            new \DateTimeImmutable('+365 days'),
            new \DateTimeImmutable('+365 days 1 hour'),
        );

        $this->assertTrue($this->method->invoke($this->plugin, $room, $vEvent));
    }

    // ── Single events ──────────────────────────────────────────────

    public function testHorizonWithinLimit(): void {
        $room = ['maxBookingHorizon' => 30];

        $vEvent = $this->buildVEvent(
            new \DateTimeImmutable('+10 days'),
            new \DateTimeImmutable('+10 days 1 hour'),
        );

        $this->assertTrue($this->method->invoke($this->plugin, $room, $vEvent));
    }

    public function testHorizonExceedsLimit(): void {
        $room = ['maxBookingHorizon' => 30];

        $vEvent = $this->buildVEvent(
            new \DateTimeImmutable('+60 days'),
            new \DateTimeImmutable('+60 days 1 hour'),
        );

        $this->assertFalse($this->method->invoke($this->plugin, $room, $vEvent));
    }

    // ── Recurring events with UNTIL ────────────────────────────────

    public function testHorizonRruleWithUntilWithin(): void {
        $room = ['maxBookingHorizon' => 30];

        $until = (new \DateTimeImmutable('+20 days'))->format('Ymd\THis\Z');
        $vEvent = $this->buildVEvent(
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+1 day 1 hour'),
            "FREQ=WEEKLY;UNTIL={$until}",
        );

        $this->assertTrue($this->method->invoke($this->plugin, $room, $vEvent));
    }

    public function testHorizonRruleWithUntilExceeds(): void {
        $room = ['maxBookingHorizon' => 30];

        $until = (new \DateTimeImmutable('+90 days'))->format('Ymd\THis\Z');
        $vEvent = $this->buildVEvent(
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+1 day 1 hour'),
            "FREQ=WEEKLY;UNTIL={$until}",
        );

        $this->assertFalse($this->method->invoke($this->plugin, $room, $vEvent));
    }

    // ── Recurring events with COUNT ────────────────────────────────

    public function testHorizonRruleWithCountWithin(): void {
        $room = ['maxBookingHorizon' => 30];

        // 3 weekly occurrences starting tomorrow = last occurrence in ~2 weeks
        $vEvent = $this->buildVEvent(
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+1 day 1 hour'),
            'FREQ=WEEKLY;COUNT=3',
        );

        $this->assertTrue($this->method->invoke($this->plugin, $room, $vEvent));
    }

    public function testHorizonRruleWithCountExceeds(): void {
        $room = ['maxBookingHorizon' => 30];

        // 10 weekly occurrences = last occurrence in ~9 weeks (~63 days)
        $vEvent = $this->buildVEvent(
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+1 day 1 hour'),
            'FREQ=WEEKLY;COUNT=10',
        );

        $this->assertFalse($this->method->invoke($this->plugin, $room, $vEvent));
    }

    // ── Infinite recurrence ────────────────────────────────────────

    public function testHorizonRruleInfinite(): void {
        $room = ['maxBookingHorizon' => 30];

        // RRULE without UNTIL or COUNT = infinite
        $vEvent = $this->buildVEvent(
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+1 day 1 hour'),
            'FREQ=WEEKLY',
        );

        $this->assertFalse($this->method->invoke($this->plugin, $room, $vEvent));
    }
}
