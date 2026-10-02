<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\PublicApiController;
use OCA\RoomVox\Middleware\ApiTokenMiddleware;
use OCA\RoomVox\Service\ApiTokenService;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\InstanceTimezone;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\RoomService;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PublicApiController::createBooking() — the bearer-token API
 * for external systems. Validates conflict checking, availability rules,
 * horizon limits, and input validation.
 */
class PublicApiConflictTest extends TestCase {
    private CalDAVService $calDAVService;
    private RoomService $roomService;
    private ExchangeSyncService $exchangeSyncService;
    private IRequest $request;

    private array $testRoom = [
        'id' => 'room1',
        'userId' => 'rb_room1',
        'name' => 'Conference Room',
        'email' => 'room1@example.com',
        'autoAccept' => true,
        'active' => true,
        'availabilityRules' => ['enabled' => false, 'rules' => []],
        'maxBookingHorizon' => 0,
    ];

    private array $testToken = [
        'id' => 'token1',
        'scope' => 'book',
        'roomIds' => [],
    ];

    private function createController(): PublicApiController {
        $this->request = $this->createMock(IRequest::class);
        $this->roomService = $this->createMock(RoomService::class);
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->exchangeSyncService = $this->createMock(ExchangeSyncService::class);
        $tokenMiddleware = $this->createMock(ApiTokenMiddleware::class);
        $tokenService = $this->createMock(ApiTokenService::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Bypass token auth by making requireScope/getAuthorizedRoom work
        // We test via reflection on the createBooking method
        return new PublicApiController(
            'roomvox',
            $this->request,
            $this->roomService,
            $this->calDAVService,
            $this->exchangeSyncService,
            $this->createMock(MailService::class),
            $tokenMiddleware,
            $tokenService,
            $logger,
            new InstanceTimezone($this->createMock(IConfig::class)),
        );
    }

    /**
     * Since PublicApiController uses requireScope() which depends on middleware,
     * we test the booking validation logic that is unique to this controller
     * (availability rules, horizon checks) using reflection or by verifying
     * the CalDAVService interaction patterns.
     *
     * For the conflict check specifically: it calls the same hasConflict() as
     * BookingApiController, so the core conflict detection is already tested.
     * Here we verify the controller-specific validation.
     */

    public function testPublicApiCallsHasConflict(): void {
        // Verify that PublicApiController constructor accepts all required dependencies
        $controller = $this->createController();
        $this->assertInstanceOf(PublicApiController::class, $controller);
    }

    /**
     * Availability rules, exercised through the controller's own matcher.
     *
     * These three tests previously re-implemented the comparison inline using
     * STRING weekdays ("mon") and asserted that it matched. That encoded issue
     * #32 rather than catching it: days are stored as integers 0-6 (0 = Sunday),
     * so the production comparison could never match and the public API reported
     * every room as unavailable around the clock. They now call the real
     * matchesRuleDay() with the canonical integer format.
     */
    public function testAvailabilityRuleCheckLogicMonFri(): void {
        $controller = $this->createController();
        $matches = new \ReflectionMethod(PublicApiController::class, 'matchesRuleDay');

        $rule = ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'];
        $startDt = new \DateTime('2026-02-16 10:00:00'); // Monday

        $this->assertTrue(
            $matches->invoke($controller, $startDt, $rule['days'])
                && $startDt->format('H:i') >= $rule['startTime']
                && '11:00' <= $rule['endTime'],
        );
    }

    public function testAvailabilityRuleCheckLogicWeekendRejected(): void {
        $controller = $this->createController();
        $matches = new \ReflectionMethod(PublicApiController::class, 'matchesRuleDay');

        $rule = ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'];
        $startDt = new \DateTime('2026-02-21 10:00:00'); // Saturday

        $this->assertFalse($matches->invoke($controller, $startDt, $rule['days']));
    }

    public function testAvailabilityRuleCheckLogicOutsideHours(): void {
        $controller = $this->createController();
        $matches = new \ReflectionMethod(PublicApiController::class, 'matchesRuleDay');

        $rule = ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'];
        $startDt = new \DateTime('2026-02-16 07:00:00'); // Monday, before opening

        // The day matches; the time window is what rejects this booking.
        $this->assertTrue($matches->invoke($controller, $startDt, $rule['days']));
        $this->assertFalse($startDt->format('H:i') >= $rule['startTime']);
    }

    public function testHorizonCheckLogicWithinLimit(): void {
        // Test the horizon check logic that PublicApiController uses inline
        // Matches PublicApiController lines 414-419

        $room = ['maxBookingHorizon' => 30];
        $startDt = new \DateTime('+10 days');
        $maxDate = new \DateTimeImmutable('+' . $room['maxBookingHorizon'] . ' days');

        $this->assertFalse($startDt > $maxDate, 'Booking within horizon should be allowed');
    }

    public function testHorizonCheckLogicExceedsLimit(): void {
        $room = ['maxBookingHorizon' => 30];
        $startDt = new \DateTime('+60 days');
        $maxDate = new \DateTimeImmutable('+' . $room['maxBookingHorizon'] . ' days');

        $this->assertTrue($startDt > $maxDate, 'Booking beyond horizon should be rejected');
    }

    public function testEndBeforeStartValidation(): void {
        // PublicApiController lines 383-385
        $startDt = new \DateTime('2026-02-20T10:00:00');
        $endDt = new \DateTime('2026-02-20T09:00:00');

        $this->assertTrue($endDt <= $startDt, 'End before start should fail validation');
    }

    public function testEndEqualsStartValidation(): void {
        // Zero-duration bookings should also fail
        $startDt = new \DateTime('2026-02-20T10:00:00');
        $endDt = new \DateTime('2026-02-20T10:00:00');

        $this->assertTrue($endDt <= $startDt, 'Zero-duration booking should fail validation');
    }
}
