<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service\Exchange;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\Exchange\GraphApiClient;
use OCA\RoomVox\Service\RoomService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * Tests for the Exchange conflict check exclusion logic in hasExchangeConflict().
 *
 * Covers the fix where events without ROOMVOX_UID_PROP (e.g. auto-accepted
 * by Exchange) are correctly excluded by matching on Exchange event ID.
 */
class ExchangeConflictExcludeTest extends TestCase {
    private ExchangeSyncService $service;
    private GraphApiClient $graphClient;
    private CalDavBackend $calDavBackend;

    private array $exchangeRoom = [
        'id' => 'testex',
        'userId' => 'rb_testex',
        'exchangeConfig' => [
            'resourceEmail' => 'room@company.com',
            'syncEnabled' => true,
        ],
    ];

    protected function setUp(): void {
        $this->graphClient = $this->createMock(GraphApiClient::class);
        $this->graphClient->method('isConfigured')->willReturn(true);

        $this->calDavBackend = $this->createMock(CalDavBackend::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Use a real CalDAVService (not a mock) so getCalDavBackend() reflection works
        $calDAVService = new CalDAVService($this->calDavBackend, $logger);
        $roomService = $this->createMock(RoomService::class);

        $this->service = new ExchangeSyncService(
            $this->graphClient,
            $calDAVService,
            $roomService,
            $logger,
        );

        Reader::setTestParser(null);
    }

    protected function tearDown(): void {
        Reader::setTestParser(null);
    }

    /**
     * Helper: build a Graph API calendarView response.
     */
    private function buildResponse(array $events): array {
        return ['value' => $events];
    }

    /**
     * Helper: build a single Exchange event.
     */
    private function buildEvent(array $overrides = []): array {
        return array_merge([
            'id' => 'exchange-event-AAMk123',
            'subject' => 'Meeting',
            'start' => ['dateTime' => '2026-03-26T09:00:00', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-03-26T10:00:00', 'timeZone' => 'UTC'],
            'isCancelled' => false,
            'showAs' => 'busy',
            'singleValueExtendedProperties' => [],
        ], $overrides);
    }

    /**
     * Helper: set up CalDavBackend to return a room calendar with an event
     * that has an X-EXCHANGE-EVENT-ID property.
     */
    private function setupLocalEventWithExchangeId(string $uid, string $exchangeEventId): void {
        // getRoomCalendarId needs getCalendarsForUser to return a calendar
        $this->calDavBackend->method('getCalendarsForUser')
            ->willReturn([['id' => 30, 'uri' => 'calendar']]);

        // getCalendarObject returns iCal data with the Exchange ID
        $this->calDavBackend->method('getCalendarObject')
            ->willReturnCallback(function (int $calId, string $objectUri) use ($uid, $exchangeEventId) {
                if ($objectUri === $uid . '.ics') {
                    return ['calendardata' => 'ics-with-exchange-id-' . $exchangeEventId];
                }
                return null;
            });

        // Reader::read returns a VObject with X-EXCHANGE-EVENT-ID
        Reader::setTestParser(function (string $data) use ($uid, $exchangeEventId) {
            if (!str_contains($data, $exchangeEventId)) {
                return new VCalendar();
            }

            $vEvent = new VEvent();
            $vEvent->UID = new Property($uid);
            $vEvent->{'X-EXCHANGE-EVENT-ID'} = new Property($exchangeEventId);

            $vCalendar = new VCalendar();
            $vCalendar->VEVENT = $vEvent;
            return $vCalendar;
        });
    }

    /**
     * Helper: set up CalDavBackend where the local event does NOT have
     * an X-EXCHANGE-EVENT-ID (e.g. never pushed to Exchange).
     */
    private function setupLocalEventWithoutExchangeId(string $uid): void {
        $this->calDavBackend->method('getCalendarsForUser')
            ->willReturn([['id' => 30, 'uri' => 'calendar']]);

        $this->calDavBackend->method('getCalendarObject')
            ->willReturnCallback(function (int $calId, string $objectUri) use ($uid) {
                if ($objectUri === $uid . '.ics') {
                    return ['calendardata' => 'ics-without-exchange-id'];
                }
                return null;
            });

        Reader::setTestParser(function (string $data) use ($uid) {
            $vEvent = new VEvent();
            $vEvent->UID = new Property($uid);

            $vCalendar = new VCalendar();
            $vCalendar->VEVENT = $vEvent;
            return $vCalendar;
        });
    }

    // ── Exclude by ROOMVOX_UID_PROP (existing behavior) ─────────────

    public function testExcludeByRoomvoxUidProp(): void {
        $this->graphClient->method('get')->willReturn(
            $this->buildResponse([
                $this->buildEvent([
                    'singleValueExtendedProperties' => [
                        ['id' => GraphApiClient::ROOMVOX_UID_PROP, 'value' => 'booking-123'],
                    ],
                ]),
            ])
        );

        $this->calDavBackend->method('getCalendarsForUser')->willReturn([]);

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-03-26 09:00'),
            new \DateTime('2026-03-26 10:00'),
            'booking-123',
        );

        $this->assertFalse($result, 'Should skip event matching excludeUid via ROOMVOX_UID_PROP');
    }

    public function testExcludeByRoomvoxUidPropWrongUid(): void {
        $this->graphClient->method('get')->willReturn(
            $this->buildResponse([
                $this->buildEvent([
                    'singleValueExtendedProperties' => [
                        ['id' => GraphApiClient::ROOMVOX_UID_PROP, 'value' => 'other-booking'],
                    ],
                ]),
            ])
        );

        $this->calDavBackend->method('getCalendarsForUser')->willReturn([]);

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-03-26 09:00'),
            new \DateTime('2026-03-26 10:00'),
            'booking-123',
        );

        $this->assertTrue($result, 'Should NOT skip event with different ROOMVOX_UID_PROP');
    }

