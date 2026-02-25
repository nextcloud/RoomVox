<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Dav;

use OCA\RoomVox\Dav\SchedulingPlugin;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ITip;
use Sabre\VObject\Property;

/**
 * Tests for SchedulingPlugin::handleScheduleRequest() — the full iTIP
 * booking flow from CalDAV clients (Apple Calendar, Thunderbird, etc.).
 *
 * Tests the complete chain: permission → availability → horizon → conflict → PARTSTAT → delivery.
 */
class SchedulingPluginRequestTest extends TestCase {
    private SchedulingPlugin $plugin;
    private RoomService $roomService;
    private PermissionService $permissionService;
    private CalDAVService $calDAVService;
    private MailService $mailService;
    private ExchangeSyncService $exchangeSyncService;
    private IUserManager $userManager;

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

    protected function setUp(): void {
        $this->roomService = $this->createMock(RoomService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->mailService = $this->createMock(MailService::class);
        $this->exchangeSyncService = $this->createMock(ExchangeSyncService::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Default: room is recognized and active
        $this->roomService->method('isRoomPrincipal')->willReturn(true);
        $this->roomService->method('getRoomIdByPrincipal')->willReturn('room1');
        $this->roomService->method('getRoom')->willReturn($this->testRoom);

        // Default: no permissions configured (anyone can book)
        $this->permissionService->method('getPermissions')->willReturn([
            'viewers' => [], 'bookers' => [], 'managers' => [],
        ]);

        // Default: no conflicts
        $this->calDAVService->method('hasConflict')->willReturn(false);
        $this->calDAVService->method('deliverToRoomCalendar')->willReturn(true);

        $this->plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );
    }

    /**
     * Build an iTIP REQUEST message with a VEVENT for testing.
     */
    private function buildRequestMessage(
        string $sender = 'principals/users/testuser',
        string $uid = 'test-booking-uid',
        ?\DateTimeInterface $start = null,
        ?\DateTimeInterface $end = null,
    ): ITip\Message {
        $start ??= new \DateTimeImmutable('2026-02-20 10:00:00');
        $end ??= new \DateTimeImmutable('2026-02-20 11:00:00');

        $vEvent = new VEvent();
        $vEvent->DTSTART = new Property($start);
        $vEvent->DTEND = new Property($end);
        $vEvent->UID = new Property($uid);
        $vEvent->SUMMARY = new Property('Test Booking');

        $vCalendar = new VCalendar();
        $vCalendar->VEVENT = $vEvent;

        $message = new ITip\Message();
        $message->method = 'REQUEST';
        $message->sender = $sender;
        $message->recipient = 'principals/users/rb_room1';
        $message->message = $vCalendar;

        return $message;
    }

    // ── Successful bookings ────────────────────────────────────────

    public function testRequestAccepted(): void {
        $message = $this->buildRequestMessage();

        $result = $this->plugin->handleScheduleRequest($message);

        $this->assertFalse($result); // false = stop Sabre propagation
        $this->assertSame('1.2', $message->scheduleStatus);
    }

    public function testRequestTentative(): void {
        // Room requires manual approval
        $room = array_merge($this->testRoom, ['autoAccept' => false]);
        $this->roomService = $this->createMock(RoomService::class);
        $this->roomService->method('isRoomPrincipal')->willReturn(true);
        $this->roomService->method('getRoomIdByPrincipal')->willReturn('room1');
        $this->roomService->method('getRoom')->willReturn($room);

        $this->permissionService = $this->createMock(PermissionService::class);
        $this->permissionService->method('getPermissions')->willReturn([
            'viewers' => [], 'bookers' => [], 'managers' => [],
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage();
        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('1.2', $message->scheduleStatus);
    }

    // ── Conflict rejection ─────────────────────────────────────────

    public function testRequestConflict(): void {
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->calDAVService->method('hasConflict')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage();
        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('3.0', $message->scheduleStatus);
    }

    // ── Permission rejections ──────────────────────────────────────

    public function testRequestNoPermission(): void {
        // Permissions configured: bookers list exists
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->permissionService->method('getPermissions')->willReturn([
            'viewers' => [],
            'bookers' => [['type' => 'user', 'id' => 'otheruser']],
            'managers' => [],
        ]);
        $this->permissionService->method('canBook')->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage('principals/users/testuser');
        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('3.7', $message->scheduleStatus);
    }

    public function testRequestUnknownSender(): void {
        // Permissions are configured
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->permissionService->method('getPermissions')->willReturn([
            'viewers' => [],
            'bookers' => [['type' => 'user', 'id' => 'someuser']],
            'managers' => [],
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        // Send from mailto: that doesn't resolve to a user
        $this->userManager->method('getByEmail')->willReturn([]);

        $message = $this->buildRequestMessage('mailto:unknown@example.com');
        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('3.7', $message->scheduleStatus);
    }

    public function testRequestNoPermissionsConfigured(): void {
        // No permissions = anyone can book
        $this->permissionService->method('getPermissions')->willReturn([
            'viewers' => [], 'bookers' => [], 'managers' => [],
        ]);

        $message = $this->buildRequestMessage();
        $result = $this->plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('1.2', $message->scheduleStatus);
    }

    // ── Availability rejection ─────────────────────────────────────

    public function testRequestOutsideAvailability(): void {
        $room = array_merge($this->testRoom, [
            'availabilityRules' => [
                'enabled' => true,
                'rules' => [
                    ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'],
                ],
            ],
        ]);
        $this->roomService = $this->createMock(RoomService::class);
        $this->roomService->method('isRoomPrincipal')->willReturn(true);
        $this->roomService->method('getRoomIdByPrincipal')->willReturn('room1');
        $this->roomService->method('getRoom')->willReturn($room);

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        // Saturday booking — outside Mon-Fri availability
        $message = $this->buildRequestMessage(
            'principals/users/testuser',
            'weekend-booking',
            new \DateTimeImmutable('2026-02-21 10:00:00'), // Saturday
            new \DateTimeImmutable('2026-02-21 11:00:00'),
        );

        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('3.7', $message->scheduleStatus);
    }

    // ── Horizon rejection ──────────────────────────────────────────

    public function testRequestBeyondHorizon(): void {
        $room = array_merge($this->testRoom, ['maxBookingHorizon' => 7]);
        $this->roomService = $this->createMock(RoomService::class);
        $this->roomService->method('isRoomPrincipal')->willReturn(true);
        $this->roomService->method('getRoomIdByPrincipal')->willReturn('room1');
        $this->roomService->method('getRoom')->willReturn($room);

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        // Booking 60 days from now — exceeds 7-day horizon
        $futureStart = new \DateTimeImmutable('+60 days 10:00:00');
        $futureEnd = new \DateTimeImmutable('+60 days 11:00:00');

        $message = $this->buildRequestMessage(
            'principals/users/testuser',
            'future-booking',
            $futureStart,
            $futureEnd,
        );

        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('3.7', $message->scheduleStatus);
    }

    // ── Delivery failure ───────────────────────────────────────────

    public function testRequestDeliveryFailure(): void {
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->calDAVService->method('hasConflict')->willReturn(false);
        $this->calDAVService->method('deliverToRoomCalendar')->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage();
        $result = $plugin->handleScheduleRequest($message);

        $this->assertFalse($result);
        $this->assertSame('5.0', $message->scheduleStatus);
    }

    // ── Cancel ─────────────────────────────────────────────────────

    public function testCancelRemovesFromCalendar(): void {
        $this->calDAVService->expects($this->once())
            ->method('deleteFromRoomCalendar');

        $message = $this->buildRequestMessage();
        $message->method = 'CANCEL';

        $this->plugin->handleScheduleRequest($message);
    }

    // ── Email notifications ────────────────────────────────────────

    public function testRequestSendsAcceptEmail(): void {
        $this->mailService->expects($this->once())
            ->method('sendAccepted');

        $message = $this->buildRequestMessage();
        $this->plugin->handleScheduleRequest($message);
    }

    public function testRequestSendsManagerNotification(): void {
        $room = array_merge($this->testRoom, ['autoAccept' => false]);
        $this->roomService = $this->createMock(RoomService::class);
        $this->roomService->method('isRoomPrincipal')->willReturn(true);
        $this->roomService->method('getRoomIdByPrincipal')->willReturn('room1');
        $this->roomService->method('getRoom')->willReturn($room);

        $this->mailService = $this->createMock(MailService::class);
        $this->mailService->expects($this->once())
            ->method('notifyManagers');

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage();
        $plugin->handleScheduleRequest($message);
    }

    public function testRequestSendsConflictEmail(): void {
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->calDAVService->method('hasConflict')->willReturn(true);

        $this->mailService = $this->createMock(MailService::class);
        $this->mailService->expects($this->once())
            ->method('sendConflict');

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage();
        $plugin->handleScheduleRequest($message);
    }

    // ── Exchange push fail-safe ────────────────────────────────────

    public function testRequestExchangePushFailSafe(): void {
        $this->exchangeSyncService = $this->createMock(ExchangeSyncService::class);
        $this->exchangeSyncService->method('pushBookingToExchange')
            ->willThrowException(new \RuntimeException('Exchange unavailable'));

        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new SchedulingPlugin(
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userManager,
            $logger,
        );

        $message = $this->buildRequestMessage();
        $result = $plugin->handleScheduleRequest($message);

        // Booking still succeeds despite Exchange failure
        $this->assertFalse($result);
        $this->assertSame('1.2', $message->scheduleStatus);
    }
}
