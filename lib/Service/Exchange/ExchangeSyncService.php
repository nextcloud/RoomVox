<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service\Exchange;

use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\RoomService;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

class ExchangeSyncService {
    /** Custom iCal properties for sync tracking */
    private const SYNC_SOURCE_PROP = 'X-ROOMVOX-SYNC-SOURCE';
    private const EXCHANGE_EVENT_ID_PROP = 'X-EXCHANGE-EVENT-ID';

    private const SOURCE_ROOMVOX = 'roomvox';
    private const SOURCE_EXCHANGE = 'exchange';

    /**
     * In-memory sync index built once per pullExchangeChanges() call.
     * Maps: exchangeId → uid, uid → syncSource
     * @var array{byExchangeId: array<string, string>, sourceByUid: array<string, string>, exchangeOriginatedUids: array<string, true>}|null
     */
    private ?array $syncIndex = null;

    public function __construct(
        private GraphApiClient $graphClient,
        private CalDAVService $calDAVService,
        private RoomService $roomService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Check if global Exchange integration is enabled and configured.
     */
    public function isGloballyEnabled(): bool {
        return $this->graphClient->isConfigured();
    }

    /**
     * Check if a room has Exchange sync enabled.
     */
    public function isExchangeRoom(array $room): bool {
        if (!$this->isGloballyEnabled()) {
            return false;
        }

        $config = $room['exchangeConfig'] ?? null;
        if ($config === null) {
            return false;
        }

        return !empty($config['resourceEmail']) && ($config['syncEnabled'] ?? false);
    }

    /**
     * Validate that a resource email corresponds to a valid Exchange room resource.
     * @return array{valid: bool, displayName?: string, error?: string}
     */
    public function validateResourceEmail(string $email): array {
        try {
            $result = $this->graphClient->get('/users/' . urlencode($email), [
                '$select' => 'displayName,mail,userType',
            ]);

            return [
                'valid' => true,
                'displayName' => $result['displayName'] ?? $email,
            ];
        } catch (ExchangeApiException $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ─── Push: RoomVox → Exchange ────────────────────────────────────────

    /**
     * Push a newly created or updated booking to Exchange.
     * Called synchronously after booking creation in RoomVox.
     *
     * @param array $room Room data (must have exchangeConfig)
     * @param string $uid RoomVox booking UID
     * @param array $bookingData Keys: summary, start (DateTime), end (DateTime), description, organizer
     * @return bool True if pushed successfully
     */
    public function pushBookingToExchange(array $room, string $uid, array $bookingData): bool {
        if (!$this->isExchangeRoom($room)) {
            $this->logger->info("ExchangeSync: Skipping push for room {$room['id']} — not an Exchange room. "
                . "globallyEnabled=" . ($this->isGloballyEnabled() ? 'true' : 'false')
                . ", exchangeConfig=" . json_encode($room['exchangeConfig'] ?? null));
            return false;
        }

        // Check if this booking originated from Exchange (echo prevention)
        if ($this->isExchangeOriginated($room, $uid)) {
            $this->logger->debug("ExchangeSync: Skipping push for Exchange-originated booking {$uid}");
            return false;
        }

        // If this booking already has an Exchange event, update instead of creating
        $existingExchangeId = $this->getExchangeEventId($room, $uid);
        if ($existingExchangeId !== null) {
            return $this->updateBookingOnExchange($room, $uid, $bookingData);
        }

        $resourceEmail = $room['exchangeConfig']['resourceEmail'];

        try {
            $utc = new \DateTimeZone('UTC');
            $start = \DateTime::createFromInterface($bookingData['start'])->setTimezone($utc);
            $end = \DateTime::createFromInterface($bookingData['end'])->setTimezone($utc);

            $event = [
                'subject' => $bookingData['summary'] ?? 'Booking',
                'start' => [
                    'dateTime' => $start->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'UTC',
                ],
                'end' => [
                    'dateTime' => $end->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'UTC',
                ],
                'body' => [
                    'contentType' => 'text',
                    'content' => $bookingData['description'] ?? 'Booked via RoomVox',
                ],
                'singleValueExtendedProperties' => [
                    [
                        'id' => GraphApiClient::SYNC_SOURCE_PROP,
                        'value' => self::SOURCE_ROOMVOX,
                    ],
                    [
                        'id' => GraphApiClient::ROOMVOX_UID_PROP,
                        'value' => $uid,
                    ],
                ],
            ];

            $result = $this->graphClient->post(
                '/users/' . urlencode($resourceEmail) . '/calendar/events',
                $event
            );

            $exchangeEventId = $result['id'] ?? '';
            if ($exchangeEventId !== '') {
                $this->storeExchangeEventId($room, $uid, $exchangeEventId);
            }

            $this->logger->info("ExchangeSync: Pushed booking {$uid} to Exchange for room {$room['id']}");
            return true;
        } catch (ExchangeApiException $e) {
            $this->logger->error("ExchangeSync: Failed to push booking {$uid}: " . $e->getMessage());
            $this->roomService->updateExchangeSyncState($room['id'], null, $e->getMessage());
            return false;
        }
    }

    /**
     * Update an existing booking on Exchange.
     */
    public function updateBookingOnExchange(array $room, string $uid, array $bookingData): bool {
        if (!$this->isExchangeRoom($room)) {
            return false;
        }

        if ($this->isExchangeOriginated($room, $uid)) {
            return false;
        }

        $exchangeEventId = $this->getExchangeEventId($room, $uid);
        if ($exchangeEventId === null) {
            // No mapping found — push as new instead
            return $this->pushBookingToExchange($room, $uid, $bookingData);
        }

        $resourceEmail = $room['exchangeConfig']['resourceEmail'];

        try {
            $utc = new \DateTimeZone('UTC');
            $start = \DateTime::createFromInterface($bookingData['start'])->setTimezone($utc);
            $end = \DateTime::createFromInterface($bookingData['end'])->setTimezone($utc);

            $event = [
                'subject' => $bookingData['summary'] ?? 'Booking',
                'start' => [
                    'dateTime' => $start->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'UTC',
                ],
                'end' => [
                    'dateTime' => $end->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'UTC',
                ],
            ];

            if (isset($bookingData['description'])) {
                $event['body'] = [
                    'contentType' => 'text',
                    'content' => $bookingData['description'],
                ];
            }

            $this->graphClient->patch(
                '/users/' . urlencode($resourceEmail) . '/calendar/events/' . urlencode($exchangeEventId),
                $event
            );

            $this->logger->info("ExchangeSync: Updated booking {$uid} on Exchange for room {$room['id']}");
            return true;
        } catch (ExchangeApiException $e) {
            $this->logger->error("ExchangeSync: Failed to update booking {$uid} on Exchange: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a booking from Exchange.
     */
    public function deleteBookingFromExchange(array $room, string $uid): bool {
        if (!$this->isExchangeRoom($room)) {
            return false;
        }

        if ($this->isExchangeOriginated($room, $uid)) {
            return false;
        }

        $exchangeEventId = $this->getExchangeEventId($room, $uid);
        if ($exchangeEventId === null) {
            $this->logger->debug("ExchangeSync: No Exchange event ID found for booking {$uid}");
            return false;
        }

        $resourceEmail = $room['exchangeConfig']['resourceEmail'];

        try {
            $this->graphClient->delete(
                '/users/' . urlencode($resourceEmail) . '/calendar/events/' . urlencode($exchangeEventId)
            );

            $this->logger->info("ExchangeSync: Deleted booking {$uid} from Exchange for room {$room['id']}");
            return true;
        } catch (ExchangeApiException $e) {
            // 404 = already deleted on Exchange, not an error
            if ($e->getHttpStatus() === 404) {
                $this->logger->debug("ExchangeSync: Booking {$uid} already deleted on Exchange");
                return true;
            }
            $this->logger->error("ExchangeSync: Failed to delete booking {$uid} from Exchange: " . $e->getMessage());
            return false;
        }
    }

    // ─── Pull: Exchange → RoomVox ────────────────────────────────────────

    /**
     * Build an in-memory sync index for a room's calendar.
     * Reads all calendar objects once and maps:
     * - exchangeId → uid (for finding local bookings by Exchange event ID)
     * - uid → syncSource (for echo prevention)
     *
     * @return array{byExchangeId: array<string, string>, sourceByUid: array<string, string>}
     */
    private function buildSyncIndex(array $room): array {
        $index = ['byExchangeId' => [], 'sourceByUid' => []];

        $calendarId = $this->calDAVService->getRoomCalendarId($room['userId']);
        if ($calendarId === null) {
            return $index;
        }

        $backend = $this->getCalDavBackend();
        $objects = $backend->getCalendarObjects($calendarId);

        foreach ($objects as $object) {
            try {
                $fullObject = $backend->getCalendarObject($calendarId, $object['uri']);
                if ($fullObject === null) {
                    continue;
                }

                $vObject = Reader::read($fullObject['calendardata'] ?? '');
                $vEvent = $vObject->VEVENT ?? null;
                if ($vEvent === null) {
                    continue;
                }

                $uid = (string)($vEvent->UID ?? '');
                $exchangeId = (string)($vEvent->{self::EXCHANGE_EVENT_ID_PROP} ?? '');
                $syncSource = (string)($vEvent->{self::SYNC_SOURCE_PROP} ?? '');

                if ($uid !== '') {
                    if ($syncSource !== '') {
                        $index['sourceByUid'][$uid] = $syncSource;
                    }
                    if ($exchangeId !== '') {
                        $index['byExchangeId'][$exchangeId] = $uid;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $index;
    }

    /**
     * Pull changes from Exchange for a room using delta query.
     */
    public function pullExchangeChanges(array $room): SyncResult {
        $result = new SyncResult();

        if (!$this->isExchangeRoom($room)) {
            return $result;
        }

        $resourceEmail = $room['exchangeConfig']['resourceEmail'];
        $deltaToken = $room['exchangeConfig']['lastSyncToken'] ?? null;

        try {
            // Build sync index once — O(bookings) instead of O(events × bookings)
            $this->syncIndex = $this->buildSyncIndex($room);

            $events = $this->fetchDeltaEvents($resourceEmail, $deltaToken, $result);

            foreach ($events as $event) {
                $this->processExchangeEvent($room, $event, $result);
            }

            // Update sync state
            $this->roomService->updateExchangeSyncState($room['id'], $result->newDeltaToken, null);

            if ($result->hasChanges()) {
                $this->logger->info(
                    "ExchangeSync: Room {$room['id']}: {$result->created} created, {$result->updated} updated, {$result->deleted} deleted"
                );
            }
        } catch (ExchangeApiException $e) {
            $this->logger->error("ExchangeSync: Pull failed for room {$room['id']}: " . $e->getMessage());
            $this->roomService->updateExchangeSyncState($room['id'], null, $e->getMessage());
            $result->errors[] = $e->getMessage();

            // If delta token is invalid, clear it so next sync does a full pull
            if ($e->getHttpStatus() === 410) {
                $this->roomService->updateExchangeSyncState($room['id'], '', $e->getMessage());
            }
        } finally {
            $this->syncIndex = null;
        }

        return $result;
    }

    /**
     * Full sync: clear delta token, pull all events, and remove orphans.
     */
    public function fullSync(array $room): SyncResult {
        // Clear the delta token to force a full pull
        $this->roomService->updateExchangeSyncState($room['id'], '', null);
        $room['exchangeConfig']['lastSyncToken'] = null;

        $result = $this->pullExchangeChanges($room);

        // Reconciliation: remove RoomVox bookings whose Exchange event no longer exists.
        // pullExchangeChanges returns all current Exchange events during a full pull,
        // but Exchange doesn't always send @removed markers for cancelled/deleted events.
        $this->reconcileOrphans($room, $result);

        return $result;
    }

    /**
     * Remove RoomVox bookings that have an X-EXCHANGE-EVENT-ID but whose
     * Exchange event was not seen during the latest full pull.
     * Uses the sync index built by pullExchangeChanges() — no extra DB scan.
     */
    private function reconcileOrphans(array $room, SyncResult $result): void {
        if ($this->syncIndex === null) {
            return;
        }

        // Collect all Exchange event IDs that were seen during the pull
        $seenExchangeIds = array_flip($result->seenExchangeIds ?? []);

        try {
            // Use the sync index instead of scanning all objects again
            foreach ($this->syncIndex['byExchangeId'] as $exchangeId => $uid) {
                // Only consider bookings that came from Exchange
                $source = $this->syncIndex['sourceByUid'][$uid] ?? '';
                if ($source !== self::SOURCE_EXCHANGE) {
                    continue;
                }

                // If this Exchange event was not in the full pull, it was deleted/cancelled
                if (!isset($seenExchangeIds[$exchangeId])) {
                    $this->calDAVService->deleteFromRoomCalendar($room['userId'], $uid);
                    $result->deleted++;
                    $this->logger->info("ExchangeSync: Reconciliation: deleted orphan booking {$uid} (Exchange event {$exchangeId} no longer exists)");
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error("ExchangeSync: Reconciliation failed for room {$room['id']}: " . $e->getMessage());
        }
    }

    // ─── Exchange Conflict Check ─────────────────────────────────────────

    /**
     * Check if a time range conflicts with events on Exchange.
     */
    public function hasExchangeConflict(
        array $room,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        ?string $excludeUid = null,
    ): bool {
        if (!$this->isExchangeRoom($room)) {
            return false;
        }

        $resourceEmail = $room['exchangeConfig']['resourceEmail'];

        try {
            $result = $this->graphClient->get(
                '/users/' . urlencode($resourceEmail) . '/calendarView',
                [
                    'startDateTime' => $start->format('Y-m-d\TH:i:s\Z'),
                    'endDateTime' => $end->format('Y-m-d\TH:i:s\Z'),
                    '$select' => 'id,subject,start,end,isCancelled,showAs,singleValueExtendedProperties',
                    '$expand' => 'singleValueExtendedProperties($filter=id eq \'' . GraphApiClient::ROOMVOX_UID_PROP . '\')',
                    '$top' => '50',
                ]
            );

            $events = $result['value'] ?? [];
            foreach ($events as $event) {
                if ($event['isCancelled'] ?? false) {
                    continue;
                }

                // Skip events that don't block the calendar
                $showAs = $event['showAs'] ?? 'busy';
                if ($showAs === 'free') {
                    continue;
                }

                // Skip the event being updated (by RoomVox UID)
                if ($excludeUid !== null) {
                    $props = $event['singleValueExtendedProperties'] ?? [];
                    foreach ($props as $prop) {
                        if ($prop['id'] === GraphApiClient::ROOMVOX_UID_PROP && $prop['value'] === $excludeUid) {
                            continue 2;
                        }
                    }
                }

                return true; // Found a conflicting event
            }

            return false;
        } catch (ExchangeApiException $e) {
            $this->logger->warning("ExchangeSync: Conflict check failed for room {$room['id']}, falling back to local-only: " . $e->getMessage());
            return false; // Fail open: don't block booking if Exchange is unreachable
        }
    }

    // ─── Private helpers ─────────────────────────────────────────────────

    /**
     * Fetch events from Exchange using delta query.
     * @return array List of event objects (including @removed markers)
     */
    private function fetchDeltaEvents(string $resourceEmail, ?string $deltaToken, SyncResult $result): array {
        $events = [];

        if (!empty($deltaToken)) {
            // Incremental sync using stored deltaLink URL
            $url = $deltaToken;
        } else {
            // Initial sync: get events from 30 days ago to 365 days ahead.
            // Note: $select is NOT supported on calendarView/delta (per MS docs).
            // All event properties are returned by default.
            $from = (new \DateTimeImmutable('-30 days'))->format('Y-m-d\TH:i:s\Z');
            $to = (new \DateTimeImmutable('+365 days'))->format('Y-m-d\TH:i:s\Z');
            $url = 'https://graph.microsoft.com/v1.0/users/' . urlencode($resourceEmail)
                . '/calendarView/delta?startDateTime=' . $from . '&endDateTime=' . $to;
        }

        $this->logger->info("ExchangeSync: fetchDeltaEvents for {$resourceEmail}, hasDeltaToken=" . (!empty($deltaToken) ? 'yes' : 'no'));

        // Follow pagination
        $page = 0;
        while ($url !== null) {
            $response = $this->graphClient->getUrl($url);
            $page++;

            $pageEvents = $response['value'] ?? [];
            $this->logger->info("ExchangeSync: Delta page {$page}: " . count($pageEvents) . " events, "
                . "hasNextLink=" . (isset($response['@odata.nextLink']) ? 'yes' : 'no')
                . ", hasDeltaLink=" . (isset($response['@odata.deltaLink']) ? 'yes' : 'no'));

            // Log each event briefly
            foreach ($pageEvents as $ev) {
                $this->logger->info("ExchangeSync: Delta event: id=" . ($ev['id'] ?? '?')
                    . ", subject=" . ($ev['subject'] ?? '?')
                    . ", removed=" . (isset($ev['@removed']) ? json_encode($ev['@removed']) : 'no')
                    . ", isCancelled=" . (($ev['isCancelled'] ?? false) ? 'yes' : 'no'));
            }

            $events = array_merge($events, $pageEvents);

            // Check for next page or delta link
            $url = $response['@odata.nextLink'] ?? null;

            if ($url === null && isset($response['@odata.deltaLink'])) {
                $result->newDeltaToken = $response['@odata.deltaLink'];
            }
        }

        $this->logger->info("ExchangeSync: fetchDeltaEvents total: " . count($events) . " events");
        return $events;
    }

    /**
     * Process a single Exchange event: create, update, or delete in RoomVox.
     */
    private function processExchangeEvent(array $room, array $event, SyncResult $result): void {
        $exchangeEventId = $event['id'] ?? '';
        if ($exchangeEventId === '') {
            return;
        }

        $this->logger->info("ExchangeSync: Processing event {$exchangeEventId}: "
            . "subject=" . ($event['subject'] ?? '?')
            . ", removed=" . (isset($event['@removed']) ? 'yes' : 'no')
            . ", isCancelled=" . (($event['isCancelled'] ?? false) ? 'yes' : 'no'));

        // Check if this event was removed
        if (isset($event['@removed'])) {
            $this->handleExchangeEventDeleted($room, $exchangeEventId, $result);
            return;
        }

        // Track seen Exchange event IDs for reconciliation
        $result->seenExchangeIds[] = $exchangeEventId;

        // Echo prevention: check if this event originated from RoomVox.
        // singleValueExtendedProperties may or may not be in the delta response
        // (since $expand is not supported on calendarView/delta).
        // Strategy: check if we already have a local booking with this Exchange event ID
        // that was created by RoomVox (has SYNC_SOURCE = roomvox).
        $props = $event['singleValueExtendedProperties'] ?? [];
        foreach ($props as $prop) {
            if ($prop['id'] === GraphApiClient::SYNC_SOURCE_PROP && $prop['value'] === self::SOURCE_ROOMVOX) {
                $result->skipped++;
                return;
            }
        }

        // Check locally via sync index: do we already have a booking with this Exchange event ID?
        $existingUid = $this->syncIndex['byExchangeId'][$exchangeEventId] ?? null;

        // Echo prevention: if the local booking was pushed FROM RoomVox, skip it
        if ($existingUid !== null && ($this->syncIndex['sourceByUid'][$existingUid] ?? '') === self::SOURCE_ROOMVOX) {
            $result->skipped++;
            return;
        }

        // Extract event data
        $subject = $event['subject'] ?? 'Exchange Booking';
        $startStr = $event['start']['dateTime'] ?? null;
        $endStr = $event['end']['dateTime'] ?? null;
        $description = $event['body']['content'] ?? '';
        $organizer = $event['organizer']['emailAddress']['address'] ?? '';
        $isCancelled = $event['isCancelled'] ?? false;

        if ($startStr === null || $endStr === null) {
            $result->skipped++;
            return;
        }

        if ($isCancelled) {
            $this->handleExchangeEventDeleted($room, $exchangeEventId, $result);
            return;
        }

        $start = new \DateTimeImmutable($startStr, new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($endStr, new \DateTimeZone('UTC'));

        if ($existingUid !== null) {
            // Update existing booking
            $this->calDAVService->updateBookingTimes(
                $room['userId'],
                $existingUid,
                \DateTime::createFromImmutable($start),
                \DateTime::createFromImmutable($end)
            );
            $result->updated++;
        } else {
            // Create new booking in RoomVox
            $uid = $this->calDAVService->createBooking($room['userId'], [
                'summary' => $subject,
                'start' => \DateTime::createFromImmutable($start),
                'end' => \DateTime::createFromImmutable($end),
                'description' => $description,
                'organizer' => $organizer,
                'roomEmail' => $room['email'] ?? '',
                'autoAccept' => true, // Exchange bookings are already accepted
            ]);

            // Add sync metadata to the iCal object
            $this->addSyncMetadata($room, $uid, $exchangeEventId);

            // Update sync index for this batch (later events may reference this)
            if ($this->syncIndex !== null) {
                $this->syncIndex['byExchangeId'][$exchangeEventId] = $uid;
                $this->syncIndex['sourceByUid'][$uid] = self::SOURCE_EXCHANGE;
            }

            $result->created++;
        }
    }

    /**
     * Handle deletion of an Exchange event from RoomVox.
     */
    private function handleExchangeEventDeleted(array $room, string $exchangeEventId, SyncResult $result): void {
        $uid = $this->syncIndex['byExchangeId'][$exchangeEventId] ?? null;
        if ($uid !== null) {
            $this->calDAVService->deleteFromRoomCalendar($room['userId'], $uid);
            $result->deleted++;
            $this->logger->info("ExchangeSync: Deleted RoomVox booking {$uid} (Exchange event {$exchangeEventId} removed)");
        } else {
            $this->logger->warning("ExchangeSync: Exchange event {$exchangeEventId} was removed but no matching RoomVox booking found for room {$room['id']}");
        }
    }

    /**
     * Check if a booking originated from Exchange by reading the iCal data.
     */
    private function isExchangeOriginated(array $room, string $uid): bool {
        return $this->getBookingSyncSource($room, $uid) === self::SOURCE_EXCHANGE;
    }

    /**
     * Get the sync source property from a booking's iCal data.
     * @return string|null 'roomvox', 'exchange', or null if not set
     */
    private function getBookingSyncSource(array $room, string $uid): ?string {
        $calendarId = $this->calDAVService->getRoomCalendarId($room['userId']);
        if ($calendarId === null) {
            return null;
        }

        try {
            $objectUri = $uid . '.ics';
            $object = $this->getCalendarObject($calendarId, $objectUri);
            if ($object === null) {
                return null;
            }

            $vObject = Reader::read($object['calendardata'] ?? '');
            $vEvent = $vObject->VEVENT ?? null;
            if ($vEvent === null) {
                return null;
            }

            $source = (string)($vEvent->{self::SYNC_SOURCE_PROP} ?? '');
            return $source !== '' ? $source : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get the Exchange event ID stored in a RoomVox booking's iCal data.
     */
    private function getExchangeEventId(array $room, string $uid): ?string {
        $calendarId = $this->calDAVService->getRoomCalendarId($room['userId']);
        if ($calendarId === null) {
            return null;
        }

        try {
            $objectUri = $uid . '.ics';
            $object = $this->getCalendarObject($calendarId, $objectUri);
            if ($object === null) {
                return null;
            }

            $vObject = Reader::read($object['calendardata'] ?? '');
            $vEvent = $vObject->VEVENT ?? null;
            if ($vEvent === null) {
                return null;
            }

            $exchangeId = (string)($vEvent->{self::EXCHANGE_EVENT_ID_PROP} ?? '');
            return $exchangeId !== '' ? $exchangeId : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Store the Exchange event ID in a RoomVox booking's iCal data.
     */
    private function storeExchangeEventId(array $room, string $uid, string $exchangeEventId): void {
        $this->logger->info("ExchangeSync: Storing Exchange event ID {$exchangeEventId} for booking {$uid}");
        $this->updateCalendarObjectProperty($room, $uid, [
            self::EXCHANGE_EVENT_ID_PROP => $exchangeEventId,
            self::SYNC_SOURCE_PROP => self::SOURCE_ROOMVOX,
        ]);
    }

    /**
     * Add sync metadata (source + exchange event ID) to a newly created iCal object.
     */
    private function addSyncMetadata(array $room, string $uid, string $exchangeEventId): void {
        $this->updateCalendarObjectProperty($room, $uid, [
            self::SYNC_SOURCE_PROP => self::SOURCE_EXCHANGE,
            self::EXCHANGE_EVENT_ID_PROP => $exchangeEventId,
        ]);
    }

    /**
     * Update custom properties on a calendar object.
     * @param array<string, string> $properties Property name => value pairs
     */
    private function updateCalendarObjectProperty(array $room, string $uid, array $properties): void {
        $calendarId = $this->calDAVService->getRoomCalendarId($room['userId']);
        if ($calendarId === null) {
            $this->logger->warning("ExchangeSync: No calendar found for room {$room['id']} — cannot store properties for {$uid}");
            return;
        }

        try {
            $objectUri = $uid . '.ics';
            $object = $this->getCalendarObject($calendarId, $objectUri);
            if ($object === null) {
                $this->logger->warning("ExchangeSync: Calendar object {$objectUri} not found in calendar {$calendarId} — cannot store properties");
                return;
            }

            $vObject = Reader::read($object['calendardata'] ?? '');
            $vEvent = $vObject->VEVENT ?? null;
            if ($vEvent === null) {
                $this->logger->warning("ExchangeSync: No VEVENT in {$objectUri} — cannot store properties");
                return;
            }

            foreach ($properties as $name => $value) {
                $vEvent->{$name} = $value;
            }

            $this->updateCalendarObject($calendarId, $objectUri, $vObject->serialize());
            $this->logger->info("ExchangeSync: Stored properties in {$objectUri}: " . json_encode(array_keys($properties)));
        } catch (\Throwable $e) {
            $this->logger->error("ExchangeSync: Failed to update iCal properties for {$uid}: " . $e->getMessage());
        }
    }

    // ─── CalDavBackend wrappers (accessed via CalDAVService reflection) ──

    /**
     * Get calendar objects from CalDavBackend.
     * We need to access the backend directly for search operations.
     */
    private function getCalendarObjects(int $calendarId): array {
        $backend = $this->getCalDavBackend();
        return $backend->getCalendarObjects($calendarId);
    }

    private function getCalendarObject(int $calendarId, string $objectUri): ?array {
        $backend = $this->getCalDavBackend();
        try {
            return $backend->getCalendarObject($calendarId, $objectUri);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function updateCalendarObject(int $calendarId, string $objectUri, string $data): void {
        $backend = $this->getCalDavBackend();
        $backend->updateCalendarObject($calendarId, $objectUri, $data);
    }

    /**
     * Get the CalDavBackend instance from CalDAVService.
     */
    private function getCalDavBackend(): \OCA\DAV\CalDAV\CalDavBackend {
        // Use reflection to access the private calDavBackend property
        $reflection = new \ReflectionClass($this->calDAVService);
        $prop = $reflection->getProperty('calDavBackend');
        return $prop->getValue($this->calDAVService);
    }
}
