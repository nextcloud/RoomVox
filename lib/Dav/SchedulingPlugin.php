<?php

declare(strict_types=1);

namespace OCA\RoomVox\Dav;

use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\VObject\ITip;

/**
 * CalDAV Scheduling Plugin for Room Booking.
 *
 * Runs BEFORE Sabre's scheduleLocalDelivery (priority 99 < 100) to fully
 * handle iTIP messages for room principals. This is necessary because
 * Sabre/NC's scheduling cannot resolve our room principals via
 * getPrincipalByUri (the AbstractPrincipalBackend::findByUri requires an
 * active user session which is not available during scheduling).
 *
 * For room principals, this plugin:
 * - Delivers the event to the room calendar directly via CalDavBackend
 * - Sets the correct PARTSTAT (ACCEPTED/TENTATIVE/DECLINED)
 * - Sets scheduleStatus to '1.2' (delivered) or error codes
 * - Returns false to stop Sabre from attempting (and failing) delivery
 */
class SchedulingPlugin extends ServerPlugin {
    private ?Server $server = null;

    /** @var array<string, string> room email → PARTSTAT set during this request */
    private array $scheduledPartstats = [];

    public function __construct(
        private RoomService $roomService,
        private PermissionService $permissionService,
        private CalDAVService $calDAVService,
        private MailService $mailService,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }

    public function initialize(Server $server): void {
        $this->server = $server;

        // Run BEFORE Sabre's scheduleLocalDelivery (priority 99 < 100).
        // For room principals we handle delivery ourselves and return false
        // to prevent Sabre from trying (which would fail with 3.7).
        $server->on('schedule', [$this, 'handleScheduleRequest'], 99);

        // After a calendar object is written, fix CUTYPE and LOCATION
        // in the organizer's event for room attendees that clients (iOS)
        // incorrectly sent as CUTYPE=INDIVIDUAL without LOCATION.
        $server->on('afterWriteContent', [$this, 'fixOrganizerEvent'], 200);
        $server->on('afterCreateFile', [$this, 'fixOrganizerEvent'], 200);
    }

    public function getPluginInfo(): array {
        return [
            'name' => 'roomvox-scheduling',
            'description' => 'Handles room booking scheduling via CalDAV',
        ];
    }

    public function getPluginName(): string {
        return 'roomvox-scheduling';
    }

    /**
     * Handle an incoming iTIP scheduling request.
     * Runs before Sabre's scheduleLocalDelivery. For room principals,
     * handles delivery and returns false to stop propagation.
     * For non-room recipients, returns void to let Sabre handle normally.
     */
    public function handleScheduleRequest(ITip\Message $message): ?bool {
        $recipient = $message->recipient;

        // Only process messages for room accounts
        if (!$this->roomService->isRoomPrincipal($recipient)) {
            $this->logger->debug("RoomVox: Passing through {$message->method} for non-room recipient {$recipient} (sender: {$message->sender})");
            return null; // Let Sabre handle non-room recipients
        }

        $roomId = $this->roomService->getRoomIdByPrincipal($recipient);
        if ($roomId === null) {
            return null;
        }

        $room = $this->roomService->getRoom($roomId);
        if ($room === null || !($room['active'] ?? true)) {
            $this->logger->debug("RoomVox: Ignoring request for inactive/missing room {$roomId}");
            return null;
        }

        $method = strtoupper($message->method ?? '');
        $this->logger->info("RoomVox: Processing {$method} for room {$roomId} from {$message->sender}");

        match ($method) {
            'REQUEST' => $this->handleRequest($message, $room),
            'CANCEL' => $this->handleCancel($message, $room),
            default => null,
        };

        // Remember the PARTSTAT we set so fixOrganizerEvent can write it
        // back into the organizer's event (Sabre won't do this since we
        // return false to stop its scheduleLocalDelivery).
        $roomEmail = strtolower($room['email'] ?? '');
        if ($roomEmail !== '' && $message->message !== null) {
            $vEvent = $message->message->VEVENT ?? null;
            if ($vEvent !== null) {
                foreach ($vEvent->select('ATTENDEE') as $att) {
                    if (strtolower(RoomService::stripMailto((string)$att)) === $roomEmail) {
                        $ps = isset($att['PARTSTAT']) ? (string)$att['PARTSTAT'] : null;
                        if ($ps !== null) {
                            $this->scheduledPartstats[$roomEmail] = $ps;
                        }
                        break;
                    }
                }
            }
        }

        // Return false to stop event propagation — we've handled delivery,
        // so Sabre's scheduleLocalDelivery should NOT run for this message.
        return false;
    }

