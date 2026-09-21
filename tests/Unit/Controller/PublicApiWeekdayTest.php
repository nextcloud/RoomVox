<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\PublicApiController;
use OCA\RoomVox\Middleware\ApiTokenMiddleware;
use OCA\RoomVox\Service\ApiTokenService;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\RoomService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Issue #32 — PublicApiController compares the weekday as a string against
 * availabilityRules days stored as integers, so no rule can ever match.
 *
 * The canonical format is integers 0-6 with 0 = Sunday: that is what the admin
 * UI writes (src/views/RoomEditor.vue:517-523) and what SchedulingPlugin reads
 * via format('w') (lib/Dav/SchedulingPlugin.php:817). PublicApiController uses
 * strtolower(format('D')) — "mon" — in four places: lines 76, 190 and 397.
 *
 * Consequence: for any room with availability rules enabled the public API
 * treats every moment as outside the allowed hours, so roomStatus() reports
 * 'unavailable' around the clock. The CalDAV/iTIP path is unaffected, which is
 * why this is easy to miss.
 *
 * This bug shares the availability block in roomStatus() with issue #44, so the
 * two are fixed together.
 *
 * NOTE ON AN EXISTING TEST: PublicApiConflictTest::testAvailabilityRuleCheckLogicMonFri()
 * does not call the controller. It re-implements the comparison inline with
 * STRING days and asserts that it matches, so it encodes the bug instead of
 * catching it (and its callCreateBooking() helper returns a hardcoded 200 and is
 * never used). That test needs correcting alongside the fix.
 */
class PublicApiWeekdayTest extends TestCase {
    private IRequest $request;
    private RoomService $roomService;
    private CalDAVService $calDAVService;

    /** Weekdays 08:00-18:00, in the canonical integer format the admin UI writes. */
    private const WEEKDAYS_0800_1800 = [
        'enabled' => true,
        'rules' => [
            ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'],
        ],
    ];

