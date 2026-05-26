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
 * Tests for PublicApiController::createBooking() focused on the post-create
 * dispatch: Exchange push, manager-approval mail (issue #14) and the
 * external-organizer pass-through (issue #14 bug B follow-through — the
 * controller must hand the raw organizer to the CalDAV layer; the @localhost
 * suffix is fixed in CalDAVService itself).
 */
class PublicApiCreateTest extends TestCase {
    private CalDAVService $calDAVService;
    private RoomService $roomService;
    private ExchangeSyncService $exchangeSyncService;
    private MailService $mailService;
    private ApiTokenMiddleware $tokenMiddleware;
    private ApiTokenService $tokenService;
    private IRequest $request;
    private PublicApiController $controller;

    private array $autoAcceptRoom = [
        'id' => 'room1',
        'userId' => 'rb_room1',
        'name' => 'Conference Room',
        'email' => 'room1@example.com',
        'autoAccept' => true,
        'active' => true,
        'availabilityRules' => ['enabled' => false, 'rules' => []],
        'maxBookingHorizon' => 0,
    ];

    private array $pendingRoom = [
        'id' => 'room2',
        'userId' => 'rb_room2',
        'name' => 'Boardroom',
        'email' => 'room2@example.com',
        'autoAccept' => false,
        'active' => true,
        'availabilityRules' => ['enabled' => false, 'rules' => []],
        'maxBookingHorizon' => 0,
    ];

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->roomService = $this->createMock(RoomService::class);
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->exchangeSyncService = $this->createMock(ExchangeSyncService::class);
        $this->mailService = $this->createMock(MailService::class);
        $this->tokenMiddleware = $this->createMock(ApiTokenMiddleware::class);
        $this->tokenService = $this->createMock(ApiTokenService::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Token middleware: always return a valid 'book'-scoped token.
        $this->tokenMiddleware->method('getValidatedToken')->willReturn([
            'id' => 'tok1',
            'scope' => 'book',
            'roomIds' => [],
        ]);
        $this->tokenService->method('hasRoomAccess')->willReturn(true);

        $this->controller = new PublicApiController(
            'roomvox',
            $this->request,
            $this->roomService,
            $this->calDAVService,
            $this->exchangeSyncService,
            $this->mailService,
            $this->tokenMiddleware,
            $this->tokenService,
            $logger,
        );
    }

    private function setRequestParams(array $params): void {
        $this->request->method('getParam')->willReturnCallback(
            fn(string $key, $default = '') => $params[$key] ?? $default
        );
    }

    public function testCreateBookingAutoAcceptDoesNotNotifyManagers(): void {
        $this->roomService->method('getRoom')->willReturn($this->autoAcceptRoom);
        $this->calDAVService->method('hasConflict')->willReturn(false);
        $this->calDAVService->method('createBooking')->willReturn('uid-auto');

        $this->setRequestParams([
            'title' => 'Sync',
            'start' => '2026-06-01T10:00:00',
            'end' => '2026-06-01T11:00:00',
            'organizer' => 'extern@example.com',
        ]);

        $this->mailService->expects($this->never())->method('notifyManagersForBooking');

        $response = $this->controller->createBooking('room1');

        $this->assertSame(201, $response->getStatus());
        $this->assertSame('accepted', $response->getData()['status']);
    }

    public function testCreateBookingNonAutoAcceptNotifiesManagers(): void {
        $this->roomService->method('getRoom')->willReturn($this->pendingRoom);
        $this->calDAVService->method('hasConflict')->willReturn(false);
        $this->calDAVService->method('createBooking')->willReturn('uid-pending');
        $this->calDAVService->method('resolveOrganizerIdentity')->willReturn([
            'email' => 'extern@example.com',
            'cn' => null,
        ]);

        $this->setRequestParams([
            'title' => 'External Sync',
            'start' => '2026-06-01T10:00:00',
            'end' => '2026-06-01T11:00:00',
            'organizer' => 'extern@example.com',
        ]);

        $this->mailService->expects($this->once())
            ->method('notifyManagersForBooking')
            ->with(
                $this->pendingRoom,
                $this->callback(function (array $data): bool {
                    return ($data['uid'] ?? null) === 'uid-pending'
                        && ($data['summary'] ?? null) === 'External Sync'
                        && ($data['organizerEmail'] ?? null) === 'extern@example.com';
                }),
            );

        $response = $this->controller->createBooking('room2');

        $this->assertSame(201, $response->getStatus());
        $this->assertSame('pending', $response->getData()['status']);
    }

    public function testCreateBookingPassesExternalOrganizerThroughVerbatim(): void {
        // The @localhost fix lives in CalDAVService; the controller must hand
        // the raw organizer string in without mangling it.
        $this->roomService->method('getRoom')->willReturn($this->autoAcceptRoom);
        $this->calDAVService->method('hasConflict')->willReturn(false);
        $this->calDAVService->method('createBooking')
            ->with(
                'rb_room1',
                $this->callback(fn(array $data): bool => ($data['organizer'] ?? null) === 'extern@example.com'),
            )
            ->willReturn('uid-pass');

        $this->setRequestParams([
            'title' => 'Pass-through',
            'start' => '2026-06-01T10:00:00',
            'end' => '2026-06-01T11:00:00',
            'organizer' => 'extern@example.com',
        ]);

        $response = $this->controller->createBooking('room1');

        $this->assertSame(201, $response->getStatus());
    }
}
