<?php

declare(strict_types=1);

namespace OCA\RoomBooking\Dav;

use OCA\RoomBooking\Service\CalDAVService;
use OCA\RoomBooking\Service\MailService;
use OCA\RoomBooking\Service\PermissionService;
use OCA\RoomBooking\Service\RoomService;
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

    public function __construct(
        private RoomService $roomService,
        private PermissionService $permissionService,
        private CalDAVService $calDAVService,
        private MailService $mailService,
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
            'name' => 'roombooking-scheduling',
            'description' => 'Handles room booking scheduling via CalDAV',
        ];
    }

    public function getPluginName(): string {
        return 'roombooking-scheduling';
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
            return null; // Let Sabre handle non-room recipients
        }

        $roomId = $this->roomService->getRoomIdByPrincipal($recipient);
        if ($roomId === null) {
            return null;
        }

        $room = $this->roomService->getRoom($roomId);
        if ($room === null || !($room['active'] ?? true)) {
            $this->logger->debug("RoomBooking: Ignoring request for inactive/missing room {$roomId}");
            return null;
        }

        $method = strtoupper($message->method ?? '');
        $this->logger->info("RoomBooking: Processing {$method} for room {$roomId} from {$message->sender}");

        match ($method) {
            'REQUEST' => $this->handleRequest($message, $room),
            'CANCEL' => $this->handleCancel($message, $room),
            default => null,
        };

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
        if ($senderId !== null && !$this->permissionService->canBook($senderId, $roomId)) {
            $this->logger->info("RoomBooking: Booking denied for {$senderId} on room {$roomId} — no permission");
            $message->scheduleStatus = '3.7'; // Delivery refused
            $this->setPartstat($message, 'DECLINED');
            return;
        }

        // 2. Conflict check
        $vEvent = $this->extractVEvent($message);
        if ($vEvent !== null) {
            $dtStart = $vEvent->DTSTART ? $vEvent->DTSTART->getDateTime() : null;
            $dtEnd = $vEvent->DTEND ? $vEvent->DTEND->getDateTime() : null;
            $uid = (string)($vEvent->UID ?? '');

            if ($dtStart !== null && $dtEnd !== null) {
                if ($this->calDAVService->hasConflict($room['userId'], $dtStart, $dtEnd, $uid)) {
                    $this->logger->info("RoomBooking: Conflict detected for room {$roomId}");
                    $message->scheduleStatus = '3.0'; // Delivery failed (conflict)
                    $this->setPartstat($message, 'DECLINED');

                    try {
                        $this->mailService->sendConflict($room, $message);
                    } catch (\Throwable $e) {
                        $this->logger->error("RoomBooking: Failed to send conflict email: " . $e->getMessage());
                    }
                    return;
                }
            }
        }

        // 3. Determine PARTSTAT based on auto-accept setting
        if ($room['autoAccept'] ?? false) {
            $partstat = 'ACCEPTED';
            $this->logger->info("RoomBooking: Auto-accepting booking for room {$roomId}");
        } else {
            $partstat = 'TENTATIVE';
            $this->logger->info("RoomBooking: Booking for room {$roomId} requires approval");
        }

        // 4. Fix room attendee metadata and add LOCATION
        $this->enrichRoomAttendee($message, $room);

        // 5. Set PARTSTAT on the message before delivery
        $this->setPartstat($message, $partstat);

        // 6. Deliver the event to the room calendar
        $calendarData = $message->message ? $message->message->serialize() : null;
        if ($calendarData !== null) {
            $delivered = $this->calDAVService->deliverToRoomCalendar($room['userId'], $calendarData);
            if (!$delivered) {
                $this->logger->error("RoomBooking: Failed to deliver to room calendar for {$roomId}");
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
            $this->logger->error("RoomBooking: Failed to send notification email: " . $e->getMessage());
        }
    }

    /**
     * Handle a CANCEL (booking cancelled by organizer)
     */
    private function handleCancel(ITip\Message $message, array $room): void {
        $this->logger->info("RoomBooking: Booking cancelled for room {$room['id']}");

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
            $this->logger->error("RoomBooking: Failed to send cancellation email: " . $e->getMessage());
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

            // 1. Fix existing room attendees with wrong CUTYPE (iOS fix)
            foreach ($attendees as $attendee) {
                $email = strtolower($this->stripMailto((string)$attendee));
                $cutype = isset($attendee['CUTYPE']) ? (string)$attendee['CUTYPE'] : '';

                if ($this->roomService->isRoomPrincipal('mailto:' . $email)) {
                    $roomEmails[] = $email;

                    if ($cutype !== 'ROOM') {
                        $attendee['CUTYPE'] = 'ROOM';
                        $changed = true;
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
                        if (!empty($matchedRoom['location'])) {
                            $vEvent->LOCATION = $matchedRoom['name'] . ' — ' . $matchedRoom['location'];
                        }

                        $changed = true;
                        $this->logger->info("RoomBooking: Added room {$matchedRoom['id']} as ATTENDEE from LOCATION match");

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
                            $roomLocation = $room['name'];
                            if (!empty($room['location'])) {
                                $roomLocation .= ' — ' . $room['location'];
                            }
                            $vEvent->LOCATION = $roomLocation;
                            $changed = true;
                            break;
                        }
                    }
                }
            }

            if ($changed) {
                $node->put($vObject->serialize());
                $this->logger->info("RoomBooking: Fixed CUTYPE/LOCATION in organizer event {$path}");
            }
        } catch (\Throwable $e) {
            $this->logger->debug("RoomBooking: fixOrganizerEvent skipped: " . $e->getMessage());
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
            $nameWithLocation = !empty($room['location'])
                ? strtolower($room['name'] . ' — ' . $room['location'])
                : '';

            if ($location === $roomName || $location === $roomEmail || $location === $emailLocal || ($nameWithLocation !== '' && $location === $nameWithLocation)) {
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
                    $this->logger->info("RoomBooking: Conflict detected for room {$roomId} (LOCATION booking)");
                    return;
                }
            }

            // Auto-accept or tentative
            $partstat = ($room['autoAccept'] ?? false) ? 'ACCEPTED' : 'TENTATIVE';

            // Update PARTSTAT on the room attendee in the vObject
            $roomEmail = strtolower($room['email']);
            $attendees = $vEvent->select('ATTENDEE');
            foreach ($attendees as $attendee) {
                $email = strtolower($this->stripMailto((string)$attendee));
                if ($email === $roomEmail) {
                    $attendee['PARTSTAT'] = $partstat;
                    break;
                }
            }

            // Deliver to room calendar
            $this->calDAVService->deliverToRoomCalendar($room['userId'], $vObject->serialize());

            $this->logger->info("RoomBooking: Room {$roomId} booked via LOCATION match (partstat={$partstat})");
        } catch (\Throwable $e) {
            $this->logger->error("RoomBooking: Failed to schedule room from LOCATION: " . $e->getMessage());
        }
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

            $recipientEmail = strtolower($this->stripMailto($message->recipient));

            // Fix CUTYPE on room attendee
            $attendees = $vEvent->select('ATTENDEE');
            foreach ($attendees as $attendee) {
                $email = strtolower($this->stripMailto((string)$attendee));
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
                $roomLocation = $room['name'];
                if (!empty($room['location'])) {
                    $roomLocation .= ' — ' . $room['location'];
                }
                $vEvent->LOCATION = $roomLocation;
            }
        } catch (\Throwable $e) {
            $this->logger->warning("RoomBooking: Failed to enrich room attendee: " . $e->getMessage());
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
                $email = $this->stripMailto((string)$attendee);
                $recipientEmail = $this->stripMailto($message->recipient);

                if (strtolower($email) === strtolower($recipientEmail)) {
                    $attendee['PARTSTAT'] = $partstat;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning("RoomBooking: Failed to set PARTSTAT: " . $e->getMessage());
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

        // Handle mailto: format — try to find user by email
        if (str_starts_with(strtolower($sender), 'mailto:')) {
            return null;
        }

        return null;
    }

    /**
     * Strip mailto: prefix
     */
    private function stripMailto(string $email): string {
        if (str_starts_with(strtolower($email), 'mailto:')) {
            return substr($email, 7);
        }
        return $email;
    }
}