    private function createController(array $availabilityRules): PublicApiController {
        $this->request = $this->createMock(IRequest::class);
        $this->roomService = $this->createMock(RoomService::class);
        $this->calDAVService = $this->createMock(CalDAVService::class);

        $tokenMiddleware = $this->createMock(ApiTokenMiddleware::class);
        $tokenMiddleware->method('getValidatedToken')
            ->willReturn(['id' => 'token1', 'scope' => 'read', 'roomIds' => []]);
        $tokenService = $this->createMock(ApiTokenService::class);
        $tokenService->method('hasRoomAccess')->willReturn(true);

        $this->roomService->method('getRoom')->willReturn([
            'id' => 'room1',
            'userId' => 'rb_room1',
            'name' => 'Conference Room',
            'email' => 'room1@roomvox.local',
            'autoAccept' => true,
            'active' => true,
            'availabilityRules' => $availabilityRules,
            'maxBookingHorizon' => 0,
        ]);
        $this->roomService->method('buildRoomLocation')->willReturn('');

        // Calendar present and empty, so availability rules decide the status.
        $this->calDAVService->method('getBookings')->willReturn([]);
        $this->calDAVService->method('getRoomCalendarId')->willReturn(7);

        return new PublicApiController(
            'roomvox',
            $this->request,
            $this->roomService,
            $this->calDAVService,
            $this->createMock(ExchangeSyncService::class),
            $this->createMock(MailService::class),
            $tokenMiddleware,
            $tokenService,
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * Inside the configured window the room must not be 'unavailable'.
     *
     * roomStatus() reads "now", so this asserts the real current weekday: on a
     * weekday within 08:00-18:00 the room is open, and outside that window the
     * assertion is skipped rather than made meaningless.
     */
    public function testWeekdayRuleMatchesInsideTheWindow(): void {
        $now = new \DateTimeImmutable();
        $dayNumber = (int)$now->format('w');
        $time = $now->format('H:i');

        if ($dayNumber < 1 || $dayNumber > 5 || $time < '08:00' || $time > '18:00') {
            $this->markTestSkipped(
                'Runs only inside the Mon-Fri 08:00-18:00 window under test; now is '
                . $now->format('D H:i') . '.',
            );
        }

        $controller = $this->createController(self::WEEKDAYS_0800_1800);
        $data = $controller->roomStatus('room1')->getData();

        $this->assertNotSame(
            'unavailable',
            $data['status'],
            'A weekday rule stored as integers 1-5 must match a weekday — the controller compares '
            . 'format("D") ("mon") against [1,2,3,4,5], which can never be equal.',
        );
        $this->assertSame('free', $data['status']);
    }

    /**
     * Control case: rules that cover every day must never report 'unavailable'.
     *
     * A Sunday-is-unavailable test would pass today for the wrong reason — with
     * the bug every day reports 'unavailable', so it could not tell a fix from
     * the defect. Using an all-week rule inverts that: it must pass whatever the
     * wall clock says, and it fails today. It also pins the other direction, so
     * the fix cannot be to stop evaluating rules altogether.
     */
    public function testRuleCoveringEveryDayIsNeverUnavailable(): void {
        $controller = $this->createController([
            'enabled' => true,
            'rules' => [
                ['days' => [0, 1, 2, 3, 4, 5, 6], 'startTime' => '00:00', 'endTime' => '23:59'],
            ],
        ]);

        $data = $controller->roomStatus('room1')->getData();

        $this->assertNotSame(
            'unavailable',
            $data['status'],
            'A rule covering all seven days and the whole day can never place "now" outside the window.',
        );
    }

    /**
     * Weekday matching, asserted independently of the wall clock.
     *
     * The two tests above are skipped outside their window, so this one always
     * runs: it drives the matcher directly for a known instant and must agree
     * with how SchedulingPlugin::bookingFitsRule() reads the same rules.
     */
    public function testWeekdayMatcherAgreesWithTheCalDavPath(): void {
        $matches = new \ReflectionMethod(PublicApiController::class, 'matchesRuleDay');
        $controller = $this->createController(self::WEEKDAYS_0800_1800);

        $monday = new \DateTimeImmutable('2026-02-16 10:00:00');
        $sunday = new \DateTimeImmutable('2026-02-15 10:00:00');
        $allowedDays = [1, 2, 3, 4, 5];

        // SchedulingPlugin::bookingFitsRule() derives the day this way.
        $this->assertContains((int)$monday->format('w'), $allowedDays);

        $this->assertTrue(
            $matches->invoke($controller, $monday, $allowedDays),
            'A Monday must match a rule stored as [1,2,3,4,5].',
        );
        $this->assertFalse(
            $matches->invoke($controller, $sunday, $allowedDays),
            'A Sunday must not match a weekdays-only rule.',
        );
    }

    /**
     * Days persisted as strings must match too.
     *
     * The API stores availabilityRules verbatim from the request, so a REST
     * client can have written ["1","2"] rather than [1,2]. Those denote the same
     * weekdays, so a strict comparison would silently reject them — the same
     * class of type mismatch as #32 itself.
     */
    public function testDaysStoredAsStringsStillMatch(): void {
        $matches = new \ReflectionMethod(PublicApiController::class, 'matchesRuleDay');
        $controller = $this->createController(self::WEEKDAYS_0800_1800);

        $monday = new \DateTimeImmutable('2026-02-16 10:00:00');

        $this->assertTrue($matches->invoke($controller, $monday, ['1', '2', '3', '4', '5']));
        $this->assertFalse($matches->invoke($controller, $monday, ['0', '6']));

        // Junk must not match anything rather than coerce to 0 (= Sunday).
        $this->assertFalse($matches->invoke($controller, $monday, ['mon']));
        $this->assertFalse($matches->invoke($controller, new \DateTimeImmutable('2026-02-15'), ['sun']));
    }
}
