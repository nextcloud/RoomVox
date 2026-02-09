<?php

declare(strict_types=1);

namespace OCA\ResaVox\Controller;

use OCA\ResaVox\Service\CalDAVService;
use OCA\ResaVox\Service\MailService;
use OCA\ResaVox\Service\PermissionService;
use OCA\ResaVox\Service\RoomService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class BookingApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private RoomService $roomService,
        private PermissionService $permissionService,
        private CalDAVService $calDAVService,
        private MailService $mailService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get all bookings for a room
     */
    public function index(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        if (!$this->groupManager->isAdmin($userId) && !$this->permissionService->canManage($userId, $id)) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }

        $room = $this->roomService->getRoom($id);
        if ($room === null) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

        $from = $this->request->getParam('from');
        $to = $this->request->getParam('to');

        $bookings = $this->calDAVService->getBookings($room['userId'], $from, $to);

        return new JSONResponse($bookings);
    }

    /**
     * Respond to a booking (approve/decline)
     */
    public function respond(string $id, string $uid): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        if (!$this->groupManager->isAdmin($userId) && !$this->permissionService->canManage($userId, $id)) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }

        $room = $this->roomService->getRoom($id);
        if ($room === null) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

        $action = $this->request->getParam('action', '');
        if (!in_array($action, ['accept', 'decline'])) {
            return new JSONResponse(['error' => 'Invalid action. Use "accept" or "decline".'], 400);
        }

        $partstat = $action === 'accept' ? 'ACCEPTED' : 'DECLINED';

        try {
            $success = $this->calDAVService->updateBookingPartstat($room['userId'], $uid, $partstat);

            if (!$success) {
                return new JSONResponse(['error' => 'Booking not found'], 404);
            }

            $this->logger->info("Booking {$uid} in room {$id} {$action}ed by {$userId}");

            return new JSONResponse(['status' => 'ok', 'action' => $action]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to respond to booking {$uid}: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to process response'], 500);
        }
    }

    private function getCurrentUserId(): ?string {
        $user = $this->userSession->getUser();
        return $user?->getUID();
    }
}
