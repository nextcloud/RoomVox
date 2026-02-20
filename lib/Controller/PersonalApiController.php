<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class PersonalApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private RoomService $roomService,
        private PermissionService $permissionService,
        private CalDAVService $calDAVService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List rooms accessible to the current user, with their role
     */
    #[NoAdminRequired]
    public function rooms(): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $allRooms = $this->roomService->getAllRooms();
        $isAdmin = $this->groupManager->isAdmin($userId);

        $result = [];
        foreach ($allRooms as $room) {
            $role = $isAdmin ? 'admin' : $this->permissionService->getEffectiveRole($userId, $room['id']);
            if ($role === 'none') {
                continue;
            }

            $result[] = [
                'id' => $room['id'],
                'name' => $room['name'],
                'roomType' => $room['roomType'] ?? '',
                'capacity' => $room['capacity'] ?? 0,
                'address' => $room['address'] ?? '',
                'role' => $role,
            ];
        }

        // Sort by role priority: admin > manager > booker > viewer
        $rolePriority = ['admin' => 0, 'manager' => 1, 'booker' => 2, 'viewer' => 3];
        usort($result, fn($a, $b) => ($rolePriority[$a['role']] ?? 9) <=> ($rolePriority[$b['role']] ?? 9));

        return new JSONResponse($result);
    }

    /**
     * List pending (TENTATIVE) bookings for rooms the user manages
     */
    #[NoAdminRequired]
    public function approvals(): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $allRooms = $this->roomService->getAllRooms();
        $isAdmin = $this->groupManager->isAdmin($userId);

        // Filter to rooms user can manage
        $managedRooms = array_filter($allRooms, function ($room) use ($userId, $isAdmin) {
            return $isAdmin || $this->permissionService->canManage($userId, $room['id']);
        });

        $pendingBookings = [];
        foreach ($managedRooms as $room) {
            $bookings = $this->calDAVService->getBookings($room['userId']);

            foreach ($bookings as $booking) {
                if (($booking['partstat'] ?? '') === 'TENTATIVE') {
                    $pendingBookings[] = [
                        'uid' => $booking['uid'],
                        'roomId' => $room['id'],
                        'roomName' => $room['name'],
                        'summary' => $booking['summary'] ?? 'Unnamed event',
                        'dtstart' => $booking['dtstart'] ?? null,
                        'dtend' => $booking['dtend'] ?? null,
                        'organizerName' => $booking['organizerName'] ?? '',
                        'organizer' => $booking['organizer'] ?? '',
                        'partstat' => $booking['partstat'],
                    ];
                }
            }
        }

        // Sort by start date ascending
        usort($pendingBookings, function ($a, $b) {
            return ($a['dtstart'] ?? '') <=> ($b['dtstart'] ?? '');
        });

        return new JSONResponse($pendingBookings);
    }

    private function getCurrentUserId(): ?string {
        $user = $this->userSession->getUser();
        return $user?->getUID();
    }
}
