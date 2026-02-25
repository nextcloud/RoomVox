<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Dav;

use OCA\RoomVox\Dav\SchedulingPlugin;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the availability and booking rule checks in SchedulingPlugin.
 * These are private methods tested via reflection — they contain the core
 * business logic for booking validation.
 */
class SchedulingPluginTest extends TestCase {
    private SchedulingPlugin $plugin;

    protected function setUp(): void {
        // Create instance without constructor (it requires many Sabre/NC dependencies)
        $this->plugin = (new \ReflectionClass(SchedulingPlugin::class))
            ->newInstanceWithoutConstructor();
    }

    // ── bookingFitsRule ──────────────────────────────────────────

    public function testBookingFitsRuleWeekday(): void {
        $method = new \ReflectionMethod($this->plugin, 'bookingFitsRule');

        // Monday 09:00-10:00, rule allows Mon-Fri 08:00-18:00
        $start = new \DateTimeImmutable('2026-02-16 09:00:00'); // Monday
        $end = new \DateTimeImmutable('2026-02-16 10:00:00');
        $rule = ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'];

        $this->assertTrue($method->invoke($this->plugin, $start, $end, $rule));
    }

    public function testBookingFitsRuleWeekendRejected(): void {
        $method = new \ReflectionMethod($this->plugin, 'bookingFitsRule');

        // Saturday 09:00-10:00, rule only allows Mon-Fri
        $start = new \DateTimeImmutable('2026-02-21 09:00:00'); // Saturday
        $end = new \DateTimeImmutable('2026-02-21 10:00:00');
        $rule = ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'];

        $this->assertFalse($method->invoke($this->plugin, $start, $end, $rule));
    }

    public function testBookingFitsRuleOutsideHours(): void {
        $method = new \ReflectionMethod($this->plugin, 'bookingFitsRule');

        // Monday 07:00-08:00, but rule starts at 08:00
        $start = new \DateTimeImmutable('2026-02-16 07:00:00');
        $end = new \DateTimeImmutable('2026-02-16 08:00:00');
        $rule = ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'];

        $this->assertFalse($method->invoke($this->plugin, $start, $end, $rule));
    }

    public function testBookingFitsRuleEndAfterRuleEnd(): void {
        $method = new \ReflectionMethod($this->plugin, 'bookingFitsRule');

        // Monday 17:00-19:00, rule ends at 18:00
        $start = new \DateTimeImmutable('2026-02-16 17:00:00');
        $end = new \DateTimeImmutable('2026-02-16 19:00:00');
        $rule = ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'];

        $this->assertFalse($method->invoke($this->plugin, $start, $end, $rule));
    }

    public function testBookingFitsRuleEmptyDays(): void {
        $method = new \ReflectionMethod($this->plugin, 'bookingFitsRule');

        $start = new \DateTimeImmutable('2026-02-16 09:00:00');
        $end = new \DateTimeImmutable('2026-02-16 10:00:00');
        $rule = ['days' => [], 'startTime' => '08:00', 'endTime' => '18:00'];

        $this->assertFalse($method->invoke($this->plugin, $start, $end, $rule));
    }

    // ── isWithinAvailability ─────────────────────────────────────

    public function testIsWithinAvailabilityNoRules(): void {
        $method = new \ReflectionMethod($this->plugin, 'isWithinAvailability');

        $room = ['availabilityRules' => ['enabled' => false, 'rules' => []]];
        $start = new \DateTimeImmutable('2026-02-16 09:00:00');
        $end = new \DateTimeImmutable('2026-02-16 10:00:00');

        $this->assertTrue($method->invoke($this->plugin, $room, $start, $end));
    }

    public function testIsWithinAvailabilityAllowed(): void {
        $method = new \ReflectionMethod($this->plugin, 'isWithinAvailability');

        $room = [
            'availabilityRules' => [
                'enabled' => true,
                'rules' => [
                    ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'],
                ],
            ],
        ];
        $start = new \DateTimeImmutable('2026-02-16 09:00:00'); // Monday
        $end = new \DateTimeImmutable('2026-02-16 10:00:00');

        $this->assertTrue($method->invoke($this->plugin, $room, $start, $end));
    }

    public function testIsWithinAvailabilityRejected(): void {
        $method = new \ReflectionMethod($this->plugin, 'isWithinAvailability');

        $room = [
            'availabilityRules' => [
                'enabled' => true,
                'rules' => [
                    ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'],
                ],
            ],
        ];
        // Saturday booking
        $start = new \DateTimeImmutable('2026-02-21 09:00:00'); // Saturday
        $end = new \DateTimeImmutable('2026-02-21 10:00:00');

        $this->assertFalse($method->invoke($this->plugin, $room, $start, $end));
    }

    public function testIsWithinAvailabilityMultipleRules(): void {
        $method = new \ReflectionMethod($this->plugin, 'isWithinAvailability');

        $room = [
            'availabilityRules' => [
                'enabled' => true,
                'rules' => [
                    ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'],
                    ['days' => [6], 'startTime' => '10:00', 'endTime' => '14:00'],
                ],
            ],
        ];

        // Saturday 11:00-12:00 — fits second rule
        $start = new \DateTimeImmutable('2026-02-21 11:00:00');
        $end = new \DateTimeImmutable('2026-02-21 12:00:00');

        $this->assertTrue($method->invoke($this->plugin, $room, $start, $end));
    }
}
