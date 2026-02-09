<?php

declare(strict_types=1);

namespace OCA\RoomBooking\Service;

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
                    $organizer = $this->stripMailto((string)$vEvent->ORGANIZER);
                    $organizerName = isset($vEvent->ORGANIZER['CN']) ? (string)$vEvent->ORGANIZER['CN'] : $organizer;
                }

                // Extract PARTSTAT of room attendee
                // First try CUTYPE=ROOM, then fall back to non-organizer attendee
                // (some clients like iOS send CUTYPE=INDIVIDUAL for rooms)
                $partstat = 'NEEDS-ACTION';
                $organizerEmail = $organizer ? strtolower($this->stripMailto($organizer)) : '';
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
                    $attendeeEmail = strtolower($this->stripMailto((string)$attendee));
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
                    $orgEmail = strtolower($this->stripMailto((string)$vEvent->ORGANIZER));
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
                        $attendeeEmail = strtolower($this->stripMailto((string)$attendee));
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
            $this->logger->error("RoomBooking: No calendar found for room {$roomUserId}, cannot deliver");
            return false;
        }

        try {
            $vObject = Reader::read($calendarData);
            $vEvent = $vObject->VEVENT ?? null;
            if ($vEvent === null) {
                $this->logger->error("RoomBooking: No VEVENT in calendar data for delivery");
                return false;
            }

            $uid = (string)($vEvent->UID ?? '');
            if ($uid === '') {
                $this->logger->error("RoomBooking: No UID in VEVENT for delivery");
                return false;
            }

            $objectUri = $uid . '.ics';

            // Check if this event already exists (update vs create)
            $existing = null;
            try {
                $existing = $this->calDavBackend->getCalendarObject($calendarId, $objectUri);
            } catch (\Throwable $e) {
                // Not found, will create
            }

            if ($existing !== null) {
                $this->calDavBackend->updateCalendarObject($calendarId, $objectUri, $calendarData);
                $this->logger->info("RoomBooking: Updated calendar object {$objectUri} in calendar {$calendarId}");
            } else {
                $this->calDavBackend->createCalendarObject($calendarId, $objectUri, $calendarData);
                $this->logger->info("RoomBooking: Created calendar object {$objectUri} in calendar {$calendarId}");
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error("RoomBooking: Failed to deliver to room calendar: " . $e->getMessage());
            return false;
        }
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
                $this->logger->info("RoomBooking: Deleted calendar object {$objectUri} from calendar {$calendarId}");
                return true;
            }
        } catch (\Throwable $e) {
            $this->logger->error("RoomBooking: Failed to delete from room calendar: " . $e->getMessage());
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
     * NC scheduling delivers events to principals/calendar-rooms/roombooking-<roomId>,
     * not to the user principal.
     */
    public function getRoomCalendarId(string $roomUserId): ?int {
        // Extract room ID from userId (rb_testroom → testroom)
        $roomId = str_starts_with($roomUserId, 'rb_') ? substr($roomUserId, 3) : $roomUserId;
        $principalUri = 'principals/calendar-rooms/roombooking-' . $roomId;

        $calendars = $this->calDavBackend->getCalendarsForUser($principalUri);

        foreach ($calendars as $calendar) {
            return (int)$calendar['id'];
        }

        // Fallback: try the user principal calendar
        $this->logger->debug("No calendar-rooms calendar found for {$roomUserId}, falling back to user principal");
        return $this->getCalendarId($roomUserId);
    }

    /**
     * Strip mailto: prefix from email
     */
    private function stripMailto(string $email): string {
        if (str_starts_with(strtolower($email), 'mailto:')) {
            return substr($email, 7);
        }
        return $email;
    }
}
