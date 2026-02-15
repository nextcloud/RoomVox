<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class RoomApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private RoomService $roomService,
        private PermissionService $permissionService,
        private CalDAVService $calDAVService,
        private MailService $mailService,
        private IRoomManager $roomManager,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List all rooms with user's permissions
     *
     * @NoAdminRequired
     */
    public function index(): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $rooms = $this->roomService->getAllRooms();
        $isAdmin = $this->groupManager->isAdmin($userId);

        // Add permission info for each room
        $sanitized = array_map(function ($room) use ($userId, $isAdmin) {
            // Strip SMTP passwords from response
            if (!empty($room['smtpConfig']['password'])) {
                $room['smtpConfig']['password'] = '***';
            }

            // Add permission flags for this user
            $room['canView'] = $isAdmin || $this->permissionService->canView($userId, $room['id']);
            $room['canBook'] = $isAdmin || $this->permissionService->canBook($userId, $room['id']);
            $room['canManage'] = $isAdmin || $this->permissionService->canManage($userId, $room['id']);

            return $room;
        }, array_values($rooms));

        // Filter to only rooms user can at least view
        $sanitized = array_filter($sanitized, fn($r) => $r['canView']);

        return new JSONResponse(array_values($sanitized));
    }

    /**
     * Get all bookings across all rooms (or filtered by room)
     *
     * @NoAdminRequired
     */
    public function allBookings(): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $roomId = $this->request->getParam('room');
        $status = $this->request->getParam('status', 'all');
        $from = $this->request->getParam('from');
        $to = $this->request->getParam('to');

        // Get all rooms the user can view (for availability/booking display)
        $rooms = $this->roomService->getAllRooms();
        $isAdmin = $this->groupManager->isAdmin($userId);

        if (!$isAdmin) {
            $rooms = array_filter($rooms, function ($room) use ($userId) {
                return $this->permissionService->canView($userId, $room['id']);
            });
        }

        // Filter to specific room if requested
        if ($roomId !== null && $roomId !== '') {
            $rooms = array_filter($rooms, fn($r) => $r['id'] === $roomId);
        }

        $allBookings = [];
        $stats = [
            'today' => 0,
            'pending' => 0,
            'thisWeek' => 0,
        ];

        $today = new \DateTimeImmutable('today');
        $tomorrow = new \DateTimeImmutable('tomorrow');
        $weekStart = new \DateTimeImmutable('monday this week');
        $weekEnd = new \DateTimeImmutable('sunday this week 23:59:59');

        foreach ($rooms as $room) {
            $bookings = $this->calDAVService->getBookings($room['userId'], $from, $to);

            foreach ($bookings as $booking) {
                $booking['roomId'] = $room['id'];
                $booking['roomName'] = $room['name'];
                $booking['roomLocation'] = $this->roomService->buildRoomLocation($room);

                // Apply status filter
                $partstat = $booking['partstat'] ?? '';
                if ($status === 'pending' && $partstat !== 'TENTATIVE') {
                    continue;
                }
                if ($status === 'accepted' && $partstat !== 'ACCEPTED') {
                    continue;
                }
                if ($status === 'declined' && $partstat !== 'DECLINED') {
                    continue;
                }

                // Calculate stats
                $dtstart = isset($booking['dtstart']) ? new \DateTimeImmutable($booking['dtstart']) : null;
                if ($dtstart !== null) {
                    if ($dtstart >= $today && $dtstart < $tomorrow) {
                        $stats['today']++;
                    }
                    if ($dtstart >= $weekStart && $dtstart <= $weekEnd) {
                        $stats['thisWeek']++;
                    }
                }
                if ($partstat === 'TENTATIVE') {
                    $stats['pending']++;
                }

                $allBookings[] = $booking;
            }
        }

        // Sort by date
        usort($allBookings, function ($a, $b) {
            return ($a['dtstart'] ?? '') <=> ($b['dtstart'] ?? '');
        });

        return new JSONResponse([
            'bookings' => $allBookings,
            'stats' => $stats,
        ]);
    }

    /**
     * Get a single room
     */
    public function show(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $room = $this->roomService->getRoom($id);
        if ($room === null) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

        if (!$this->groupManager->isAdmin($userId) && !$this->permissionService->canManage($userId, $id)) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }

        if (!empty($room['smtpConfig']['password'])) {
            $room['smtpConfig']['password'] = '***';
        }

        return new JSONResponse($room);
    }

    /**
     * Create a new room (admin only)
     */
    public function create(): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $data = [
            'name' => $this->request->getParam('name', ''),
            'email' => $this->request->getParam('email', ''),
            'description' => $this->request->getParam('description', ''),
            'capacity' => $this->request->getParam('capacity', 0),
            'location' => $this->request->getParam('location', ''),
            'facilities' => $this->request->getParam('facilities', []),
            'autoAccept' => $this->request->getParam('autoAccept', false),
            'smtpConfig' => $this->request->getParam('smtpConfig', null),
            'availabilityRules' => $this->request->getParam('availabilityRules', null),
            'maxBookingHorizon' => $this->request->getParam('maxBookingHorizon', 0),
        ];

        if (empty($data['name'])) {
            return new JSONResponse(['error' => 'Room name is required'], 400);
        }

        try {
            // 1. Create room in config
            $room = $this->roomService->createRoom($data);

            // 2. Provision CalDAV calendar
            $calendarUri = $this->calDAVService->provisionCalendar($room['userId'], $room['name']);
            $this->roomService->setCalendarUri($room['id'], $calendarUri);
            $room['calendarUri'] = $calendarUri;

            // 3. Initialize empty permissions
            $this->permissionService->setPermissions($room['id'], [
                'viewers' => [],
                'bookers' => [],
                'managers' => [],
            ]);

            // 4. Publish VAVAILABILITY if availability rules are set
            if (!empty($room['availabilityRules']['enabled'])) {
                $this->calDAVService->publishAvailability($room['userId'], $room);
            }

            // 5. Sync room cache so it appears in calendar apps immediately
            $this->syncRoomCache();

            if (!empty($room['smtpConfig']['password'])) {
                $room['smtpConfig']['password'] = '***';
            }

            return new JSONResponse($room, 201);
        } catch (\Exception $e) {
            $this->logger->error("Failed to create room: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to create room: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update a room (admin or manager)
     */
    public function update(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        if (!$this->groupManager->isAdmin($userId) && !$this->permissionService->canManage($userId, $id)) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }

        $data = [];
        $updatableFields = ['name', 'email', 'description', 'capacity', 'roomNumber', 'roomType', 'address', 'facilities', 'autoAccept', 'active', 'smtpConfig', 'groupId', 'availabilityRules', 'maxBookingHorizon'];

        foreach ($updatableFields as $field) {
            $value = $this->request->getParam($field);
            if ($value !== null) {
                $data[$field] = $value;
            }
        }

        try {
            $room = $this->roomService->updateRoom($id, $data);
            if ($room === null) {
                return new JSONResponse(['error' => 'Room not found'], 404);
            }

            // Publish/remove VAVAILABILITY when availability rules change
            if (array_key_exists('availabilityRules', $data)) {
                $this->calDAVService->publishAvailability($room['userId'], $room);
            }

            // Sync room cache
            $this->syncRoomCache();

            if (!empty($room['smtpConfig']['password'])) {
                $room['smtpConfig']['password'] = '***';
            }

            return new JSONResponse($room);
        } catch (\Exception $e) {
            $this->logger->error("Failed to update room {$id}: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to update room'], 500);
        }
    }

    /**
     * Delete a room (admin only)
     */
    public function destroy(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $room = $this->roomService->getRoom($id);
        if ($room === null) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

        try {
            // Delete calendar first
            $this->calDAVService->deleteCalendar($room['userId']);
        } catch (\Exception $e) {
            $this->logger->warning("Failed to delete calendar for room {$id}: " . $e->getMessage());
        }

        // Delete permissions
        $this->permissionService->deletePermissions($id);

        // Delete room config
        $this->roomService->deleteRoom($id);

        // Sync room cache
        $this->syncRoomCache();

        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Get permissions for a room
     */
    public function getPermissions(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        if (!$this->groupManager->isAdmin($userId) && !$this->permissionService->canManage($userId, $id)) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }

        $permissions = $this->permissionService->getPermissions($id);
        return new JSONResponse($permissions);
    }

    /**
     * Set permissions for a room
     */
    public function setPermissions(string $id): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        if (!$this->groupManager->isAdmin($userId) && !$this->permissionService->canManage($userId, $id)) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }

        $permissions = [
            'viewers' => $this->request->getParam('viewers', []),
            'bookers' => $this->request->getParam('bookers', []),
            'managers' => $this->request->getParam('managers', []),
        ];

        $this->permissionService->setPermissions($id, $permissions);

        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Search groups for the permission editor.
     * Only groups are supported because NC Calendar's room visibility
     * filtering (group_restrictions) is group-based.
     */
    public function searchSharees(): JSONResponse {
        $search = $this->request->getParam('search', '');
        $results = [];

        // Search groups only
        $groups = $this->groupManager->search($search, 25);
        foreach ($groups as $group) {
            $results[] = [
                'type' => 'group',
                'id' => $group->getGID(),
                'label' => $group->getDisplayName(),
            ];
        }

        return new JSONResponse($results);
    }

    /**
     * Debug endpoint to check what rooms are registered with Nextcloud's room manager.
     * This shows exactly what Calendar sees when searching for rooms.
     *
     * @NoCSRFRequired
     */
    public function debug(): JSONResponse {
        $userId = $this->getCurrentUserId();
        if ($userId === null || !$this->groupManager->isAdmin($userId)) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $result = [
            'backends' => [],
            'rooms' => [],
            'raw_rooms' => [],
        ];

        // List all registered backends and their rooms
        $backends = $this->roomManager->getBackends();
        foreach ($backends as $backend) {
            $backendId = $backend->getBackendIdentifier();
            $result['backends'][] = $backendId;

            // Get rooms from each backend
            try {
                $rooms = $backend->getAllRooms();
                foreach ($rooms as $room) {
                    $result['rooms'][] = [
                        'id' => $room->getId(),
                        'displayName' => $room->getDisplayName(),
                        'email' => $room->getEMail(),
                        'backend' => $backendId,
                        'groupRestrictions' => $room->getGroupRestrictions(),
                    ];
                }
            } catch (\Exception $e) {
                $result['rooms'][] = [
                    'error' => "Failed to get rooms from {$backendId}: " . $e->getMessage(),
                ];
            }
        }

        // Get raw rooms from our service for comparison
        $rawRooms = $this->roomService->getAllRooms();
        foreach ($rawRooms as $room) {
            $result['raw_rooms'][] = [
                'id' => $room['id'],
                'name' => $room['name'],
                'email' => $room['email'] ?? '',
                'active' => $room['active'] ?? true,
            ];
        }

        return new JSONResponse($result);
    }

    /**
     * Trigger room cache sync so rooms appear immediately in calendar apps
     */
    private function syncRoomCache(): void {
        try {
            $this->roomManager->update();
        } catch (\Exception $e) {
            $this->logger->warning('Failed to sync room cache: ' . $e->getMessage());
        }
    }

    private function getCurrentUserId(): ?string {
        $user = $this->userSession->getUser();
        return $user?->getUID();
    }
}
