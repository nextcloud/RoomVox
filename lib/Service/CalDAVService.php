<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

class CalDAVService {
    public function __construct(
        private CalDavBackend $calDavBackend,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Create a calendar for a room service account
     */
    public function provisionCalendar(string $roomUserId, string $roomName): string {
        $principalUri = 'principals/users/' . $roomUserId;
        $calendarUri = 'room-' . $roomUserId;

        try {
            $this->calDavBackend->createCalendar(
                $principalUri,
                $calendarUri,
                [
                    '{DAV:}displayname' => $roomName,
                    '{http://apple.com/ns/ical/}calendar-color' => '#2E86C1',
                    '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp'
                        => new \Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp('opaque'),
                ]
            );

            $this->logger->info("Calendar provisioned for room: {$roomUserId} (uri: {$calendarUri})");
        } catch (\Exception $e) {
            $this->logger->error("Failed to provision calendar for {$roomUserId}: " . $e->getMessage());
            throw $e;
        }

        return $calendarUri;
    }

    /**
     * Delete the calendar for a room
     */
    public function deleteCalendar(string $roomUserId): void {
        $calendarId = $this->getCalendarId($roomUserId);
        if ($calendarId === null) {
            $this->logger->warning("No calendar found for room: {$roomUserId}");
            return;
        }

        try {
            $this->calDavBackend->deleteCalendar($calendarId);
            $this->logger->info("Calendar deleted for room: {$roomUserId}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to delete calendar for {$roomUserId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get all bookings (events) from a room's calendar
     */
    public function getBookings(string $roomUserId, ?string $from = null, ?string $to = null): array {
        $calendarId = $this->getRoomCalendarId($roomUserId);
        if ($calendarId === null) {
            return [];
        }

        $objects = $this->calDavBackend->getCalendarObjects($calendarId);
        $bookings = [];

        foreach ($objects as $object) {
            $fullObject = $this->calDavBackend->getCalendarObject($calendarId, $object['uri']);
            if ($fullObject === null) {
                continue;
            }

            $calendarData = $fullObject['calendardata'] ?? '';
            if (empty($calendarData)) {
                continue;
            }

            try {
                $vObject = Reader::read($calendarData);
                $vEvent = $vObject->VEVENT ?? null;
                if ($vEvent === null) {
                    continue;
                }

                $dtStart = $vEvent->DTSTART ? $vEvent->DTSTART->getDateTime() : null;
                $dtEnd = $vEvent->DTEND ? $vEvent->DTEND->getDateTime() : null;

                // Filter by date range if provided
                if ($from !== null && $dtEnd !== null) {
                    $fromDate = new \DateTime($from);
                    if ($dtEnd < $fromDate) {
                        continue;
                    }
                }
                if ($to !== null && $dtStart !== null) {
                    $toDate = new \DateTime($to);
                    if ($dtStart > $toDate) {
                        continue;
                    }
                }

                // Extract organizer
                $organizer = '';
                $organizerName = '';
                if ($vEvent->ORGANIZER) {
                    $organizer = RoomService::stripMailto((string)$vEvent->ORGANIZER);
                    $organizerName = isset($vEvent->ORGANIZER['CN']) ? (string)$vEvent->ORGANIZER['CN'] : $organizer;
                }

                // Extract PARTSTAT of room attendee
                // First try CUTYPE=ROOM, then fall back to non-organizer attendee
                // (some clients like iOS send CUTYPE=INDIVIDUAL for rooms)
                $partstat = 'NEEDS-ACTION';
                $organizerEmail = $organizer ? strtolower(RoomService::stripMailto($organizer)) : '';
                $attendees = $vEvent->select('ATTENDEE');
                $fallbackPartstat = null;
                foreach ($attendees as $attendee) {
                    $cutype = isset($attendee['CUTYPE']) ? (string)$attendee['CUTYPE'] : '';
                    if ($cutype === 'ROOM') {
                        $partstat = isset($attendee['PARTSTAT']) ? (string)$attendee['PARTSTAT'] : 'NEEDS-ACTION';
                        $fallbackPartstat = null;
                        break;
                    }
                    // Track non-organizer attendee as fallback
                    $attendeeEmail = strtolower(RoomService::stripMailto((string)$attendee));
                    if ($attendeeEmail !== $organizerEmail && $fallbackPartstat === null) {
                        $fallbackPartstat = isset($attendee['PARTSTAT']) ? (string)$attendee['PARTSTAT'] : 'NEEDS-ACTION';
                    }
                }
                if ($fallbackPartstat !== null) {
                    $partstat = $fallbackPartstat;
                }

                $bookings[] = [
                    'uid' => (string)($vEvent->UID ?? $object['uri']),
                    'uri' => $object['uri'],
                    'summary' => (string)($vEvent->SUMMARY ?? ''),
                    'description' => (string)($vEvent->DESCRIPTION ?? ''),
                    'dtstart' => $dtStart ? $dtStart->format('c') : null,
                    'dtend' => $dtEnd ? $dtEnd->format('c') : null,
                    'organizer' => $organizer,
                    'organizerName' => $organizerName,
                    'partstat' => $partstat,
                    'status' => (string)($vEvent->STATUS ?? ''),
                    'location' => (string)($vEvent->LOCATION ?? ''),
                ];
            } catch (\Exception $e) {
                $this->logger->warning("Failed to parse calendar object {$object['uri']}: " . $e->getMessage());
                continue;
            }
        }

        // Sort by start date
        usort($bookings, function ($a, $b) {
            return ($a['dtstart'] ?? '') <=> ($b['dtstart'] ?? '');
        });

        return $bookings;
    }

    /**
     * Update the PARTSTAT of a room attendee in a booking
     */
    public function updateBookingPartstat(string $roomUserId, string $bookingUid, string $partstat): bool {
        $calendarId = $this->getRoomCalendarId($roomUserId);
        if ($calendarId === null) {
            return false;
        }

        $objects = $this->calDavBackend->getCalendarObjects($calendarId);

        foreach ($objects as $object) {
            $fullObject = $this->calDavBackend->getCalendarObject($calendarId, $object['uri']);
            if ($fullObject === null) {
                continue;
            }

            $calendarData = $fullObject['calendardata'] ?? '';
            if (empty($calendarData)) {
                continue;
            }

            try {
                $vObject = Reader::read($calendarData);
                $vEvent = $vObject->VEVENT ?? null;
                if ($vEvent === null) {
                    continue;
                }

                $uid = (string)($vEvent->UID ?? '');
                if ($uid !== $bookingUid) {
                    continue;
                }

                // Update PARTSTAT for room attendee (CUTYPE=ROOM or non-organizer fallback)
                $orgEmail = '';
                if ($vEvent->ORGANIZER) {
                    $orgEmail = strtolower(RoomService::stripMailto((string)$vEvent->ORGANIZER));
                }
                $attendees = $vEvent->select('ATTENDEE');
                $updated = false;
                foreach ($attendees as $attendee) {
                    $cutype = isset($attendee['CUTYPE']) ? (string)$attendee['CUTYPE'] : '';
                    if ($cutype === 'ROOM') {
                        $attendee['PARTSTAT'] = $partstat;
                        $updated = true;
                        break;
                    }
                }
                if (!$updated) {
                    // Fallback: update first non-organizer attendee
                    foreach ($attendees as $attendee) {
                        $attendeeEmail = strtolower(RoomService::stripMailto((string)$attendee));
                        if ($attendeeEmail !== $orgEmail) {
                            $attendee['PARTSTAT'] = $partstat;
                            break;
                        }
                    }
                }

                // Update STATUS based on partstat
                if ($partstat === 'ACCEPTED') {
                    $vEvent->STATUS = 'CONFIRMED';
                } elseif ($partstat === 'DECLINED') {
                    $vEvent->STATUS = 'CANCELLED';
                }

                $this->calDavBackend->updateCalendarObject(
                    $calendarId,
                    $object['uri'],
                    $vObject->serialize()
                );

                $this->logger->info("Updated booking {$bookingUid} PARTSTAT to {$partstat}");
                return true;
            } catch (\Exception $e) {
                $this->logger->error("Failed to update booking {$bookingUid}: " . $e->getMessage());
                return false;
            }
        }

        $this->logger->warning("Booking not found: {$bookingUid}");
        return false;
    }

    /**
     * Deliver an iTIP message (iCalendar data) to the room's calendar.
     * Creates or updates the calendar object based on UID.
     */
    public function deliverToRoomCalendar(string $roomUserId, string $calendarData): bool {
        $calendarId = $this->getRoomCalendarId($roomUserId);
        if ($calendarId === null) {
            $this->logger->error("RoomVox: No calendar found for room {$roomUserId}, cannot deliver");
            return false;
        }

        try {
            $vObject = Reader::read($calendarData);
            $vEvent = $vObject->VEVENT ?? null;
            if ($vEvent === null) {
                $this->logger->error("RoomVox: No VEVENT in calendar data for delivery");
                return false;
            }

            $uid = (string)($vEvent->UID ?? '');
            if ($uid === '') {
                $this->logger->error("RoomVox: No UID in VEVENT for delivery");
                return false;
            }

            $objectUri = $uid . '.ics';

            // Check if this event already exists (update vs create)
            // First try by expected URI, then search by UID in case it was
            // stored with a different filename (e.g. from approval flow).
            $existing = null;
            $existingUri = $objectUri;
            try {
                $existing = $this->calDavBackend->getCalendarObject($calendarId, $objectUri);
            } catch (\Throwable $e) {
                // Not found by URI
            }

            if ($existing === null) {
                // Search all objects for matching UID
                $objects = $this->calDavBackend->getCalendarObjects($calendarId);
                foreach ($objects as $obj) {
                    if (isset($obj['uid']) && $obj['uid'] === $uid) {
                        $existingUri = $obj['uri'];
                        $existing = $obj;
                        break;
                    }
                }
            }

            if ($existing !== null) {
                $this->calDavBackend->updateCalendarObject($calendarId, $existingUri, $calendarData);
                $this->logger->info("RoomVox: Updated calendar object {$existingUri} in calendar {$calendarId}");
            } else {
                $this->calDavBackend->createCalendarObject($calendarId, $objectUri, $calendarData);
                $this->logger->info("RoomVox: Created calendar object {$objectUri} in calendar {$calendarId}");
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error("RoomVox: Failed to deliver to room calendar: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a booking from the room's calendar by UID.
     * Wrapper for deleteFromRoomCalendar for controller use.
     */
    public function deleteBooking(string $roomUserId, string $uid): bool {
        return $this->deleteFromRoomCalendar($roomUserId, $uid);
    }

    /**
     * Delete a calendar object from the room's calendar by UID.
     */
    public function deleteFromRoomCalendar(string $roomUserId, string $uid): bool {
        $calendarId = $this->getRoomCalendarId($roomUserId);
        if ($calendarId === null) {
            return false;
        }

        $objectUri = $uid . '.ics';

        try {
            $existing = $this->calDavBackend->getCalendarObject($calendarId, $objectUri);
            if ($existing !== null) {
                $this->calDavBackend->deleteCalendarObject($calendarId, $objectUri);
                $this->logger->info("RoomVox: Deleted calendar object {$objectUri} from calendar {$calendarId}");
                return true;
            }
        } catch (\Throwable $e) {
            $this->logger->error("RoomVox: Failed to delete from room calendar: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Check for conflicting bookings
     */
    public function hasConflict(string $roomUserId, \DateTimeInterface $start, \DateTimeInterface $end, ?string $excludeUid = null): bool {
        $calendarId = $this->getRoomCalendarId($roomUserId);
        if ($calendarId === null) {
            return false;
        }

        $objects = $this->calDavBackend->getCalendarObjects($calendarId);

        foreach ($objects as $object) {
            $fullObject = $this->calDavBackend->getCalendarObject($calendarId, $object['uri']);
            if ($fullObject === null) {
                continue;
            }

            try {
                $vObject = Reader::read($fullObject['calendardata'] ?? '');
                $vEvent = $vObject->VEVENT ?? null;
                if ($vEvent === null) {
                    continue;
                }

                // Skip excluded UID (for updates)
                if ($excludeUid !== null && (string)($vEvent->UID ?? '') === $excludeUid) {
                    continue;
                }

                // Skip cancelled/declined events
                $status = (string)($vEvent->STATUS ?? '');
                if ($status === 'CANCELLED') {
                    continue;
                }

                $eventStart = $vEvent->DTSTART ? $vEvent->DTSTART->getDateTime() : null;
                $eventEnd = $vEvent->DTEND ? $vEvent->DTEND->getDateTime() : null;

                if ($eventStart === null || $eventEnd === null) {
                    continue;
                }

                // Check overlap: events overlap if start < eventEnd AND end > eventStart
                if ($start < $eventEnd && $end > $eventStart) {
                    return true;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return false;
    }

    /**
     * Get the internal calendar ID for a room's user principal
     * Used for provisioning only.
     */
    public function getCalendarId(string $roomUserId): ?int {
        $principalUri = 'principals/users/' . $roomUserId;
        $calendars = $this->calDavBackend->getCalendarsForUser($principalUri);

        $targetUri = 'room-' . $roomUserId;

        foreach ($calendars as $calendar) {
            if (($calendar['uri'] ?? '') === $targetUri) {
                return (int)$calendar['id'];
            }
        }

        return null;
    }

    /**
     * Get the internal calendar ID for a room via calendar-rooms principal.
     * NC scheduling delivers events to principals/calendar-rooms/roomvox-<roomId>,
     * not to the user principal.
     */
    public function getRoomCalendarId(string $roomUserId): ?int {
        // Extract room ID from userId (rb_testroom → testroom)
        $roomId = str_starts_with($roomUserId, 'rb_') ? substr($roomUserId, 3) : $roomUserId;
        $principalUri = 'principals/calendar-rooms/roomvox-' . $roomId;

        $calendars = $this->calDavBackend->getCalendarsForUser($principalUri);

        foreach ($calendars as $calendar) {
            return (int)$calendar['id'];
        }

        // Fallback: try the user principal calendar
        $this->logger->debug("No calendar-rooms calendar found for {$roomUserId}, falling back to user principal");
        return $this->getCalendarId($roomUserId);
    }

    /**
     * Publish or remove a VAVAILABILITY object on the room's calendar.
     * CalDAV clients (Apple Calendar, Outlook) use this to show
     * when the room is available for booking.
     */
    public function publishAvailability(string $roomUserId, array $room): void {
        $calendarId = $this->getRoomCalendarId($roomUserId);
        if ($calendarId === null) {
            $this->logger->warning("RoomVox: No calendar found for {$roomUserId}, cannot publish availability");
            return;
        }

        $objectUri = 'room-availability.ics';
        $rules = $room['availabilityRules'] ?? [];
        $enabled = !empty($rules['enabled']) && !empty($rules['rules']);

        try {
            $existing = null;
            try {
                $existing = $this->calDavBackend->getCalendarObject($calendarId, $objectUri);
            } catch (\Throwable $e) {
                // Not found
            }

            if (!$enabled) {
                // Remove availability object if it exists
                if ($existing !== null) {
                    $this->calDavBackend->deleteCalendarObject($calendarId, $objectUri);
                    $this->logger->info("RoomVox: Removed VAVAILABILITY for {$roomUserId}");
                }
                return;
            }

            // Build VAVAILABILITY iCalendar data
            $icsData = $this->buildVAvailability($room);

            if ($existing !== null) {
                $this->calDavBackend->updateCalendarObject($calendarId, $objectUri, $icsData);
                $this->logger->info("RoomVox: Updated VAVAILABILITY for {$roomUserId}");
            } else {
                $this->calDavBackend->createCalendarObject($calendarId, $objectUri, $icsData);
                $this->logger->info("RoomVox: Published VAVAILABILITY for {$roomUserId}");
            }
        } catch (\Throwable $e) {
            $this->logger->error("RoomVox: Failed to publish availability for {$roomUserId}: " . $e->getMessage());
        }
    }

    /**
     * Build a VCALENDAR with VAVAILABILITY component from room availability rules.
     */
    private function buildVAvailability(array $room): string {
        $dayMap = [0 => 'SU', 1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA'];
        $rules = $room['availabilityRules']['rules'] ?? [];
        $roomEmail = $room['email'] ?? '';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//RoomVox//Room Availability//EN',
            'BEGIN:VAVAILABILITY',
        ];

        if ($roomEmail !== '') {
            $lines[] = 'ORGANIZER:mailto:' . $roomEmail;
        }

        // DTSTART far in the past so it covers all recurring instances
        $lines[] = 'DTSTART:20240101T000000Z';

        foreach ($rules as $rule) {
            $days = $rule['days'] ?? [];
            $startTime = $rule['startTime'] ?? '08:00';
            $endTime = $rule['endTime'] ?? '18:00';

            if (empty($days)) {
                continue;
            }

            // Map day numbers to BYDAY codes
            $byDay = [];
            foreach ($days as $day) {
                if (isset($dayMap[$day])) {
                    $byDay[] = $dayMap[$day];
                }
            }

            $startFormatted = str_replace(':', '', $startTime) . '00';
            $endFormatted = str_replace(':', '', $endTime) . '00';

            $lines[] = 'BEGIN:AVAILABLE';
            $lines[] = 'DTSTART;TZID=Europe/Amsterdam:20240101T' . $startFormatted;
            $lines[] = 'DTEND;TZID=Europe/Amsterdam:20240101T' . $endFormatted;
            $lines[] = 'RRULE:FREQ=WEEKLY;BYDAY=' . implode(',', $byDay);
            $lines[] = 'SUMMARY:Available';
            $lines[] = 'END:AVAILABLE';
        }

        $lines[] = 'END:VAVAILABILITY';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Create a new booking in the room's calendar
     *
     * @param string $roomUserId The room's service account user ID
     * @param array $data Booking data with keys: summary, start, end, description, organizer, roomEmail, autoAccept
     * @return string The UID of the created event
     */
    public function createBooking(string $roomUserId, array $data): string {
        $calendarId = $this->getRoomCalendarId($roomUserId);
        if ($calendarId === null) {
            throw new \Exception("No calendar found for room: {$roomUserId}");
        }

        $uid = $this->generateUid();
        $objectUri = $uid . '.ics';

        /** @var \DateTime $start */
        $start = $data['start'];
        /** @var \DateTime $end */
        $end = $data['end'];

        $summary = $data['summary'] ?? 'Booking';
        $description = $data['description'] ?? '';
        $organizer = $data['organizer'] ?? '';
        $roomEmail = $data['roomEmail'] ?? '';
        $autoAccept = $data['autoAccept'] ?? false;

        // Build iCalendar data
        $icsLines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//RoomVox//Room Booking//EN',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $start->format('Ymd\THis\Z'),
            'DTEND:' . $end->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->escapeIcsText($summary),
        ];

        if ($description !== '') {
            $icsLines[] = 'DESCRIPTION:' . $this->escapeIcsText($description);
        }

        if ($organizer !== '') {
            $icsLines[] = 'ORGANIZER;CN=' . $this->escapeIcsText($organizer) . ':mailto:' . $organizer . '@localhost';
        }

        // Add room as attendee
        if ($roomEmail !== '') {
            $partstat = $autoAccept ? 'ACCEPTED' : 'TENTATIVE';
            $icsLines[] = 'ATTENDEE;CUTYPE=ROOM;PARTSTAT=' . $partstat . ';CN=Room:mailto:' . $roomEmail;
        }

        // Set status based on auto-accept
        $icsLines[] = 'STATUS:' . ($autoAccept ? 'CONFIRMED' : 'TENTATIVE');

        $icsLines[] = 'END:VEVENT';
        $icsLines[] = 'END:VCALENDAR';

        $icsData = implode("\r\n", $icsLines) . "\r\n";

        try {
            $this->calDavBackend->createCalendarObject($calendarId, $objectUri, $icsData);
            $this->logger->info("RoomVox: Created booking {$uid} in calendar for {$roomUserId}");
            return $uid;
        } catch (\Throwable $e) {
            $this->logger->error("RoomVox: Failed to create booking: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update the times of an existing booking
     *
     * @param string $roomUserId The room's service account user ID
     * @param string $uid The UID of the event to update
     * @param \DateTime $start New start time
     * @param \DateTime $end New end time
     * @return bool True if successful
     */
    public function updateBookingTimes(string $roomUserId, string $uid, \DateTime $start, \DateTime $end): bool {
        $calendarId = $this->getRoomCalendarId($roomUserId);
        if ($calendarId === null) {
            return false;
        }

        $objectUri = $uid . '.ics';

        try {
            $existing = $this->calDavBackend->getCalendarObject($calendarId, $objectUri);
            if ($existing === null) {
                $this->logger->warning("RoomVox: Booking not found: {$uid}");
                return false;
            }

            $calendarData = $existing['calendardata'] ?? '';
            $vObject = Reader::read($calendarData);
            $vEvent = $vObject->VEVENT ?? null;

            if ($vEvent === null) {
                return false;
            }

            // Update times
            $vEvent->DTSTART = $start;
            $vEvent->DTEND = $end;
            $vEvent->DTSTAMP = new \DateTime('now', new \DateTimeZone('UTC'));

            $this->calDavBackend->updateCalendarObject($calendarId, $objectUri, $vObject->serialize());
            $this->logger->info("RoomVox: Updated booking times for {$uid}");
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("RoomVox: Failed to update booking times: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a single booking by its UID
     *
     * @param string $roomUserId The room's service account user ID
     * @param string $uid The UID of the event
     * @return array|null The booking data or null if not found
     */
    public function getBookingByUid(string $roomUserId, string $uid): ?array {
        $calendarId = $this->getRoomCalendarId($roomUserId);
        if ($calendarId === null) {
            return null;
        }

        $objectUri = $uid . '.ics';

        try {
            $object = $this->calDavBackend->getCalendarObject($calendarId, $objectUri);
            if ($object === null) {
                return null;
            }

            $calendarData = $object['calendardata'] ?? '';
            $vObject = Reader::read($calendarData);
            $vEvent = $vObject->VEVENT ?? null;

            if ($vEvent === null) {
                return null;
            }

            // Extract organizer
            $organizer = '';
            if ($vEvent->ORGANIZER) {
                $organizer = RoomService::stripMailto((string)$vEvent->ORGANIZER);
            }

            return [
                'uid' => (string)($vEvent->UID ?? ''),
                'uri' => $objectUri,
                'summary' => (string)($vEvent->SUMMARY ?? ''),
                'description' => (string)($vEvent->DESCRIPTION ?? ''),
                'dtstart' => $vEvent->DTSTART ? $vEvent->DTSTART->getDateTime()->format('c') : null,
                'dtend' => $vEvent->DTEND ? $vEvent->DTEND->getDateTime()->format('c') : null,
                'organizer' => $organizer,
                'status' => (string)($vEvent->STATUS ?? ''),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning("RoomVox: Failed to get booking {$uid}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate a unique ID for calendar events
     */
    private function generateUid(): string {
        return sprintf(
            '%s-%s@roomvox',
            bin2hex(random_bytes(8)),
            time()
        );
    }

    /**
     * Escape text for iCalendar format
     */
    private function escapeIcsText(string $text): string {
        return str_replace(
            ['\\', "\n", ';', ','],
            ['\\\\', '\\n', '\\;', '\\,'],
            $text
        );
    }

}