    /**
     * Handle a booking REQUEST (new booking or update)
     */
    private function handleRequest(ITip\Message $message, array $room): void {
        $senderId = $this->extractUserId($message->sender);
        $roomId = $room['id'];

        // 1. Permission check
        $perms = $this->permissionService->getPermissions($roomId);
        $hasPermissions = !empty($perms['viewers']) || !empty($perms['bookers']) || !empty($perms['managers']);

        if ($hasPermissions) {
            if ($senderId === null) {
                // Sender could not be resolved to a NC user — deny when permissions are configured
                $this->logger->info("RoomVox: Booking denied for unknown sender {$message->sender} on room {$roomId} — could not resolve user");
                $message->scheduleStatus = '3.7'; // Delivery refused
                $this->setPartstat($message, 'DECLINED');
                return;
            }
            if (!$this->permissionService->canBook($senderId, $roomId)) {
                $this->logger->info("RoomVox: Booking denied for {$senderId} on room {$roomId} — no permission");
                $message->scheduleStatus = '3.7'; // Delivery refused
                $this->setPartstat($message, 'DECLINED');
                return;
            }
        }

        // 2. Extract event data (used by availability + conflict check)
        $vEvent = $this->extractVEvent($message);
        $dtStart = null;
        $dtEnd = null;
        $uid = '';
        if ($vEvent !== null) {
            $dtStart = $vEvent->DTSTART ? $vEvent->DTSTART->getDateTime() : null;
            $dtEnd = $vEvent->DTEND ? $vEvent->DTEND->getDateTime() : null;
            $uid = (string)($vEvent->UID ?? '');
        }

        // 3. Availability check
        if ($dtStart !== null && $dtEnd !== null && !$this->isWithinAvailability($room, $dtStart, $dtEnd)) {
            $this->logger->info("RoomVox: Booking outside availability hours for room {$roomId}");
            $message->scheduleStatus = '3.7';
            $this->setPartstat($message, 'DECLINED');
            return;
        }

        // 3b. Max booking horizon check
        if ($vEvent !== null && !$this->isWithinHorizon($room, $vEvent)) {
            $this->logger->info("RoomVox: Booking exceeds max horizon for room {$roomId}");
            $message->scheduleStatus = '3.7';
            $this->setPartstat($message, 'DECLINED');
            return;
        }

        // 4. Conflict check
        if ($vEvent !== null) {
            if ($dtStart !== null && $dtEnd !== null) {
                if ($this->calDAVService->hasConflict($room['userId'], $dtStart, $dtEnd, $uid)) {
                    $this->logger->info("RoomVox: Conflict detected for room {$roomId}");
                    $message->scheduleStatus = '3.0'; // Delivery failed (conflict)
                    $this->setPartstat($message, 'DECLINED');

                    try {
                        $this->mailService->sendConflict($room, $message);
                    } catch (\Throwable $e) {
                        $this->logger->error("RoomVox: Failed to send conflict email: " . $e->getMessage());
                    }
                    return;
                }
            }
        }

        // 5. Determine PARTSTAT based on auto-accept setting
        if ($room['autoAccept'] ?? false) {
            $partstat = 'ACCEPTED';
            $this->logger->info("RoomVox: Auto-accepting booking for room {$roomId}");
        } else {
            $partstat = 'TENTATIVE';
            $this->logger->info("RoomVox: Booking for room {$roomId} requires approval");
        }

        // 6. Fix room attendee metadata and add LOCATION
        $this->enrichRoomAttendee($message, $room);

        // 5. Set PARTSTAT on the message before delivery
        $this->setPartstat($message, $partstat);

        // 6. Deliver the event to the room calendar
        $calendarData = $message->message ? $message->message->serialize() : null;
        if ($calendarData !== null) {
            $delivered = $this->calDAVService->deliverToRoomCalendar($room['userId'], $calendarData);
            if (!$delivered) {
                $this->logger->error("RoomVox: Failed to deliver to room calendar for {$roomId}");
                $message->scheduleStatus = '5.0'; // Delivery error
                return;
            }
        }

        // 7. Set success status
        $message->scheduleStatus = '1.2'; // Delivered successfully

        // 8. Send notifications
        try {
            if ($partstat === 'ACCEPTED') {
                $this->mailService->sendAccepted($room, $message);
            } else {
                $this->mailService->notifyManagers($room, $message);
            }
        } catch (\Throwable $e) {
            $this->logger->error("RoomVox: Failed to send notification email: " . $e->getMessage());
        }
    }

