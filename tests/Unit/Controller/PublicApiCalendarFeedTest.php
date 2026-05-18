<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\PublicApiController;
use OCA\RoomVox\Middleware\ApiTokenMiddleware;
use OCA\RoomVox\Service\ApiTokenService;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\RoomService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PublicApiController::calendarFeed() — verifies that the iCal feed
 * passes through master VEVENTs with RRULE intact (issue #4). Exercises the
 * private helper methods directly via reflection since requireScope/
 * getAuthorizedRoom path needs a live token middleware.
 */
class PublicApiCalendarFeedTest extends TestCase {

    private function createController(): PublicApiController {
        $request = $this->createMock(IRequest::class);
        $roomService = $this->createMock(RoomService::class);
        $calDAVService = $this->createMock(CalDAVService::class);
        $exchangeSyncService = $this->createMock(ExchangeSyncService::class);
        $tokenMiddleware = $this->createMock(ApiTokenMiddleware::class);
        $tokenService = $this->createMock(ApiTokenService::class);
        $logger = $this->createMock(LoggerInterface::class);

        return new PublicApiController(
            'roomvox',
            $request,
            $roomService,
            $calDAVService,
            $exchangeSyncService,
            $tokenMiddleware,
            $tokenService,
            $logger,
        );
    }

    public function testExtractVEventsReturnsOneBlockPerVevent(): void {
        $controller = $this->createController();
        $method = new \ReflectionMethod($controller, 'extractVEvents');

        $ical = "BEGIN:VCALENDAR\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:one\r\n"
            . "DTSTART:20260424T220000Z\r\n"
            . "RRULE:FREQ=WEEKLY;BYDAY=FR\r\n"
            . "END:VEVENT\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:one\r\n"
            . "RECURRENCE-ID:20260501T220000Z\r\n"
            . "DTSTART:20260501T230000Z\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        /** @var string[] $events */
        $events = $method->invoke($controller, $ical);
        $this->assertCount(2, $events);
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;BYDAY=FR', $events[0]);
        $this->assertStringContainsString('RECURRENCE-ID:20260501T220000Z', $events[1]);
    }

    public function testExtractVEventsNormalizesLfToCrlf(): void {
        $controller = $this->createController();
        $method = new \ReflectionMethod($controller, 'extractVEvents');

        // Source uses bare LF — extraction should still work and yield CRLF output.
        $ical = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:lf\nRRULE:FREQ=DAILY\nEND:VEVENT\nEND:VCALENDAR\n";
        $events = $method->invoke($controller, $ical);

        $this->assertCount(1, $events);
        $this->assertStringContainsString("RRULE:FREQ=DAILY\r\n", $events[0]);
        $this->assertStringEndsWith("END:VEVENT\r\n", $events[0]);
    }

    public function testExtractVEventsReturnsEmptyForBlankInput(): void {
        $controller = $this->createController();
        $method = new \ReflectionMethod($controller, 'extractVEvents');
        $this->assertSame([], $method->invoke($controller, ''));
    }

    public function testRewriteVEventLocationReplacesExisting(): void {
        $controller = $this->createController();
        $method = new \ReflectionMethod($controller, 'rewriteVEventLocation');

        $vevent = "BEGIN:VEVENT\r\nUID:loc\r\nLOCATION:Old place\r\nEND:VEVENT\r\n";
        $result = $method->invoke($controller, $vevent, 'New place');

        $this->assertStringNotContainsString('Old place', $result);
        $this->assertStringContainsString('LOCATION:New place', $result);
    }

    public function testRewriteVEventLocationDropsFoldedContinuation(): void {
        $controller = $this->createController();
        $method = new \ReflectionMethod($controller, 'rewriteVEventLocation');

        // LOCATION line with a folded continuation (leading space on next line).
        $vevent = "BEGIN:VEVENT\r\nUID:loc\r\nLOCATION:Long address part 1\r\n  part 2\r\nEND:VEVENT\r\n";
        $result = $method->invoke($controller, $vevent, 'New place');

        $this->assertStringNotContainsString('part 1', $result);
        $this->assertStringNotContainsString('part 2', $result);
        $this->assertStringContainsString('LOCATION:New place', $result);
    }

    public function testRewriteVEventLocationOmitsLineWhenEmpty(): void {
        $controller = $this->createController();
        $method = new \ReflectionMethod($controller, 'rewriteVEventLocation');

        $vevent = "BEGIN:VEVENT\r\nUID:loc\r\nLOCATION:Something\r\nEND:VEVENT\r\n";
        $result = $method->invoke($controller, $vevent, '');

        $this->assertStringNotContainsString('LOCATION', $result);
    }

    public function testForceVEventStatusReplacesExisting(): void {
        $controller = $this->createController();
        $method = new \ReflectionMethod($controller, 'forceVEventStatus');

        $vevent = "BEGIN:VEVENT\r\nUID:s\r\nSTATUS:TENTATIVE\r\nEND:VEVENT\r\n";
        $result = $method->invoke($controller, $vevent, 'CONFIRMED');

        $this->assertStringNotContainsString('STATUS:TENTATIVE', $result);
        $this->assertStringContainsString('STATUS:CONFIRMED', $result);
    }

    public function testForceVEventStatusInsertsWhenMissing(): void {
        $controller = $this->createController();
        $method = new \ReflectionMethod($controller, 'forceVEventStatus');

        $vevent = "BEGIN:VEVENT\r\nUID:s\r\nEND:VEVENT\r\n";
        $result = $method->invoke($controller, $vevent, 'CONFIRMED');

        $this->assertStringContainsString("STATUS:CONFIRMED\r\nEND:VEVENT", $result);
    }
}
