<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\Middleware\ApiTokenMiddleware;
use OCA\RoomVox\Middleware\ApiTokenException;
use OCA\RoomVox\Service\ApiTokenService;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class PublicApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private RoomService $roomService,
        private CalDAVService $calDAVService,
        private ExchangeSyncService $exchangeSyncService,
        private MailService $mailService,
        private ApiTokenMiddleware $tokenMiddleware,
        private ApiTokenService $tokenService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ── Room Status ──────────────────────────────────────────────────

    /**
     * Get current status of a room (free/busy/unavailable)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function roomStatus(string $id): JSONResponse {
        $token = $this->requireScope('read');
        $room = $this->getAuthorizedRoom($token, $id);
        if ($room instanceof JSONResponse) {
            return $room;
        }

        $now = new \DateTimeImmutable();
        $todayStart = $now->setTime(0, 0);
        $todayEnd = $now->setTime(23, 59, 59);

        $bookings = $this->calDAVService->getBookings(
            $room['userId'],
            $todayStart->format('c'),
            $todayEnd->format('c')
        );

        // Only accepted bookings
        $accepted = array_filter($bookings, fn($b) => ($b['partstat'] ?? '') === 'ACCEPTED');
        $accepted = array_values($accepted);

        // Determine current status
        $status = 'free';
        $currentBooking = null;
        $nextBooking = null;
        $freeUntil = null;

        // Check availability rules
        if (!empty($room['availabilityRules']['enabled'])) {
            $dayOfWeek = strtolower($now->format('D')); // mon, tue, etc.
            $currentTime = $now->format('H:i');
            $isWithinRules = false;

            foreach ($room['availabilityRules']['rules'] ?? [] as $rule) {
                if (in_array($dayOfWeek, $rule['days'] ?? []) &&
                    $currentTime >= ($rule['startTime'] ?? '00:00') &&
                    $currentTime <= ($rule['endTime'] ?? '23:59')) {
                    $isWithinRules = true;
                    break;
                }
            }

            if (!$isWithinRules) {
                $status = 'unavailable';
            }
        }

        if ($status !== 'unavailable') {
            foreach ($accepted as $booking) {
                $start = new \DateTimeImmutable($booking['dtstart']);
                $end = new \DateTimeImmutable($booking['dtend']);

                if ($now >= $start && $now < $end) {
                    $status = 'busy';
                    $currentBooking = [
                        'title' => $booking['summary'],
                        'organizer' => $booking['organizerName'] ?: $booking['organizer'],
                        'start' => $booking['dtstart'],
                        'end' => $booking['dtend'],
                        'minutesRemaining' => (int)ceil(($end->getTimestamp() - $now->getTimestamp()) / 60),
                    ];
                } elseif ($now < $start && $nextBooking === null) {
                    $nextBooking = [
                        'title' => $booking['summary'],
                        'organizer' => $booking['organizerName'] ?: $booking['organizer'],
                        'start' => $booking['dtstart'],
                        'end' => $booking['dtend'],
                    ];
                }
            }

            if ($status === 'free' && $nextBooking !== null) {
                $freeUntil = $nextBooking['start'];
            }
        }

        $todayBookings = array_map(fn($b) => [
            'title' => $b['summary'],
            'start' => $b['dtstart'],
            'end' => $b['dtend'],
            'status' => $this->partstatToStatus($b['partstat']),
        ], $accepted);

        return new JSONResponse([
            'room' => $this->formatRoom($room),
            'status' => $status,
            'currentBooking' => $currentBooking,
            'nextBooking' => $nextBooking,
            'freeUntil' => $freeUntil,
            'todayBookings' => $todayBookings,
        ]);
    }

    /**
     * Get availability slots for a room on a given date
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function roomAvailability(string $id): JSONResponse {
        $token = $this->requireScope('read');
        $room = $this->getAuthorizedRoom($token, $id);
        if ($room instanceof JSONResponse) {
            return $room;
        }

        $date = $this->request->getParam('date', date('Y-m-d'));
        $from = $this->request->getParam('from');
        $to = $this->request->getParam('to');

        try {
            if ($from && $to) {
                // Validate date range
                $rangeError = $this->validateDateRange($from, $to);
                if ($rangeError !== null) {
                    return $rangeError;
                }
                $rangeStart = new \DateTimeImmutable($from);
                $rangeEnd = new \DateTimeImmutable($to);
            } else {
                $rangeStart = new \DateTimeImmutable($date . 'T00:00:00');
                $rangeEnd = new \DateTimeImmutable($date . 'T23:59:59');
            }
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Invalid date format'], 400);
        }

        $bookings = $this->calDAVService->getBookings(
            $room['userId'],
            $rangeStart->format('c'),
            $rangeEnd->format('c')
        );

        // Only accepted bookings
        $busy = array_filter($bookings, fn($b) => ($b['partstat'] ?? '') === 'ACCEPTED');
        $busy = array_values($busy);

        // Determine available hours from rules
        $availabilityRules = null;
        $dayStart = $rangeStart->format('H:i') === '00:00' ? '00:00' : $rangeStart->format('H:i');
        $dayEnd = '23:59';

        if (!empty($room['availabilityRules']['enabled'])) {
            $dayOfWeek = strtolower($rangeStart->format('D'));
            foreach ($room['availabilityRules']['rules'] ?? [] as $rule) {
                if (in_array($dayOfWeek, $rule['days'] ?? [])) {
                    $dayStart = $rule['startTime'] ?? '08:00';
                    $dayEnd = $rule['endTime'] ?? '18:00';
                    $availabilityRules = [
                        'start' => $dayStart,
                        'end' => $dayEnd,
                        'days' => $rule['days'],
                    ];
                    break;
                }
            }
        }

        // Build slots
        $slots = [];
        $cursor = new \DateTimeImmutable($date . 'T' . $dayStart);
        $end = new \DateTimeImmutable($date . 'T' . $dayEnd);

        foreach ($busy as $booking) {
            $bStart = new \DateTimeImmutable($booking['dtstart']);
            $bEnd = new \DateTimeImmutable($booking['dtend']);

            // Clamp to day range
            if ($bStart < $cursor) {
                $bStart = $cursor;
            }
            if ($bEnd > $end) {
                $bEnd = $end;
            }

            if ($cursor < $bStart) {
                $slots[] = [
                    'start' => $cursor->format('H:i'),
                    'end' => $bStart->format('H:i'),
                    'status' => 'free',
                ];
            }

            $slots[] = [
                'start' => $bStart->format('H:i'),
                'end' => $bEnd->format('H:i'),
                'status' => 'busy',
                'title' => $booking['summary'],
            ];

            $cursor = $bEnd;
        }

        if ($cursor < $end) {
            $slots[] = [
                'start' => $cursor->format('H:i'),
                'end' => $end->format('H:i'),
                'status' => 'free',
            ];
        }

        return new JSONResponse([
            'room' => ['id' => $room['id'], 'name' => $room['name']],
            'date' => $date,
            'availabilityRules' => $availabilityRules,
            'slots' => $slots,
        ]);
    }

    // ── Rooms ────────────────────────────────────────────────────────

    /**
     * List all rooms (filtered by token scope)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function listRooms(): JSONResponse {
        $token = $this->requireScope('read');
        $rooms = $this->roomService->getAllRooms();

        // Filter by token room restrictions
        if (!empty($token['roomIds'])) {
            $rooms = array_filter($rooms, fn($r) => in_array($r['id'], $token['roomIds']));
        }

        // Apply query filters
        $active = $this->request->getParam('active');
        $type = $this->request->getParam('type');
        $capacityMin = $this->request->getParam('capacity_min');

        if ($active !== null) {
            $isActive = $active === 'true' || $active === '1';
            $rooms = array_filter($rooms, fn($r) => ($r['active'] ?? true) === $isActive);
        }
        if ($type !== null) {
            $rooms = array_filter($rooms, fn($r) => ($r['roomType'] ?? '') === $type);
        }
        if ($capacityMin !== null) {
            $min = (int)$capacityMin;
            $rooms = array_filter($rooms, fn($r) => ($r['capacity'] ?? 0) >= $min);
        }

        $result = array_map(fn($r) => $this->formatRoom($r), array_values($rooms));

        return new JSONResponse($result);
    }

    /**
     * Get a single room
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function getRoom(string $id): JSONResponse {
        $token = $this->requireScope('read');
        $room = $this->getAuthorizedRoom($token, $id);
        if ($room instanceof JSONResponse) {
            return $room;
        }

        return new JSONResponse($this->formatRoom($room));
    }

    // ── Bookings ─────────────────────────────────────────────────────

    /**
     * List bookings for a room
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function listBookings(string $id): JSONResponse {
        $token = $this->requireScope('read');
        $room = $this->getAuthorizedRoom($token, $id);
        if ($room instanceof JSONResponse) {
            return $room;
        }

        $from = $this->request->getParam('from');
        $to = $this->request->getParam('to');
        $status = $this->request->getParam('status');

        // Validate date range
        $rangeError = $this->validateDateRange($from, $to);
        if ($rangeError !== null) {
            return $rangeError;
        }

        $bookings = $this->calDAVService->getBookings($room['userId'], $from, $to);

        // Filter by status
        if ($status !== null) {
            $partstat = match ($status) {
                'accepted' => 'ACCEPTED',
                'pending' => 'TENTATIVE',
                'declined' => 'DECLINED',
                default => null,
            };
            if ($partstat !== null) {
                $bookings = array_filter($bookings, fn($b) => ($b['partstat'] ?? '') === $partstat);
            }
        }

        $result = array_map(fn($b) => $this->formatBooking($b, $room), array_values($bookings));

        return new JSONResponse($result);
    }

    /**
     * Create a new booking
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function createBooking(string $id): JSONResponse {
        $token = $this->requireScope('book');
        $room = $this->getAuthorizedRoom($token, $id);
        if ($room instanceof JSONResponse) {
            return $room;
        }

        $title = $this->request->getParam('title', '');
        $start = $this->request->getParam('start', '');
        $end = $this->request->getParam('end', '');
        $organizer = $this->request->getParam('organizer', '');
        $description = $this->request->getParam('description', '');

        if (empty($title) || empty($start) || empty($end)) {
            return new JSONResponse(['error' => 'title, start, and end are required'], 400);
        }

        try {
            $startDt = new \DateTime($start);
            $endDt = new \DateTime($end);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Invalid date format for start or end'], 400);
        }

        if ($endDt <= $startDt) {
            return new JSONResponse(['error' => 'End time must be after start time'], 400);
        }

        // Check for conflicts (local + Exchange)
        if ($this->calDAVService->hasConflict($room['userId'], $startDt, $endDt, null, $room)) {
            return new JSONResponse(['error' => 'Room is already booked during this time'], 409);
        }

        // Check availability rules
        if (!empty($room['availabilityRules']['enabled'])) {
            $dayOfWeek = strtolower($startDt->format('D'));
            $startTime = $startDt->format('H:i');
            $endTime = $endDt->format('H:i');

            $withinRules = false;
            foreach ($room['availabilityRules']['rules'] ?? [] as $rule) {
                if (in_array($dayOfWeek, $rule['days'] ?? []) &&
                    $startTime >= ($rule['startTime'] ?? '00:00') &&
                    $endTime <= ($rule['endTime'] ?? '23:59')) {
                    $withinRules = true;
                    break;
                }
            }

            if (!$withinRules) {
                return new JSONResponse(['error' => 'Booking is outside available hours'], 422);
            }
        }

        // Check booking horizon
        if (!empty($room['maxBookingHorizon']) && (int)$room['maxBookingHorizon'] > 0) {
            $maxDate = new \DateTimeImmutable('+' . $room['maxBookingHorizon'] . ' days');
            if ($startDt > $maxDate) {
                return new JSONResponse(['error' => 'Booking exceeds maximum booking horizon'], 422);
            }
        }

        $bookingData = [
            'summary' => $title,
            'start' => $startDt,
            'end' => $endDt,
            'organizer' => $organizer,
            'description' => $description,
            'roomEmail' => $room['email'] ?? '',
            'autoAccept' => $room['autoAccept'] ?? false,
        ];

        try {
            $uid = $this->calDAVService->createBooking($room['userId'], $bookingData);

            // Push to Exchange (synchronous, fail-safe)
            try {
                $this->exchangeSyncService->pushBookingToExchange($room, $uid, [
                    'summary' => $title,
                    'start' => $startDt,
                    'end' => $endDt,
                    'description' => $description,
                    'organizer' => $organizer,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error("Exchange push failed (non-blocking): " . $e->getMessage());
            }

            // Notify managers when the room requires approval. The Sabre iTIP
            // path runs notifyManagers from SchedulingPlugin, but the direct
            // API create path bypasses that — so trigger it here.
            if (!($room['autoAccept'] ?? false)) {
                try {
                    $identity = $this->calDAVService->resolveOrganizerIdentity($organizer);
                    $this->mailService->notifyManagersForBooking($room, [
                        'uid' => $uid,
                        'summary' => $title,
                        'organizerEmail' => $identity['email'] ?? '',
                        'organizerName' => $identity['cn'] ?? '',
                        'dtstart' => $startDt->format(\DateTimeInterface::ATOM),
                        'dtend' => $endDt->format(\DateTimeInterface::ATOM),
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error("Manager approval mail failed (non-blocking): " . $e->getMessage());
                }
            }

            $status = ($room['autoAccept'] ?? false) ? 'accepted' : 'pending';

            return new JSONResponse([
                'uid' => $uid,
                'title' => $title,
                'start' => $start,
                'end' => $end,
                'status' => $status,
                'room' => ['id' => $room['id'], 'name' => $room['name']],
            ], 201);
        } catch (\Exception $e) {
            $this->logger->error("API booking creation failed: " . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to create booking'], 500);
        }
    }

    /**
     * Delete/cancel a booking
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function deleteBooking(string $id, string $uid, ?string $recurrenceId = null): JSONResponse {
        $token = $this->requireScope('book');
        $room = $this->getAuthorizedRoom($token, $id);
        if ($room instanceof JSONResponse) {
            return $room;
        }

        $isOccurrence = false;
        if ($recurrenceId !== null) {
            $existing = $this->calDAVService->getBookingByUid($room['userId'], $uid);
            $isOccurrence = $existing !== null && !empty($existing['isRecurring']);
            if (!$isOccurrence) {
                $this->logger->warning("Public API: booking {$uid} is not recurring but recurrenceId was supplied — falling back to series delete");
            }
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
                $this->calDAVService->deleteBookingOccurrence($room['userId'], $uid, $recurrenceId);
            } else {
                $this->calDAVService->deleteBooking($room['userId'], $uid);
            }
            return new JSONResponse(['status' => 'ok']);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Booking not found'], 404);
        }
    }

    // ── iCal Feed ────────────────────────────────────────────────────

    /**
     * iCalendar (.ics) feed for a room
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function calendarFeed(string $id): DataDownloadResponse {
        try {
            $token = $this->requireScope('read');
        } catch (ApiTokenException $e) {
            return new DataDownloadResponse('', 'error.ics', 'text/calendar');
        }

        $room = $this->getAuthorizedRoom($token, $id);
        if ($room instanceof JSONResponse) {
            return new DataDownloadResponse('', 'error.ics', 'text/calendar');
        }

        // Pass through master VEVENTs with RRULE intact (issue #4): clients
        // expand recurrence themselves. Server-side expansion + duplicated UIDs
        // violates RFC 5545 §3.8.4.7 and causes clients to dedupe to one event.
        $rawObjects = $this->calDAVService->getRawCalendarObjects($room['userId'], 'ACCEPTED');

        $location = $this->roomService->buildRoomLocation($room);

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//RoomVox//Nextcloud//EN\r\n";
        $ics .= "X-WR-CALNAME:" . $this->escapeIcal($room['name']) . "\r\n";

        foreach ($rawObjects as $object) {
            $vevents = $this->extractVEvents($object['calendardata'] ?? '');
            foreach ($vevents as $vevent) {
                $vevent = $this->rewriteVEventLocation($vevent, $location);
                $vevent = $this->forceVEventStatus($vevent, 'CONFIRMED');
                $ics .= $vevent;
            }
        }

        $ics .= "END:VCALENDAR\r\n";

        $filename = 'roomvox-' . $room['id'] . '.ics';
        return new DataDownloadResponse($ics, $filename, 'text/calendar; charset=utf-8');
    }

    /**
     * Extract VEVENT blocks (with CRLF endings) from a raw iCalendar string.
     * Returns each "BEGIN:VEVENT ... END:VEVENT\r\n" verbatim — RRULE, EXDATE,
     * RECURRENCE-ID, TZID parameters, line-folding all preserved.
     *
     * @return string[]
     */
    private function extractVEvents(string $calendarData): array {
        if ($calendarData === '') {
            return [];
        }
        // Normalize line endings to CRLF so output is RFC-compliant regardless
        // of the source's line endings.
        $normalized = str_replace(["\r\n", "\r"], "\n", $calendarData);
        $normalized = str_replace("\n", "\r\n", $normalized);

        $events = [];
        $offset = 0;
        while (($beginPos = strpos($normalized, "BEGIN:VEVENT\r\n", $offset)) !== false) {
            $endPos = strpos($normalized, "END:VEVENT\r\n", $beginPos);
            if ($endPos === false) {
                break;
            }
            $endPos += strlen("END:VEVENT\r\n");
            $events[] = substr($normalized, $beginPos, $endPos - $beginPos);
            $offset = $endPos;
        }
        return $events;
    }

    /**
     * Replace any existing LOCATION line in a VEVENT block with the given
     * value (no-op when location is empty). Handles line-folded LOCATION
     * by also dropping continuation lines (those starting with space/tab).
     */
    private function rewriteVEventLocation(string $vevent, string $location): string {
        $lines = explode("\r\n", $vevent);
        $out = [];
        $skipFolds = false;
        foreach ($lines as $line) {
            if ($skipFolds) {
                if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                    continue;
                }
                $skipFolds = false;
            }
            if (preg_match('/^LOCATION[:;]/', $line) === 1) {
                $skipFolds = true;
                continue;
            }
            $out[] = $line;
        }
        $result = implode("\r\n", $out);
        if ($location !== '') {
            // Insert LOCATION right before END:VEVENT so it is part of the component.
            $insertion = "LOCATION:" . $this->escapeIcal($location) . "\r\n";
            $result = str_replace("END:VEVENT\r\n", $insertion . "END:VEVENT\r\n", $result);
        }
        return $result;
    }

    /**
     * Force STATUS:<status> in a VEVENT block, replacing any existing STATUS.
     */
    private function forceVEventStatus(string $vevent, string $status): string {
        $lines = explode("\r\n", $vevent);
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^STATUS:/', $line) === 1) {
                continue;
            }
            $out[] = $line;
        }
        $result = implode("\r\n", $out);
        $insertion = "STATUS:" . $status . "\r\n";
        return str_replace("END:VEVENT\r\n", $insertion . "END:VEVENT\r\n", $result);
    }

    // ── Statistics ────────────────────────────────────────────────────

    /**
     * Usage statistics for rooms
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function statistics(): JSONResponse {
        $token = $this->requireScope('admin');
        $rooms = $this->roomService->getAllRooms();

        // Filter by token room restrictions
        if (!empty($token['roomIds'])) {
            $rooms = array_filter($rooms, fn($r) => in_array($r['id'], $token['roomIds']));
            $rooms = array_values($rooms);
        }

        $from = $this->request->getParam('from', (new \DateTimeImmutable('-30 days'))->format('Y-m-d'));
        $to = $this->request->getParam('to', date('Y-m-d'));
        $roomFilter = $this->request->getParam('room');

        // Validate date range
        $rangeError = $this->validateDateRange($from, $to);
        if ($rangeError !== null) {
            return $rangeError;
        }

        $fromDate = $from . 'T00:00:00';
        $toDate = $to . 'T23:59:59';

        $activeRooms = array_filter($rooms, fn($r) => ($r['active'] ?? true));
        $byType = [];
        foreach ($rooms as $room) {
            $type = $room['roomType'] ?? 'other';
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        $totalBookings = 0;
        $accepted = 0;
        $declined = 0;
        $pending = 0;
        $cancelled = 0;
        $utilization = [];

        $targetRooms = $rooms;
        if ($roomFilter) {
            $targetRooms = array_filter($rooms, fn($r) => $r['id'] === $roomFilter);
        }

        foreach ($targetRooms as $room) {
            $bookings = $this->calDAVService->getBookings($room['userId'], $fromDate, $toDate);

            $roomAccepted = 0;
            $totalHours = 0;

            foreach ($bookings as $booking) {
                $totalBookings++;
                $partstat = $booking['partstat'] ?? '';
                $status = $booking['status'] ?? '';

                if ($partstat === 'ACCEPTED') {
                    $accepted++;
                    $roomAccepted++;

                    if (!empty($booking['dtstart']) && !empty($booking['dtend'])) {
                        $start = new \DateTimeImmutable($booking['dtstart']);
                        $end = new \DateTimeImmutable($booking['dtend']);
                        $totalHours += ($end->getTimestamp() - $start->getTimestamp()) / 3600;
                    }
                } elseif ($partstat === 'DECLINED') {
                    $declined++;
                } elseif ($partstat === 'TENTATIVE') {
                    $pending++;
                }

                if ($status === 'CANCELLED') {
                    $cancelled++;
                }
            }

            // Calculate available hours in the period
            $periodStart = new \DateTimeImmutable($from);
            $periodEnd = new \DateTimeImmutable($to);
            $days = max(1, (int)$periodStart->diff($periodEnd)->days + 1);
            $hoursPerDay = 8; // Default 8-hour workday

            if (!empty($room['availabilityRules']['enabled'])) {
                $firstRule = ($room['availabilityRules']['rules'] ?? [])[0] ?? null;
                if ($firstRule) {
                    $ruleStart = new \DateTimeImmutable('today ' . ($firstRule['startTime'] ?? '08:00'));
                    $ruleEnd = new \DateTimeImmutable('today ' . ($firstRule['endTime'] ?? '18:00'));
                    $hoursPerDay = ($ruleEnd->getTimestamp() - $ruleStart->getTimestamp()) / 3600;
                    // Adjust for weekdays only
                    $activeDays = count($firstRule['days'] ?? []);
                    $days = (int)round($days * $activeDays / 7);
                }
            }

            $availableHours = $days * $hoursPerDay;

            $utilization[] = [
                'roomId' => $room['id'],
                'roomName' => $room['name'],
                'totalHoursBooked' => round($totalHours, 1),
                'totalHoursAvailable' => round($availableHours, 1),
                'utilizationPercent' => $availableHours > 0 ? round(($totalHours / $availableHours) * 100, 1) : 0,
                'bookingCount' => $roomAccepted,
            ];
        }

        return new JSONResponse([
            'period' => ['from' => $from, 'to' => $to],
            'rooms' => [
                'total' => count($rooms),
                'active' => count($activeRooms),
                'byType' => $byType,
            ],
            'bookings' => [
                'total' => $totalBookings,
                'accepted' => $accepted,
                'declined' => $declined,
                'pending' => $pending,
                'cancelled' => $cancelled,
            ],
            'utilization' => $utilization,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Validate that from/to are valid dates and span at most 365 days.
     */
    private function validateDateRange(?string $from, ?string $to): ?JSONResponse {
        if ($from === null && $to === null) {
            return null;
        }

        try {
            $fromDt = $from !== null ? new \DateTimeImmutable($from) : null;
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Invalid "from" date format'], 400);
        }

        try {
            $toDt = $to !== null ? new \DateTimeImmutable($to) : null;
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Invalid "to" date format'], 400);
        }

        if ($fromDt !== null && $toDt !== null) {
            $diff = $fromDt->diff($toDt);
            if ($diff->invert) {
                return new JSONResponse(['error' => '"from" must be before "to"'], 400);
            }
            if ($diff->days > 365) {
                return new JSONResponse(['error' => 'Date range must not exceed 365 days'], 400);
            }
        }

        return null;
    }

    private function requireScope(string $scope): array {
        $token = $this->tokenMiddleware->getValidatedToken();
        if ($token === null) {
            throw new ApiTokenException('Authentication required', 401);
        }

        $scopes = ['read' => 1, 'book' => 2, 'admin' => 3];
        $tokenLevel = $scopes[$token['scope']] ?? 0;
        $requiredLevel = $scopes[$scope] ?? 99;

        if ($tokenLevel < $requiredLevel) {
            throw new ApiTokenException('Insufficient permissions. Required scope: ' . $scope, 403);
        }

        return $token;
    }

    private function getAuthorizedRoom(array $token, string $roomId): array|JSONResponse {
        $room = $this->roomService->getRoom($roomId);
        if ($room === null) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

        if (!$this->tokenService->hasRoomAccess($token, $roomId)) {
            return new JSONResponse(['error' => 'Token does not have access to this room'], 403);
        }

        return $room;
    }

    private function formatRoom(array $room): array {
        return [
            'id' => $room['id'],
            'name' => $room['name'],
            'email' => $room['email'] ?? '',
            'capacity' => $room['capacity'] ?? 0,
            'roomNumber' => $room['roomNumber'] ?? '',
            'roomType' => $room['roomType'] ?? '',
            'facilities' => $room['facilities'] ?? [],
            'description' => $room['description'] ?? '',
            'responsibleContact' => $room['responsibleContact'] ?? '',
            'location' => $this->roomService->buildRoomLocation($room),
            'autoAccept' => $room['autoAccept'] ?? false,
            'active' => $room['active'] ?? true,
        ];
    }

    private function formatBooking(array $booking, array $room): array {
        return [
            'uid' => $booking['uid'],
            'title' => $booking['summary'],
            'start' => $booking['dtstart'],
            'end' => $booking['dtend'],
            'organizer' => $booking['organizerName'] ?: $booking['organizer'],
            'status' => $this->partstatToStatus($booking['partstat'] ?? ''),
            'room' => ['id' => $room['id'], 'name' => $room['name']],
        ];
    }

    private function partstatToStatus(string $partstat): string {
        return match ($partstat) {
            'ACCEPTED' => 'accepted',
            'DECLINED' => 'declined',
            'TENTATIVE' => 'pending',
            default => 'unknown',
        };
    }

    private function escapeIcal(string $text): string {
        return str_replace(
            ['\\', ';', ',', "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', ''],
            $text
        );
    }
}