    /**
     * Handle a CANCEL (booking cancelled by organizer)
     */
    private function handleCancel(ITip\Message $message, array $room): void {
        $this->logger->info("RoomVox: Booking cancelled for room {$room['id']}");

        // Delete from room calendar
        $vEvent = $this->extractVEvent($message);
        if ($vEvent !== null) {
            $uid = (string)($vEvent->UID ?? '');
            if ($uid !== '') {
                $this->calDAVService->deleteFromRoomCalendar($room['userId'], $uid);
            }
        }

        $message->scheduleStatus = '1.2'; // Delivered

        try {
            $this->mailService->sendCancelled($room, $message);
        } catch (\Throwable $e) {
            $this->logger->error("RoomVox: Failed to send cancellation email: " . $e->getMessage());
        }
    }

    /**
     * After a calendar object is written, fix room attendees in the
     * organizer's event:
     * - iOS: CUTYPE=INDIVIDUAL → CUTYPE=ROOM, add LOCATION
     * - eM Client: LOCATION matches room name but no ATTENDEE → add room as ATTENDEE + deliver
     */
    public function fixOrganizerEvent(string $path): void {
        try {
            // Only process .ics files in calendar paths
            if (!str_ends_with($path, '.ics') || !str_contains($path, 'calendars/')) {
                return;
            }

            $node = $this->server->tree->getNodeForPath($path);
            if (!($node instanceof \Sabre\CalDAV\ICalendarObject)) {
                return;
            }

            $data = $node->get();
            if (is_resource($data)) {
                $data = stream_get_contents($data);
            }
            if (empty($data)) {
                return;
            }

            $vObject = \Sabre\VObject\Reader::read($data);
            $vEvent = $vObject->VEVENT ?? null;
            if ($vEvent === null) {
                return;
            }

            $changed = false;
            $roomEmails = [];
            $attendees = $vEvent->select('ATTENDEE');

            // 1. Fix existing room attendees: CUTYPE + PARTSTAT write-back
            foreach ($attendees as $attendee) {
                $email = strtolower(RoomService::stripMailto((string)$attendee));
                $cutype = isset($attendee['CUTYPE']) ? (string)$attendee['CUTYPE'] : '';

                if ($this->roomService->isRoomPrincipal('mailto:' . $email)) {
                    $roomEmails[] = $email;

                    if ($cutype !== 'ROOM') {
                        $attendee['CUTYPE'] = 'ROOM';
                        $changed = true;
                    }

                    // Write back PARTSTAT that was set during scheduling
                    // (Sabre doesn't do this because we handle delivery ourselves)
                    if (isset($this->scheduledPartstats[$email])) {
                        $currentPartstat = isset($attendee['PARTSTAT']) ? (string)$attendee['PARTSTAT'] : '';
                        if ($currentPartstat !== $this->scheduledPartstats[$email]) {
                            $attendee['PARTSTAT'] = $this->scheduledPartstats[$email];
                            $changed = true;
                            $this->logger->info("RoomVox: Updated PARTSTAT to {$this->scheduledPartstats[$email]} for room {$email} in organizer event {$path}");
                        }
                    }
                }
            }

            // 2. eM Client fix: LOCATION matches a room name but no room ATTENDEE
            //    → add the room as ATTENDEE and deliver to room calendar
            if (empty($roomEmails)) {
                $location = strtolower(trim((string)($vEvent->LOCATION ?? '')));
                if ($location !== '') {
                    $matchedRoom = $this->findRoomByLocation($location);
                    if ($matchedRoom !== null) {
                        $roomEmail = $matchedRoom['email'];

                        // Add ORGANIZER if missing (needed for scheduling)
                        if (!isset($vEvent->ORGANIZER)) {
                            // Try to get organizer from the calendar path
                            $pathParts = explode('/', $path);
                            // path = calendars/<user>/<calendar>/<uid>.ics
                            $calendarOwner = $pathParts[1] ?? null;
                            if ($calendarOwner !== null) {
                                $vEvent->add('ORGANIZER', 'mailto:' . $calendarOwner);
                            }
                        }

                        // Add room as ATTENDEE
                        $vEvent->add('ATTENDEE', 'mailto:' . $roomEmail, [
                            'CN' => $matchedRoom['name'],
                            'CUTYPE' => 'ROOM',
                            'ROLE' => 'REQ-PARTICIPANT',
                            'PARTSTAT' => 'NEEDS-ACTION',
                        ]);

                        // Update LOCATION to include room location
                        $vEvent->LOCATION = $this->roomService->buildRoomLocation($matchedRoom);

                        $changed = true;
                        $this->logger->info("RoomVox: Added room {$matchedRoom['id']} as ATTENDEE from LOCATION match");

                        // Save first, then trigger scheduling
                        $node->put($vObject->serialize());

                        // Now trigger scheduling for the room
                        $this->scheduleRoomFromLocation($vObject, $matchedRoom);
                        return; // Already saved
                    }
                }
            }

            // 3. Add LOCATION if room attendees exist but no location
            if (!empty($roomEmails)) {
                $location = (string)($vEvent->LOCATION ?? '');
                if ($location === '') {
                    $roomEmail = $roomEmails[0];
                    $rooms = $this->roomService->getAllRooms();
                    foreach ($rooms as $room) {
                        if (strtolower($room['email'] ?? '') === $roomEmail) {
                            $vEvent->LOCATION = $this->roomService->buildRoomLocation($room);
                            $changed = true;
                            break;
                        }
                    }
                }
            }

            if ($changed) {
                $node->put($vObject->serialize());
                $this->logger->info("RoomVox: Fixed CUTYPE/LOCATION in organizer event {$path}");
            }
        } catch (\Throwable $e) {
            $this->logger->debug("RoomVox: fixOrganizerEvent skipped: " . $e->getMessage());
        }
    }