    // ── Exclude by Exchange event ID (the fix) ──────────────────────

    public function testExcludeByExchangeEventId(): void {
        $exchangeEventId = 'exchange-event-AAMk123';

        // Exchange returns event WITHOUT ROOMVOX_UID_PROP (auto-accepted by Exchange)
        $this->graphClient->method('get')->willReturn(
            $this->buildResponse([
                $this->buildEvent([
                    'id' => $exchangeEventId,
                    'singleValueExtendedProperties' => [], // No ROOMVOX_UID_PROP!
                ]),
            ])
        );

        // Local CalDAV has the event with X-EXCHANGE-EVENT-ID stored
        $this->setupLocalEventWithExchangeId('booking-123', $exchangeEventId);

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-03-26 09:00'),
            new \DateTime('2026-03-26 10:00'),
            'booking-123',
        );

        $this->assertFalse($result, 'Should skip event matching by Exchange event ID even without ROOMVOX_UID_PROP');
    }

    public function testExcludeByExchangeEventIdWrongId(): void {
        // Exchange returns event with a DIFFERENT Exchange ID
        $this->graphClient->method('get')->willReturn(
            $this->buildResponse([
                $this->buildEvent([
                    'id' => 'exchange-event-DIFFERENT',
                    'singleValueExtendedProperties' => [],
                ]),
            ])
        );

        // Local event has a different Exchange ID
        $this->setupLocalEventWithExchangeId('booking-123', 'exchange-event-AAMk123');

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-03-26 09:00'),
            new \DateTime('2026-03-26 10:00'),
            'booking-123',
        );

        $this->assertTrue($result, 'Should NOT skip event with different Exchange event ID');
    }

    public function testExcludeByExchangeEventIdNoLocalMapping(): void {
        // Exchange returns event without ROOMVOX_UID_PROP
        $this->graphClient->method('get')->willReturn(
            $this->buildResponse([
                $this->buildEvent([
                    'id' => 'exchange-event-AAMk123',
                    'singleValueExtendedProperties' => [],
                ]),
            ])
        );

        // Local event has NO X-EXCHANGE-EVENT-ID
        $this->setupLocalEventWithoutExchangeId('booking-123');

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-03-26 09:00'),
            new \DateTime('2026-03-26 10:00'),
            'booking-123',
        );

        $this->assertTrue($result, 'Without both ROOMVOX_UID_PROP and local Exchange ID, event is a conflict');
    }

    // ── Both exclusion mechanisms together ───────────────────────────

    public function testExcludePrefersPropOverId(): void {
        $exchangeEventId = 'exchange-event-AAMk123';

        // Exchange event HAS ROOMVOX_UID_PROP (best case)
        $this->graphClient->method('get')->willReturn(
            $this->buildResponse([
                $this->buildEvent([
                    'id' => $exchangeEventId,
                    'singleValueExtendedProperties' => [
                        ['id' => GraphApiClient::ROOMVOX_UID_PROP, 'value' => 'booking-123'],
                    ],
                ]),
            ])
        );

        $this->setupLocalEventWithExchangeId('booking-123', $exchangeEventId);

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-03-26 09:00'),
            new \DateTime('2026-03-26 10:00'),
            'booking-123',
        );

        $this->assertFalse($result, 'Should skip when both UID prop and Exchange ID match');
    }

    // ── Multiple events: exclude self but detect real conflict ───────

    public function testExcludeSelfButDetectOtherConflict(): void {
        $exchangeEventId = 'exchange-event-AAMk123';

        // Two events: one is ours (no ROOMVOX_UID_PROP), one is a real conflict
        $this->graphClient->method('get')->willReturn(
            $this->buildResponse([
                $this->buildEvent([
                    'id' => $exchangeEventId,
                    'subject' => 'Our booking',
                    'singleValueExtendedProperties' => [],
                ]),
                $this->buildEvent([
                    'id' => 'exchange-event-OTHER',
                    'subject' => 'Someone else meeting',
                    'singleValueExtendedProperties' => [],
                ]),
            ])
        );

        $this->setupLocalEventWithExchangeId('booking-123', $exchangeEventId);

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-03-26 09:00'),
            new \DateTime('2026-03-26 10:00'),
            'booking-123',
        );

        $this->assertTrue($result, 'Should skip our event but still detect the other conflict');
    }

    public function testExcludeSelfOnlyEventNoConflict(): void {
        $exchangeEventId = 'exchange-event-AAMk123';

        // Only our event on Exchange (no ROOMVOX_UID_PROP)
        $this->graphClient->method('get')->willReturn(
            $this->buildResponse([
                $this->buildEvent([
                    'id' => $exchangeEventId,
                    'singleValueExtendedProperties' => [],
                ]),
            ])
        );

        $this->setupLocalEventWithExchangeId('booking-123', $exchangeEventId);

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-03-26 09:00'),
            new \DateTime('2026-03-26 10:00'),
            'booking-123',
        );

        $this->assertFalse($result, 'Only our own event exists — no conflict');
    }

    // ── No excludeUid provided → normal conflict detection ──────────

    public function testNoExcludeUidDetectsConflict(): void {
        $this->graphClient->method('get')->willReturn(
            $this->buildResponse([
                $this->buildEvent(['showAs' => 'busy']),
            ])
        );

        $this->calDavBackend->method('getCalendarsForUser')->willReturn([]);

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-03-26 09:00'),
            new \DateTime('2026-03-26 10:00'),
            null, // No excludeUid
        );

        $this->assertTrue($result, 'Without excludeUid, busy event is a conflict');
    }

    // ── No calendar for room → Exchange ID lookup returns null ───────

    public function testNoCalendarFallsBackToConflict(): void {
        // Exchange returns event without ROOMVOX_UID_PROP
        $this->graphClient->method('get')->willReturn(
            $this->buildResponse([
                $this->buildEvent([
                    'id' => 'exchange-event-AAMk123',
                    'singleValueExtendedProperties' => [],
                ]),
            ])
        );

        // No calendar for room → getExchangeEventId returns null
        $this->calDavBackend->method('getCalendarsForUser')->willReturn([]);

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-03-26 09:00'),
            new \DateTime('2026-03-26 10:00'),
            'booking-123',
        );

        $this->assertTrue($result, 'Without calendar, cannot look up Exchange ID → conflict');
    }
}
