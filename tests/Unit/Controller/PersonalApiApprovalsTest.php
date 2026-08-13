<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\PersonalApiController;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PersonalApiController::approvals() — the manager approval queue.
 *
 * Regression cover for issue #28: the queue listed every TENTATIVE booking ever
 * made, including meetings whose date had long passed, because getBookings()
 * was called without a date range. A manager cannot usefully approve a meeting
 * that is already over.
 */
class PersonalApiApprovalsTest extends TestCase {
    private const ROOM = ['id' => 'room1', 'name' => 'Room 1', 'userId' => 'rb_room1'];

    private CalDAVService $calDAVService;
    /** @var list<array{0: string, 1: ?string, 2: ?string}> */
    private array $getBookingsCalls = [];

    /**
     * @param list<array<string, mixed>> $bookings returned by the (mocked) CalDAV layer
     */
    private function makeController(array $bookings): PersonalApiController {
        $roomService = $this->createMock(RoomService::class);
        $roomService->method('getAllRooms')->willReturn(['room1' => self::ROOM]);

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('canManage')->willReturn(true);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('manager');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);

        // Record the arguments and apply the same end-before-$from filter the
        // real CalDAVService::getBookings() applies, so the controller is
        // tested against realistic behaviour rather than a passthrough.
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->calDAVService->method('getBookings')->willReturnCallback(
            function (string $roomUserId, ?string $from = null, ?string $to = null) use ($bookings) {
                $this->getBookingsCalls[] = [$roomUserId, $from, $to];
                if ($from === null) {
                    return $bookings;
                }
                $fromDt = new \DateTimeImmutable($from);
                return array_values(array_filter(
                    $bookings,
                    fn (array $b) => new \DateTimeImmutable($b['dtend']) >= $fromDt
                ));
            }
        );

        return new PersonalApiController(
            'roomvox',
            $this->createMock(IRequest::class),
            $roomService,
            $permissionService,
            $this->calDAVService,
            $userSession,
            $groupManager,
        );
    }

    /** @return list<array<string, mixed>> */
    private function bookings(): array {
        return [
            [
                'uid' => 'past',
                'summary' => 'Meeting held in July',
                'partstat' => 'TENTATIVE',
                'dtstart' => '2026-07-02T10:00:00+02:00',
                'dtend' => '2026-07-02T11:00:00+02:00',
            ],
            [
                'uid' => 'future',
                'summary' => 'Upcoming meeting',
                'partstat' => 'TENTATIVE',
                'dtstart' => '2099-01-01T10:00:00+01:00',
                'dtend' => '2099-01-01T11:00:00+01:00',
            ],
        ];
    }

    public function testApprovalsAsksForUpcomingBookingsOnly(): void {
        $controller = $this->makeController($this->bookings());

        $controller->approvals();

        $this->assertCount(1, $this->getBookingsCalls);
        [$roomUserId, $from] = $this->getBookingsCalls[0];
        $this->assertSame('rb_room1', $roomUserId);
        $this->assertNotNull($from, 'approvals() must pass a $from bound (#28)');

        // The bound must be "now", not some arbitrary date.
        $delta = abs((new \DateTimeImmutable($from))->getTimestamp() - time());
        $this->assertLessThan(60, $delta, '$from should be the current time');
    }

    public function testExpiredApprovalRequestIsNotListed(): void {
        $controller = $this->makeController($this->bookings());

        $data = $controller->approvals()->getData();
        $uids = array_column($data, 'uid');

        $this->assertNotContains('past', $uids, 'A meeting that already ended must drop out (#28)');
        $this->assertContains('future', $uids, 'Upcoming requests must still be listed');
    }

    /** A meeting that started but has not ended yet is still actionable. */
    public function testInProgressBookingIsStillListed(): void {
        $now = new \DateTimeImmutable('now');
        $controller = $this->makeController([[
            'uid' => 'running',
            'summary' => 'Started an hour ago',
            'partstat' => 'TENTATIVE',
            'dtstart' => $now->modify('-1 hour')->format('c'),
            'dtend' => $now->modify('+1 hour')->format('c'),
        ]]);

        $uids = array_column($controller->approvals()->getData(), 'uid');

        $this->assertContains('running', $uids);
    }

    /** Non-TENTATIVE bookings must keep being filtered out. */
    public function testAcceptedBookingIsNotAnApprovalRequest(): void {
        $controller = $this->makeController([[
            'uid' => 'accepted',
            'summary' => 'Already approved',
            'partstat' => 'ACCEPTED',
            'dtstart' => '2099-01-01T10:00:00+01:00',
            'dtend' => '2099-01-01T11:00:00+01:00',
        ]]);

        $this->assertSame([], $controller->approvals()->getData());
    }
}