    /**
     * Find a room by matching LOCATION text against room names/emails
     */
    private function findRoomByLocation(string $location): ?array {
        $location = strtolower(trim($location));
        $rooms = $this->roomService->getAllRooms();

        foreach ($rooms as $room) {
            if (!($room['active'] ?? true)) {
                continue;
            }
            // Match on room name, email (without domain), email prefix,
            // or combined "Name — Location" format (as shown in CalDAV clients)
            $roomName = strtolower($room['name'] ?? '');
            $roomEmail = strtolower($room['email'] ?? '');
            $emailLocal = explode('@', $roomEmail)[0] ?? '';
            $nameWithLocation = strtolower($this->roomService->buildRoomLocation($room));

            if ($location === $roomName || $location === $roomEmail || $location === $emailLocal || $location === $nameWithLocation) {
                return $room;
            }
        }

        return null;
    }

    /**
     * Manually trigger room scheduling for events that had no ATTENDEE
     * (e.g. eM Client only sets LOCATION).
     */
    private function scheduleRoomFromLocation(\Sabre\VObject\Component\VCalendar $vObject, array $room): void {
        try {
            $vEvent = $vObject->VEVENT ?? null;
            if ($vEvent === null) {
                return;
            }

            $roomId = $room['id'];

            // Permission check (skip — LOCATION-based bookings have no sender context)

            // Conflict check
            $dtStart = $vEvent->DTSTART ? $vEvent->DTSTART->getDateTime() : null;
            $dtEnd = $vEvent->DTEND ? $vEvent->DTEND->getDateTime() : null;
            $uid = (string)($vEvent->UID ?? '');

            if ($dtStart !== null && $dtEnd !== null) {
                if ($this->calDAVService->hasConflict($room['userId'], $dtStart, $dtEnd, $uid)) {
                    $this->logger->info("RoomVox: Conflict detected for room {$roomId} (LOCATION booking)");
                    return;
                }
            }

            // Auto-accept or tentative
            $partstat = ($room['autoAccept'] ?? false) ? 'ACCEPTED' : 'TENTATIVE';

            // Update PARTSTAT on the room attendee in the vObject
            $roomEmail = strtolower($room['email']);
            $attendees = $vEvent->select('ATTENDEE');
            foreach ($attendees as $attendee) {
                $email = strtolower(RoomService::stripMailto((string)$attendee));
                if ($email === $roomEmail) {
                    $attendee['PARTSTAT'] = $partstat;
                    break;
                }
            }

            // Deliver to room calendar
            $this->calDAVService->deliverToRoomCalendar($room['userId'], $vObject->serialize());

            $this->logger->info("RoomVox: Room {$roomId} booked via LOCATION match (partstat={$partstat})");
        } catch (\Throwable $e) {
            $this->logger->error("RoomVox: Failed to schedule room from LOCATION: " . $e->getMessage());
        }
    }

