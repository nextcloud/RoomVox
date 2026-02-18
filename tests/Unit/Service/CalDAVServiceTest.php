<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\CalDAVService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CalDAVServiceTest extends TestCase {
    private CalDAVService $service;
    private CalDavBackend $calDavBackend;

    protected function setUp(): void {
        $this->calDavBackend = $this->createMock(CalDavBackend::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new CalDAVService($this->calDavBackend, $logger);
    }

    public function testEscapeIcsText(): void {
        $method = new \ReflectionMethod($this->service, 'escapeIcsText');

        $this->assertSame('Hello\\nWorld', $method->invoke($this->service, "Hello\nWorld"));
        $this->assertSame('Semi\\;colon', $method->invoke($this->service, 'Semi;colon'));
        $this->assertSame('Com\\,ma', $method->invoke($this->service, 'Com,ma'));
        $this->assertSame('Back\\\\slash', $method->invoke($this->service, 'Back\\slash'));
        $this->assertSame('Plain text', $method->invoke($this->service, 'Plain text'));
    }

    public function testBuildVAvailability(): void {
        $method = new \ReflectionMethod($this->service, 'buildVAvailability');

        $room = [
            'email' => 'room@example.com',
            'availabilityRules' => [
                'enabled' => true,
                'rules' => [
                    [
                        'days' => [1, 2, 3, 4, 5], // Mon-Fri
                        'startTime' => '09:00',
                        'endTime' => '17:00',
                    ],
                ],
            ],
        ];

        $result = $method->invoke($this->service, $room);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $result);
        $this->assertStringContainsString('BEGIN:VAVAILABILITY', $result);
        $this->assertStringContainsString('BEGIN:AVAILABLE', $result);
        $this->assertStringContainsString('ORGANIZER:mailto:room@example.com', $result);
        $this->assertStringContainsString('DTSTART;TZID=Europe/Amsterdam:20240101T090000', $result);
        $this->assertStringContainsString('DTEND;TZID=Europe/Amsterdam:20240101T170000', $result);
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR', $result);
        $this->assertStringContainsString('END:VAVAILABILITY', $result);
        $this->assertStringContainsString('END:VCALENDAR', $result);
    }

    public function testBuildVAvailabilityEmptyRules(): void {
        $method = new \ReflectionMethod($this->service, 'buildVAvailability');

        $room = [
            'email' => 'room@example.com',
            'availabilityRules' => ['enabled' => true, 'rules' => []],
        ];

        $result = $method->invoke($this->service, $room);

        $this->assertStringContainsString('BEGIN:VAVAILABILITY', $result);
        $this->assertStringNotContainsString('BEGIN:AVAILABLE', $result);
    }

    public function testBuildVAvailabilityMultipleRules(): void {
        $method = new \ReflectionMethod($this->service, 'buildVAvailability');

        $room = [
            'email' => '',
            'availabilityRules' => [
                'enabled' => true,
                'rules' => [
                    ['days' => [1, 2, 3, 4, 5], 'startTime' => '09:00', 'endTime' => '17:00'],
                    ['days' => [6], 'startTime' => '10:00', 'endTime' => '14:00'],
                ],
            ],
        ];

        $result = $method->invoke($this->service, $room);

        // Should contain 2 AVAILABLE blocks
        $this->assertSame(2, substr_count($result, 'BEGIN:AVAILABLE'));
        $this->assertStringContainsString('BYDAY=MO,TU,WE,TH,FR', $result);
        $this->assertStringContainsString('BYDAY=SA', $result);
        // No ORGANIZER when email is empty
        $this->assertStringNotContainsString('ORGANIZER', $result);
    }
}
