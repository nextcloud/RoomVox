<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
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
     * Create a new booking in a room's calendar
     *
     * @NoAdminRequired
     */
    public function create(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        // Users need at least "book" permission to create bookings
        if (!$this->groupManager->isAdmin($userId) && !$this->permissionService->canBook($userId, $id)) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }

        $room = $this->roomService->getRoom($id);
        if ($room === null) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

        $summary = $this->request->getParam('summary', '');
        $start = $this->request->getParam('start', '');
        $end = $this->request->getParam('end', '');
        $description = $this->request->getParam('description', '');

        if (empty($summary)) {
            return new JSONResponse(['error' => 'Summary is required'], 400);
        }
        if (empty($start) || empty($end)) {
            return new JSONResponse(['error' => 'Start and end times are required'], 400);
        }

        try {
            $startDt = new \DateTime($start);
            $endDt = new \DateTime($end);

            // Check for conflicts
            if ($this->calDAVService->hasConflict($room['userId'], $startDt, $endDt)) {
                return new JSONResponse(['error' => 'Time slot conflicts with existing booking'], 409);
            }

            // Create the booking
            $uid = $this->calDAVService->createBooking($room['userId'], [
                'summary' => $summary,
                'start' => $startDt,
                'end' => $endDt,
                'description' => $description,
                'organizer' => $userId,
                'roomEmail' => $room['email'] ?? '',
                'autoAccept' => $room['autoAccept'] ?? false,
            ]);

            $this->logger->info("Booking {$uid} created in room {$id} by {$userId}");

            return new JSONResponse(['status' => 'ok', 'uid' => $uid], 201);
        } catch (\Exception $e) {
            $this->logger->error("Failed to create booking in room {$id}: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to create booking: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update a booking's times (reschedule)
     *
     * @NoAdminRequired
     */
    public function update(string $id, string $uid): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $room = $this->roomService->getRoom($id);
        if ($room === null) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

        // Check if user is admin, manager, or owns this booking
        $isAdmin = $this->groupManager->isAdmin($userId);
        $canManage = $this->permissionService->canManage($userId, $id);
        $existingBooking = $this->calDAVService->getBookingByUid($room['userId'], $uid);

        if ($existingBooking === null) {
            return new JSONResponse(['error' => 'Booking not found'], 404);
        }

        $isOwner = ($existingBooking['organizer'] ?? '') === $userId;

        if (!$isAdmin && !$canManage && !$isOwner) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }

        $start = $this->request->getParam('start', '');
        $end = $this->request->getParam('end', '');
        $newRoomId = $this->request->getParam('roomId');

        if (empty($start) || empty($end)) {
            return new JSONResponse(['error' => 'Start and end times are required'], 400);
        }

        try {
            $startDt = new \DateTime($start);
            $endDt = new \DateTime($end);

            // If moving to a different room
            if ($newRoomId && $newRoomId !== $id) {
                $newRoom = $this->roomService->getRoom($newRoomId);
                if ($newRoom === null) {
                    return new JSONResponse(['error' => 'Target room not found'], 404);
                }

                // Check permission for new room (need at least book permission)
                if (!$isAdmin && !$this->permissionService->canBook($userId, $newRoomId)) {
                    return new JSONResponse(['error' => 'No permission to move to target room'], 403);
                }

                // Check for conflicts in new room
                if ($this->calDAVService->hasConflict($newRoom['userId'], $startDt, $endDt)) {
                    return new JSONResponse(['error' => 'Time slot conflicts with existing booking in target room'], 409);
                }

                // Move booking: delete from old room and create in new room
                $existingBooking = $this->calDAVService->getBookingByUid($room['userId'], $uid);
                if ($existingBooking === null) {
                    return new JSONResponse(['error' => 'Booking not found'], 404);
                }

                // Delete from old room
                $this->calDAVService->deleteBooking($room['userId'], $uid);

                // Create in new room
                $newUid = $this->calDAVService->createBooking($newRoom['userId'], [
                    'summary' => $existingBooking['summary'],
                    'start' => $startDt,
                    'end' => $endDt,
                    'description' => $existingBooking['description'] ?? '',
                    'organizer' => $existingBooking['organizer'] ?? $userId,
                    'roomEmail' => $newRoom['email'] ?? '',
                    'autoAccept' => $newRoom['autoAccept'] ?? false,
                ]);

                $this->logger->info("Booking {$uid} moved from room {$id} to {$newRoomId} (new uid: {$newUid}) by {$userId}");

                return new JSONResponse(['status' => 'ok', 'uid' => $newUid, 'moved' => true]);
            }

            // Check for conflicts (excluding this booking)
            if ($this->calDAVService->hasConflict($room['userId'], $startDt, $endDt, $uid)) {
                return new JSONResponse(['error' => 'Time slot conflicts with existing booking'], 409);
            }

            // Update booking times
            $success = $this->calDAVService->updateBookingTimes($room['userId'], $uid, $startDt, $endDt);

            if (!$success) {
                return new JSONResponse(['error' => 'Booking not found'], 404);
            }

            $this->logger->info("Booking {$uid} in room {$id} rescheduled by {$userId}");

            return new JSONResponse(['status' => 'ok']);
        } catch (\Exception $e) {
            $this->logger->error("Failed to update booking {$uid}: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to update booking: ' . $e->getMessage()], 500);
        }
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

    /**
     * Delete a booking (admin/manager or owner)
     *
     * @NoAdminRequired
     */
    public function destroy(string $id, string $uid): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $room = $this->roomService->getRoom($id);
        if ($room === null) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

        // Check if user is admin, manager, or owns this booking
        $isAdmin = $this->groupManager->isAdmin($userId);
        $canManage = $this->permissionService->canManage($userId, $id);
        $existingBooking = $this->calDAVService->getBookingByUid($room['userId'], $uid);

        if ($existingBooking === null) {
            return new JSONResponse(['error' => 'Booking not found'], 404);
        }

        $isOwner = ($existingBooking['organizer'] ?? '') === $userId;

        if (!$isAdmin && !$canManage && !$isOwner) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }

        try {
            $success = $this->calDAVService->deleteBooking($room['userId'], $uid);

            if (!$success) {
                return new JSONResponse(['error' => 'Booking not found'], 404);
            }

            $this->logger->info("Booking {$uid} in room {$id} deleted by {$userId}");

            return new JSONResponse(['status' => 'ok']);
        } catch (\Exception $e) {
            $this->logger->error("Failed to delete booking {$uid}: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to delete booking'], 500);
        }
    }

    private function getCurrentUserId(): ?string {
        $user = $this->userSession->getUser();
        return $user?->getUID();
    }
}