    /**
     * Check if a booking (including recurring instances) falls within the
     * room's maximum booking horizon. Returns true if no horizon is set
     * or if the booking's furthest date is within the allowed range.
     *
     * @param array $room Room data with optional maxBookingHorizon (in days)
     * @param \Sabre\VObject\Component\VEvent $vEvent The event to check
     */
    private function isWithinHorizon(array $room, \Sabre\VObject\Component\VEvent $vEvent): bool {
        $maxDays = (int)($room['maxBookingHorizon'] ?? 0);
        if ($maxDays <= 0) {
            return true; // No restriction
        }

        $horizon = new \DateTimeImmutable('+' . $maxDays . ' days');

        // For non-recurring events, just check DTEND/DTSTART
        $rrule = $vEvent->RRULE ?? null;
        if ($rrule === null) {
            $dtEnd = $vEvent->DTEND ? $vEvent->DTEND->getDateTime() : null;
            $dtStart = $vEvent->DTSTART ? $vEvent->DTSTART->getDateTime() : null;
            $lastDate = $dtEnd ?? $dtStart;
            if ($lastDate === null) {
                return true;
            }
            return $lastDate <= $horizon;
        }

        // Recurring event: check UNTIL or calculate from COUNT
        $rruleStr = (string)$rrule;
        $parts = [];
        foreach (explode(';', $rruleStr) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $parts[strtoupper($kv[0])] = $kv[1];
            }
        }

        // If UNTIL is set, check it directly
        if (!empty($parts['UNTIL'])) {
            try {
                $until = new \DateTimeImmutable($parts['UNTIL']);
                return $until <= $horizon;
            } catch (\Exception $e) {
                // Couldn't parse UNTIL, be conservative and allow
                return true;
            }
        }

        // If COUNT is set, estimate the last occurrence
        if (!empty($parts['COUNT'])) {
            $count = (int)$parts['COUNT'];
            $freq = strtoupper($parts['FREQ'] ?? 'WEEKLY');
            $interval = (int)($parts['INTERVAL'] ?? 1);
            if ($interval < 1) $interval = 1;

            $dtStart = $vEvent->DTSTART ? $vEvent->DTSTART->getDateTime() : null;
            if ($dtStart === null) {
                return true;
            }

            $startDt = \DateTimeImmutable::createFromInterface($dtStart);

            // Estimate last occurrence based on frequency
            $totalIntervals = ($count - 1) * $interval;
            $lastOccurrence = match ($freq) {
                'DAILY' => $startDt->modify('+' . $totalIntervals . ' days'),
                'WEEKLY' => $startDt->modify('+' . ($totalIntervals * 7) . ' days'),
                'MONTHLY' => $startDt->modify('+' . $totalIntervals . ' months'),
                'YEARLY' => $startDt->modify('+' . $totalIntervals . ' years'),
                default => $startDt->modify('+' . ($totalIntervals * 7) . ' days'),
            };

            return $lastOccurrence <= $horizon;
        }

