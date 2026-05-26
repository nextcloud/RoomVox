<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\BookingApiController;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Validates BookingApiController::destroy() per-occurrence (issue #13) routing:
 * - With recurrenceId on a recurring booking → deleteBookingOccurrence path.
 * - Without recurrenceId → deleteBooking (series) path.
 * - With recurrenceId on a NON-recurring booking → falls back to series delete.
 */
class BookingApiDestroyTest extends TestCase {
    private BookingApiController $controller;
    private CalDAVService $calDAVService;
    private RoomService $roomService;
    private PermissionService $permissionService;
    private MailService $mailService;
    private ExchangeSyncService $exchangeSyncService;
    private IRequest $request;
    private IUserSession $userSession;
    private IGroupManager $groupManager;

    private array $testRoom = [
        'id' => 'room1',
        'userId' => 'rb_room1',
        'name' => 'Conference Room',
        'email' => 'room1@example.com',
        'autoAccept' => true,
        'active' => true,
    ];

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->roomService = $this->createMock(RoomService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->mailService = $this->createMock(MailService::class);
        $this->exchangeSyncService = $this->createMock(ExchangeSyncService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->roomService->method('getRoom')->willReturn($this->testRoom);

        $this->controller = new BookingApiController(
            'roomvox',
            $this->request,
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userSession,
            $this->groupManager,
            $logger,
        );
    }

    private function recurringBooking(): array {
        return [
            'uid' => 'evt-1',
            'uri' => 'evt-1.ics',
            'calendarId' => 1,
            'isRecurring' => true,
            'summary' => 'Weekly Standup',
            'organizer' => 'alice',
            'organizerEmail' => 'alice@example.com',
            'roomEmail' => 'room1@example.com',
            'status' => 'CONFIRMED',
        ];
    }

    private function nonRecurringBooking(): array {
        return [
            'uid' => 'evt-2',
            'uri' => 'evt-2.ics',
            'calendarId' => 1,
            'isRecurring' => false,
            'summary' => 'One-off',
            'organizer' => 'alice',
            'organizerEmail' => 'alice@example.com',
            'roomEmail' => 'room1@example.com',
            'status' => 'CONFIRMED',
        ];
    }

    public function testDestroyOccurrenceCallsDeleteBookingOccurrence(): void {
        $this->calDAVService->method('getBookingByUid')->willReturn($this->recurringBooking());

        $this->calDAVService->expects($this->once())
            ->method('deleteBookingOccurrence')
            ->with('rb_room1', 'evt-1', '2026-06-10T09:00:00+02:00')
            ->willReturn(true);
        $this->calDAVService->expects($this->never())->method('deleteBooking');

        $this->exchangeSyncService->expects($this->once())
            ->method('deleteBookingFromExchange')
            ->with($this->testRoom, 'evt-1', '2026-06-10T09:00:00+02:00');

        $this->calDAVService->expects($this->once())
            ->method('updateOrganizerEventPartstat')
            ->with('alice@example.com', 'evt-1', 'DECLINED', 'room1@example.com', '2026-06-10T09:00:00+02:00');

        $this->mailService->expects($this->once())
            ->method('sendRespondCancelled')
            ->with($this->testRoom, $this->anything(), '2026-06-10T09:00:00+02:00');

        $response = $this->controller->destroy('room1', 'evt-1', '2026-06-10T09:00:00+02:00');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['status' => 'ok'], $response->getData());
    }

    public function testDestroyWithoutRecurrenceIdCallsSeriesDelete(): void {
        $this->calDAVService->method('getBookingByUid')->willReturn($this->recurringBooking());

        $this->calDAVService->expects($this->once())
            ->method('deleteBooking')
            ->with('rb_room1', 'evt-1')
            ->willReturn(true);
        $this->calDAVService->expects($this->never())->method('deleteBookingOccurrence');

        $this->exchangeSyncService->expects($this->once())
            ->method('deleteBookingFromExchange')
            ->with($this->testRoom, 'evt-1', null);

        $this->calDAVService->expects($this->once())
            ->method('updateOrganizerEventPartstat')
            ->with('alice@example.com', 'evt-1', 'DECLINED', 'room1@example.com', null);

        $this->mailService->expects($this->once())
            ->method('sendRespondCancelled')
            ->with($this->testRoom, $this->anything(), null);

        $response = $this->controller->destroy('room1', 'evt-1', null);

        $this->assertSame(200, $response->getStatus());
    }

    public function testDestroyRecurrenceIdOnNonRecurringFallsBackToSeries(): void {
        $this->calDAVService->method('getBookingByUid')->willReturn($this->nonRecurringBooking());

        $this->calDAVService->expects($this->once())
            ->method('deleteBooking')
            ->with('rb_room1', 'evt-2')
            ->willReturn(true);
        $this->calDAVService->expects($this->never())->method('deleteBookingOccurrence');

        $this->exchangeSyncService->expects($this->once())
            ->method('deleteBookingFromExchange')
            ->with($this->testRoom, 'evt-2', null);

        $response = $this->controller->destroy('room1', 'evt-2', '2026-06-10T09:00:00+02:00');

        $this->assertSame(200, $response->getStatus());
    }

    public function testDestroyReturns404WhenBookingMissing(): void {
        $this->calDAVService->method('getBookingByUid')->willReturn(null);

        $this->calDAVService->expects($this->never())->method('deleteBooking');
        $this->calDAVService->expects($this->never())->method('deleteBookingOccurrence');

        $response = $this->controller->destroy('room1', 'missing-uid', null);

        $this->assertSame(404, $response->getStatus());
    }

    public function testDestroyReturns403ForUnauthorizedUser(): void {
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->permissionService->method('canManage')->willReturn(false);

        // Booking owned by someone else
        $booking = $this->recurringBooking();
        $booking['organizer'] = 'someone-else';
        $this->calDAVService->method('getBookingByUid')->willReturn($booking);

        $logger = $this->createMock(LoggerInterface::class);
        $controller = new BookingApiController(
            'roomvox',
            $this->request,
            $this->roomService,
            $this->permissionService,
            $this->calDAVService,
            $this->mailService,
            $this->exchangeSyncService,
            $this->userSession,
            $this->groupManager,
            $logger,
        );

        $this->calDAVService->expects($this->never())->method('deleteBooking');
        $this->calDAVService->expects($this->never())->method('deleteBookingOccurrence');

        $response = $controller->destroy('room1', 'evt-1', '2026-06-10T09:00:00+02:00');

        $this->assertSame(403, $response->getStatus());
    }
}
