<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
        private ExchangeSyncService $exchangeSyncService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get all bookings for a room
     */
    #[NoAdminRequired]
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
     */
    #[NoAdminRequired]
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

            // Check for conflicts (local + Exchange)
            if ($this->calDAVService->hasConflict($room['userId'], $startDt, $endDt, null, $room)) {
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

            // Push to Exchange (synchronous, fail-safe)
            try {
                $this->exchangeSyncService->pushBookingToExchange($room, $uid, [
                    'summary' => $summary,
                    'start' => $startDt,
                    'end' => $endDt,
                    'description' => $description,
                    'organizer' => $userId,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error("Exchange push failed (non-blocking): " . $e->getMessage());
            }

            $this->notifyManagersIfPending($room, $uid, $summary, $userId, $startDt, $endDt);

            return new JSONResponse(['status' => 'ok', 'uid' => $uid], 201);
        } catch (\Exception $e) {
            $this->logger->error("Failed to create booking in room {$id}: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to create booking: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update a booking's times (reschedule)
     */
    #[NoAdminRequired]
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

                // Check for conflicts in new room (local + Exchange)
                if ($this->calDAVService->hasConflict($newRoom['userId'], $startDt, $endDt, null, $newRoom)) {
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

                $this->notifyManagersIfPending(
                    $newRoom,
                    $newUid,
                    $existingBooking['summary'] ?? 'Booking',
                    $existingBooking['organizer'] ?? $userId,
                    $startDt,
                    $endDt,
                );

                return new JSONResponse(['status' => 'ok', 'uid' => $newUid, 'moved' => true]);
            }

            // Check for conflicts (excluding this booking, local + Exchange)
            if ($this->calDAVService->hasConflict($room['userId'], $startDt, $endDt, $uid, $room)) {
                return new JSONResponse(['error' => 'Time slot conflicts with existing booking'], 409);
            }

            // Update booking times
            $success = $this->calDAVService->updateBookingTimes($room['userId'], $uid, $startDt, $endDt);

            if (!$success) {
                return new JSONResponse(['error' => 'Booking not found'], 404);
            }

            $this->logger->info("Booking {$uid} in room {$id} rescheduled by {$userId}");

            // Push update to Exchange
            try {
                $this->exchangeSyncService->updateBookingOnExchange($room, $uid, [
                    'summary' => $existingBooking['summary'] ?? 'Booking',
                    'start' => $startDt,
                    'end' => $endDt,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error("Exchange update failed (non-blocking): " . $e->getMessage());
            }

            return new JSONResponse(['status' => 'ok']);
        } catch (\Exception $e) {
            $this->logger->error("Failed to update booking {$uid}: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to update booking: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Respond to a booking (approve/decline)
     */
    #[NoAdminRequired]
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
            $bookingData = $this->calDAVService->updateBookingPartstat($room['userId'], $uid, $partstat);

            if ($bookingData === null) {
                return new JSONResponse(['error' => 'Booking not found'], 404);
            }

            $this->logger->info("Booking {$uid} in room {$id} {$action}ed by {$userId}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to respond to booking {$uid}: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to process response'], 500);
        }

        // Propagate PARTSTAT to organizer's calendar so their event reflects the response
        try {
            if (!empty($bookingData['organizerEmail']) && !empty($bookingData['roomEmail'])) {
                $this->calDAVService->updateOrganizerEventPartstat(
                    $bookingData['organizerEmail'],
                    $uid,
                    $partstat,
                    $bookingData['roomEmail'],
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error("Failed to update organizer calendar for booking {$uid}: " . $e->getMessage());
        }

        // Send email notification to organizer
        try {
            if ($action === 'accept') {
                $this->mailService->sendRespondAccepted($room, $bookingData);
            } else {
                $this->mailService->sendRespondDeclined($room, $bookingData);
            }
        } catch (\Throwable $e) {
            $this->logger->error("Failed to send response email for booking {$uid}: " . $e->getMessage());
        }

        return new JSONResponse(['status' => 'ok', 'action' => $action]);
    }

    /**
     * Delete a booking (admin/manager or owner).
     * When $recurrenceId is provided and the booking is recurring, only that
     * occurrence is cancelled (EXDATE); otherwise the entire series is removed.
     */
    #[NoAdminRequired]
    public function destroy(string $id, string $uid, ?string $recurrenceId = null): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $room = $this->roomService->getRoom($id);
        if ($room === null) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

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

        // Non-recurring booking + stray recurrenceId → fall back to series delete.
        $isOccurrence = $recurrenceId !== null && !empty($existingBooking['isRecurring']);
        if ($recurrenceId !== null && !$isOccurrence) {
            $this->logger->warning("Booking {$uid} is not recurring but recurrenceId was supplied — falling back to series delete");
        }

        try {
            try {
                $this->exchangeSyncService->deleteBookingFromExchange(
                    $room,
                    $uid,
                    $isOccurrence ? $recurrenceId : null,
                );
            } catch (\Throwable $e) {
                $this->logger->error("Exchange delete failed (non-blocking): " . $e->getMessage());
            }

            if ($isOccurrence) {
                $success = $this->calDAVService->deleteBookingOccurrence($room['userId'], $uid, $recurrenceId);
            } else {
                $success = $this->calDAVService->deleteBooking($room['userId'], $uid);
            }

            if (!$success) {
                return new JSONResponse(['error' => 'Booking not found'], 404);
            }

            $context = $isOccurrence ? " occurrence {$recurrenceId}" : '';
            $this->logger->info("Booking {$uid}{$context} in room {$id} deleted by {$userId}");

            // Propagate cancellation to organizer's calendar so the slot is
            // freed in their Room Finder (issue #10 / #13). For an occurrence,
            // an override VEVENT is added to the organizer's master event.
            $organizerEmail = $existingBooking['organizerEmail'] ?? '';
            $roomEmail = $existingBooking['roomEmail'] ?? '';
            if ($organizerEmail !== '' && $roomEmail !== '') {
                try {
                    $this->calDAVService->updateOrganizerEventPartstat(
                        $organizerEmail,
                        $uid,
                        'DECLINED',
                        $roomEmail,
                        $isOccurrence ? $recurrenceId : null,
                    );
                } catch (\Throwable $e) {
                    $this->logger->error("Failed to clean up organizer event for cancelled booking {$uid}: " . $e->getMessage());
                }

                try {
                    $this->mailService->sendRespondCancelled(
                        $room,
                        $existingBooking,
                        $isOccurrence ? $recurrenceId : null,
                    );
                } catch (\Throwable $e) {
                    $this->logger->error("Failed to send cancellation email for booking {$uid}: " . $e->getMessage());
                }
            }

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

    /**
     * Trigger the manager approval mail for rooms that don't auto-accept.
     * The Sabre iTIP path covers this from SchedulingPlugin; this controller
     * bypasses Sabre, so we call it directly. Failures are non-blocking.
     */
    private function notifyManagersIfPending(
        array $room,
        string $uid,
        string $summary,
        string $organizerInput,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
    ): void {
        if ($room['autoAccept'] ?? false) {
            return;
        }
        try {
            $identity = $this->calDAVService->resolveOrganizerIdentity($organizerInput);
            $this->mailService->notifyManagersForBooking($room, [
                'uid' => $uid,
                'summary' => $summary,
                'organizerEmail' => $identity['email'] ?? '',
                'organizerName' => $identity['cn'] ?? '',
                'dtstart' => $start->format(\DateTimeInterface::ATOM),
                'dtend' => $end->format(\DateTimeInterface::ATOM),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("Manager approval mail failed (non-blocking): " . $e->getMessage());
        }
    }
}