        // RRULE with neither UNTIL nor COUNT = infinite recurrence → always exceeds horizon
        $this->logger->info("RoomVox: Recurring event without UNTIL or COUNT — exceeds horizon");
        return false;
    }

    /**
     * Check if a booking falls within the room's availability rules.
     * Returns true if no rules are configured or if the booking fits within at least one rule.
     */
    private function isWithinAvailability(array $room, \DateTimeInterface $start, \DateTimeInterface $end): bool {
        $rules = $room['availabilityRules'] ?? [];
        if (empty($rules['enabled']) || empty($rules['rules'])) {
            return true; // No restrictions
        }

        foreach ($rules['rules'] as $rule) {
            if ($this->bookingFitsRule($start, $end, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a booking fits entirely within a single availability rule.
     * The booking's start and end must fall on allowed days and within the time window.
     */
    private function bookingFitsRule(\DateTimeInterface $start, \DateTimeInterface $end, array $rule): bool {
        $allowedDays = $rule['days'] ?? [];
        $ruleStart = $rule['startTime'] ?? '00:00';
        $ruleEnd = $rule['endTime'] ?? '23:59';

        if (empty($allowedDays)) {
            return false;
        }

        // Get day of week (0=Sunday, 6=Saturday) matching our data model
        $startDay = (int)$start->format('w');
        $endDay = (int)$end->format('w');
        $startTime = $start->format('H:i');
        $endTime = $end->format('H:i');

        // Check if start and end are on the same day
        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');

        if ($startDate === $endDate) {
            // Same day: check day is allowed and times fit
            return in_array($startDay, $allowedDays, true)
                && $startTime >= $ruleStart
                && $endTime <= $ruleEnd;
        }

        // Multi-day booking: check all days in range
        $current = \DateTimeImmutable::createFromInterface($start);
        $endDt = \DateTimeImmutable::createFromInterface($end);

        while ($current->format('Y-m-d') <= $endDt->format('Y-m-d')) {
            $day = (int)$current->format('w');
            if (!in_array($day, $allowedDays, true)) {
                return false;
            }

            // First day: start time must be >= rule start
            if ($current->format('Y-m-d') === $startDate) {
                if ($current->format('H:i') < $ruleStart) {
                    return false;
                }
            }

            // Last day: end time must be <= rule end
            if ($current->format('Y-m-d') === $endDate) {
                if ($endDt->format('H:i') > $ruleEnd) {
                    return false;
                }
            }

            $current = $current->modify('+1 day')->setTime(0, 0);
        }

        return true;
    }

    /**
     * Enrich the room attendee in the iTIP message:
     * - Fix CUTYPE from INDIVIDUAL to ROOM (iOS sends INDIVIDUAL)
     * - Add LOCATION if missing
     */
    private function enrichRoomAttendee(ITip\Message $message, array $room): void {
        if ($message->message === null) {
            return;
        }

        try {
            $vEvent = $message->message->VEVENT ?? null;
            if ($vEvent === null) {
                return;
            }

            $recipientEmail = strtolower(RoomService::stripMailto($message->recipient));

            // Fix CUTYPE on room attendee
            $attendees = $vEvent->select('ATTENDEE');
            foreach ($attendees as $attendee) {
                $email = strtolower(RoomService::stripMailto((string)$attendee));
                if ($email === $recipientEmail) {
                    $cutype = isset($attendee['CUTYPE']) ? (string)$attendee['CUTYPE'] : '';
                    if ($cutype !== 'ROOM') {
                        $attendee['CUTYPE'] = 'ROOM';
                    }
                    break;
                }
            }

            // Add LOCATION if not present
            $location = (string)($vEvent->LOCATION ?? '');
            if ($location === '') {
                $vEvent->LOCATION = $this->roomService->buildRoomLocation($room);
            }
        } catch (\Throwable $e) {
            $this->logger->warning("RoomVox: Failed to enrich room attendee: " . $e->getMessage());
        }
    }

    /**
     * Set PARTSTAT for the room attendee in the iTIP message
     */
    private function setPartstat(ITip\Message $message, string $partstat): void {
        if ($message->message === null) {
            return;
        }

        try {
            $vEvent = $message->message->VEVENT ?? null;
            if ($vEvent === null) {
                return;
            }

            $attendees = $vEvent->select('ATTENDEE');
            foreach ($attendees as $attendee) {
                $email = RoomService::stripMailto((string)$attendee);
                $recipientEmail = RoomService::stripMailto($message->recipient);

                if (strtolower($email) === strtolower($recipientEmail)) {
                    $attendee['PARTSTAT'] = $partstat;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning("RoomVox: Failed to set PARTSTAT: " . $e->getMessage());
        }
    }

    /**
     * Extract VEVENT from iTIP message
     */
    private function extractVEvent(ITip\Message $message): ?\Sabre\VObject\Component\VEvent {
        if ($message->message === null) {
            return null;
        }

        return $message->message->VEVENT ?? null;
    }

    /**
     * Extract user ID from principal URI or mailto
     */
    private function extractUserId(string $sender): ?string {
        // Handle principals/users/xxx format
        $prefix = 'principals/users/';
        if (str_starts_with($sender, $prefix)) {
            return substr($sender, strlen($prefix));
        }

        // Handle mailto: format — find Nextcloud user by email
        if (str_starts_with(strtolower($sender), 'mailto:')) {
            $email = substr($sender, 7);
            $users = $this->userManager->getByEmail($email);
            if (count($users) === 1) {
                return $users[0]->getUID();
            }
            // Multiple matches or none: return null (handled by caller)
            $this->logger->debug("RoomVox: Could not resolve mailto:{$email} to a unique user (found " . count($users) . ")");
            return null;
        }

        return null;
    }

}
