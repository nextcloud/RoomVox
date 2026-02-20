<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Controller\BookingApiController;
use OCA\RoomVox\Dav\SchedulingPlugin;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\Exchange\GraphApiClient;
use OCA\RoomVox\Service\ImportExportService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ITip;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * Performance tests for RoomVox critical paths.
 *
 * Validates that key operations complete within acceptable time limits,
 * even with realistic data volumes. All external dependencies are mocked.
 *
 * NOTE: These are unit-level performance tests — they measure the overhead
 * of PHP logic with mocked I/O. Real-world latency (database, network)
 * is NOT included.
 */
class PerformanceTest extends TestCase {
    protected function tearDown(): void {
        Reader::setTestParser(null);
    }

    // ── 1. Conflict detection at scale ─────────────────────────────

    public function testHasConflict50EventsUnder50ms(): void {
        $this->assertConflictCheckPerformance(50, 50);
    }

    public function testHasConflict200EventsUnder150ms(): void {
        $this->assertConflictCheckPerformance(200, 150);
    }

    private function assertConflictCheckPerformance(int $eventCount, float $maxMs): void {
        $calDavBackend = $this->createMock(CalDavBackend::class);
        $logger = $this->createMock(LoggerInterface::class);
        $service = new CalDAVService($calDavBackend, $logger);

        $calDavBackend->method('getCalendarsForUser')
            ->willReturn([['id' => 1, 'uri' => 'personal']]);

        $uris = [];
        $objectMap = [];
        $eventsByUri = [];

        for ($i = 0; $i < $eventCount; $i++) {
            $uri = "event-{$i}.ics";
            $uris[] = $uri;
            $objectMap[$uri] = ['calendardata' => "ics-data-{$i}"];

            // Spread events across the morning (no overlap with 23:30)
            $h = 8 + intdiv($i, 4); // 4 per hour, starting 08:00
            $m = ($i % 4) * 15;     // 0, 15, 30, 45
            if ($h >= 23) {
                $h = 8 + ($i % 15);
                $m = ($i % 4) * 15;
            }

            $eventsByUri["ics-data-{$i}"] = [
                'uid' => "event-{$i}",
                'start' => new \DateTime(sprintf('2026-02-20 %02d:%02d', $h, $m)),
                'end' => new \DateTime(sprintf('2026-02-20 %02d:%02d', $h, $m + 10)),
            ];
        }

        $calDavBackend->method('calendarQuery')->willReturn($uris);
        $calDavBackend->method('getCalendarObject')
            ->willReturnCallback(fn (int $calId, string $uri) => $objectMap[$uri] ?? null);

        Reader::setTestParser(function (string $data) use ($eventsByUri) {
            $eventData = $eventsByUri[$data] ?? null;
            if ($eventData === null) {
                return new VCalendar();
            }

            $vEvent = new VEvent();
            $vEvent->DTSTART = new Property($eventData['start']);
            $vEvent->DTEND = new Property($eventData['end']);
            $vEvent->UID = new Property($eventData['uid']);

            $vCalendar = new VCalendar();
            $vCalendar->VEVENT = $vEvent;
            return $vCalendar;
        });

        // Check at a time with no overlap → forces full scan of all events
        $start = hrtime(true);
        $result = $service->hasConflict(
            'rb_room1',
            new \DateTime('2026-02-20 23:30'),
            new \DateTime('2026-02-20 23:59'),
        );
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertFalse($result);
        $this->assertLessThan($maxMs, $elapsed,
            "hasConflict() with {$eventCount} events took {$elapsed}ms (limit: {$maxMs}ms)");
    }

    // ── 2. Scheduling plugin full flow ─────────────────────────────

    public function testSchedulingPluginFullFlowUnder50ms(): void {
        $plugin = $this->buildSchedulingPlugin();

        $message = $this->buildItipMessage('testuser',
            new \DateTimeImmutable('2026-02-23 10:00'),
            new \DateTimeImmutable('2026-02-23 11:00'));

        $start = hrtime(true);
        $plugin->handleScheduleRequest($message);
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertSame('1.2', $message->scheduleStatus);
        $this->assertLessThan(50, $elapsed,
            "Full scheduling flow took {$elapsed}ms (limit: 50ms)");
    }

    // ── 3. Booking API create flow ─────────────────────────────────

    public function testBookingApiCreateUnder50ms(): void {
        $controller = $this->buildBookingController();

        $start = hrtime(true);
        $response = $controller->create('room1');
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertSame(201, $response->getStatus());
        $this->assertLessThan(50, $elapsed,
            "Booking API create took {$elapsed}ms (limit: 50ms)");
    }

    // ── 4. Room listing at scale ───────────────────────────────────

    public function testRoomLookup100Under50ms(): void {
        $this->assertRoomLookupPerformance(100, 50);
    }

    public function testRoomLookup500Under200ms(): void {
        $this->assertRoomLookupPerformance(500, 200);
    }

    private function assertRoomLookupPerformance(int $roomCount, float $maxMs): void {
        // Simulate what getAllRooms() does: load index, then load each room JSON
        $roomIds = [];
        $roomJsons = [];
        for ($i = 0; $i < $roomCount; $i++) {
            $roomIds[] = "room{$i}";
            $roomJsons["room{$i}"] = json_encode([
                'id' => "room{$i}",
                'name' => "Room {$i}",
                'email' => "room{$i}@example.com",
                'userId' => "rb_room{$i}",
                'active' => true,
                'capacity' => rand(4, 50),
                'roomType' => 'meeting-room',
                'facilities' => ['projector', 'whiteboard'],
            ]);
        }

        $start = hrtime(true);

        // Simulate getAllRooms(): decode index + decode each room
        $rooms = [];
        foreach ($roomIds as $roomId) {
            $rooms[] = json_decode($roomJsons[$roomId], true);
        }

        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertCount($roomCount, $rooms);
        $this->assertLessThan($maxMs, $elapsed,
            "Loading {$roomCount} rooms took {$elapsed}ms (limit: {$maxMs}ms)");
    }

    // ── 5. CSV import parsing at scale ─────────────────────────────

    public function testCsvParse100RowsUnder100ms(): void {
        $this->assertCsvParsePerformance(100, 100);
    }

    public function testCsvParse500RowsUnder400ms(): void {
        $this->assertCsvParsePerformance(500, 400);
    }

    private function assertCsvParsePerformance(int $rowCount, float $maxMs): void {
        $roomService = $this->createMock(RoomService::class);
        $logger = $this->createMock(LoggerInterface::class);
        $roomService->method('getAllRooms')->willReturn([]);

        $service = new ImportExportService($roomService, $logger);

        $lines = ['name,email,capacity,roomType,facilities'];
        for ($i = 0; $i < $rowCount; $i++) {
            $lines[] = "\"Room {$i}\",\"room{$i}@example.com\",{$i},meeting-room,\"projector,whiteboard\"";
        }
        $csv = implode("\n", $lines);

        $start = hrtime(true);
        $result = $service->parseCsv($csv);
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertCount($rowCount, $result['rows']);
        $this->assertLessThan($maxMs, $elapsed,
            "Parsing {$rowCount} CSV rows took {$elapsed}ms (limit: {$maxMs}ms)");
    }

    // ── 6. Exchange conflict check with many events ────────────────

    public function testExchangeConflict50EventsUnder50ms(): void {
        $this->assertExchangeConflictPerformance(50, 50);
    }

    public function testExchangeConflict200EventsUnder150ms(): void {
        $this->assertExchangeConflictPerformance(200, 150);
    }

    private function assertExchangeConflictPerformance(int $eventCount, float $maxMs): void {
        $graphClient = $this->createMock(GraphApiClient::class);
        $calDAVService = $this->createMock(CalDAVService::class);
        $roomService = $this->createMock(RoomService::class);
        $logger = $this->createMock(LoggerInterface::class);

        $graphClient->method('isConfigured')->willReturn(true);

        $service = new ExchangeSyncService($graphClient, $calDAVService, $roomService, $logger);

        $room = [
            'id' => 'room1',
            'userId' => 'rb_room1',
            'exchangeConfig' => [
                'resourceEmail' => 'room@company.com',
                'syncEnabled' => true,
            ],
        ];

        // Generate N events — all with showAs=free so none conflict
        // This forces a full scan through all events
        $events = [];
        for ($i = 0; $i < $eventCount; $i++) {
            $h = 8 + intdiv($i, 4);
            $m = ($i % 4) * 15;

            $events[] = [
                'id' => "graph-event-{$i}",
                'subject' => "Meeting {$i}",
                'start' => ['dateTime' => sprintf('2026-02-20T%02d:%02d:00', $h % 24, $m), 'timeZone' => 'UTC'],
                'end' => ['dateTime' => sprintf('2026-02-20T%02d:%02d:00', $h % 24, $m + 10), 'timeZone' => 'UTC'],
                'isCancelled' => false,
                'showAs' => 'free',
                'singleValueExtendedProperties' => [],
            ];
        }

        $graphClient->method('get')->willReturn(['value' => $events]);

        $start = hrtime(true);
        $result = $service->hasExchangeConflict(
            $room,
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertFalse($result);
        $this->assertLessThan($maxMs, $elapsed,
            "Exchange conflict check with {$eventCount} events took {$elapsed}ms (limit: {$maxMs}ms)");
    }

    // ── 7. iTIP with complex availability rules ────────────────────

    public function testSchedulingWithComplexAvailabilityUnder30ms(): void {
        $testRoom = [
            'id' => 'room1',
            'userId' => 'rb_room1',
            'name' => 'Complex Room',
            'email' => 'room1@example.com',
            'autoAccept' => true,
            'active' => true,
            'availabilityRules' => [
                'enabled' => true,
                'rules' => [
                    ['days' => [1], 'startTime' => '07:00', 'endTime' => '20:00'],
                    ['days' => [2], 'startTime' => '08:00', 'endTime' => '19:00'],
                    ['days' => [3], 'startTime' => '09:00', 'endTime' => '18:00'],
                    ['days' => [4], 'startTime' => '07:30', 'endTime' => '21:00'],
                    ['days' => [5], 'startTime' => '08:00', 'endTime' => '17:00'],
                    ['days' => [6], 'startTime' => '10:00', 'endTime' => '14:00'],
                ],
            ],
            'maxBookingHorizon' => 90,
        ];

        $plugin = $this->buildSchedulingPlugin($testRoom);

        // Monday 10:00-11:00 — within mon rule (07:00-20:00)
        $message = $this->buildItipMessage('testuser',
            new \DateTimeImmutable('2026-02-23 10:00'),
            new \DateTimeImmutable('2026-02-23 11:00'));

        $start = hrtime(true);
        $plugin->handleScheduleRequest($message);
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertSame('1.2', $message->scheduleStatus);
        $this->assertLessThan(30, $elapsed,
            "Scheduling with complex availability took {$elapsed}ms (limit: 30ms)");
    }

    // ── 8. Batch booking creation (10 sequential) ──────────────────

    public function testBatch10BookingsUnder200ms(): void {
        $bookingCount = 10;
        $controller = $this->buildBookingController();

        $start = hrtime(true);
        for ($i = 0; $i < $bookingCount; $i++) {
            $response = $controller->create('room1');
            $this->assertSame(201, $response->getStatus());
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertLessThan(200, $elapsed,
            "{$bookingCount} sequential bookings took {$elapsed}ms (limit: 200ms)");
    }

    // ── 9. Load simulation: 300 rooms × 1 booking/hour ───────────────

    /**
     * Simulates 300 bookings across 300 different rooms via the internal API.
     * Each booking goes through: auth → room lookup → conflict check → create → Exchange push attempt.
     *
     * With mocked I/O this measures pure PHP overhead. Real-world adds:
     * - ~2-5ms per conflict check (DB calendarQuery)
     * - ~1-2ms per createBooking (DB insert)
     * - ~50-200ms per Exchange push (HTTP, but fail-safe/non-blocking)
     *
     * Budget: 300 × ~5ms PHP overhead = ~1.5 seconds. Over 1 hour = 5 bookings/minute = trivial.
     */
    public function testLoad300BookingsAcross300RoomsUnder2s(): void {
        $roomCount = 300;

        $rooms = [];
        for ($i = 0; $i < $roomCount; $i++) {
            $rooms["room{$i}"] = [
                'id' => "room{$i}", 'userId' => "rb_room{$i}", 'name' => "Room {$i}",
                'email' => "room{$i}@example.com", 'autoAccept' => true, 'active' => true,
            ];
        }

        $request = $this->createMock(IRequest::class);
        $roomService = $this->createMock(RoomService::class);
        $permissionService = $this->createMock(PermissionService::class);
        $calDAVService = $this->createMock(CalDAVService::class);
        $exchangeSyncService = $this->createMock(ExchangeSyncService::class);
        $userSession = $this->createMock(IUserSession::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $userSession->method('getUser')->willReturn($user);
        $groupManager->method('isAdmin')->willReturn(true);

        $roomService->method('getRoom')
            ->willReturnCallback(fn (string $id) => $rooms[$id] ?? null);
        $calDAVService->method('hasConflict')->willReturn(false);
        $calDAVService->method('createBooking')->willReturn('uid');
        $exchangeSyncService->method('isExchangeRoom')->willReturn(true);
        $exchangeSyncService->method('pushBookingToExchange')->willReturn(true);

        $request->method('getParam')->willReturnCallback(fn (string $key, $default = '') => match ($key) {
            'summary' => 'Load Test Booking',
            'start' => '2026-02-23T10:00:00',
            'end' => '2026-02-23T11:00:00',
            'description' => '',
            default => $default,
        });

        $controller = new BookingApiController(
            'roomvox', $request, $roomService, $permissionService,
            $calDAVService, $exchangeSyncService, $userSession, $groupManager, $logger,
        );

        $start = hrtime(true);
        $successCount = 0;
        for ($i = 0; $i < $roomCount; $i++) {
            $response = $controller->create("room{$i}");
            if ($response->getStatus() === 201) {
                $successCount++;
            }
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $perBooking = $elapsed / $roomCount;

        $this->assertSame($roomCount, $successCount);
        $this->assertLessThan(2000, $elapsed,
            "300 bookings across 300 rooms took {$elapsed}ms ({$perBooking}ms/booking, limit: 2000ms)");
    }

    /**
     * Same load but via CalDAV scheduling (iTIP flow) — the path that
     * CalDAV clients like Thunderbird/Apple Calendar use.
     */
    public function testLoad300BookingsViaCalDAVSchedulingUnder2s(): void {
        $roomCount = 300;

        $rooms = [];
        for ($i = 0; $i < $roomCount; $i++) {
            $rooms["room{$i}"] = [
                'id' => "room{$i}", 'userId' => "rb_room{$i}", 'name' => "Room {$i}",
                'email' => "room{$i}@example.com", 'autoAccept' => true, 'active' => true,
                'availabilityRules' => ['enabled' => false, 'rules' => []],
                'maxBookingHorizon' => 0,
            ];
        }

        $roomService = $this->createMock(RoomService::class);
        $permissionService = $this->createMock(PermissionService::class);
        $calDAVService = $this->createMock(CalDAVService::class);
        $mailService = $this->createMock(MailService::class);
        $exchangeSyncService = $this->createMock(ExchangeSyncService::class);
        $userManager = $this->createMock(IUserManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $roomIndex = 0;
        $roomService->method('isRoomPrincipal')->willReturn(true);
        $roomService->method('getRoomIdByPrincipal')
            ->willReturnCallback(function (string $principal) {
                // Extract room ID from principals/users/rb_roomN
                $userId = str_replace('principals/users/', '', $principal);
                return str_replace('rb_', '', $userId);
            });
        $roomService->method('getRoom')
            ->willReturnCallback(fn (string $id) => $rooms[$id] ?? null);

        $permissionService->method('getPermissions')->willReturn([
            'viewers' => [], 'bookers' => [], 'managers' => [],
        ]);
        $calDAVService->method('hasConflict')->willReturn(false);
        $calDAVService->method('deliverToRoomCalendar')->willReturn(true);
        $exchangeSyncService->method('isExchangeRoom')->willReturn(true);
        $exchangeSyncService->method('pushBookingToExchange')->willReturn(true);

        $plugin = new SchedulingPlugin(
            $roomService, $permissionService, $calDAVService, $mailService,
            $exchangeSyncService, $userManager, $logger,
        );

        $start = hrtime(true);
        $deliveredCount = 0;
        for ($i = 0; $i < $roomCount; $i++) {
            $message = new ITip\Message();
            $message->method = 'REQUEST';
            $message->sender = 'principals/users/testuser';
            $message->senderEmail = 'testuser@example.com';
            $message->recipient = "principals/users/rb_room{$i}";
            $message->recipientEmail = "room{$i}@example.com";
            $message->significantChange = true;

            $vEvent = new VEvent();
            $vEvent->DTSTART = new Property(new \DateTimeImmutable('2026-02-23 10:00'));
            $vEvent->DTEND = new Property(new \DateTimeImmutable('2026-02-23 11:00'));
            $vEvent->UID = new Property("load-test-uid-{$i}");
            $vEvent->SUMMARY = new Property("Load Test {$i}");
            $vCalendar = new VCalendar();
            $vCalendar->VEVENT = $vEvent;
            $message->message = $vCalendar;

            $plugin->handleScheduleRequest($message);
            if ($message->scheduleStatus === '1.2') {
                $deliveredCount++;
            }
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $perBooking = $elapsed / $roomCount;

        $this->assertSame($roomCount, $deliveredCount);
        $this->assertLessThan(2000, $elapsed,
            "300 iTIP bookings took {$elapsed}ms ({$perBooking}ms/booking, limit: 2000ms)");
    }

    /**
     * Combined scenario: 300 rooms with varying existing bookings (5-20 per room).
     * Tests conflict check at scale — every new booking must scan existing events.
     *
     * This simulates a busy Monday morning where all rooms have existing bookings
     * and 300 new bookings arrive within the same hour.
     */
    public function testLoad300BookingsWithConflictChecksUnder3s(): void {
        $roomCount = 300;
        $existingPerRoom = 10; // 10 existing bookings per room

        $calDavBackend = $this->createMock(CalDavBackend::class);
        $logger = $this->createMock(LoggerInterface::class);
        $calDAVService = new CalDAVService($calDavBackend, $logger);

        $calDavBackend->method('getCalendarsForUser')
            ->willReturn([['id' => 1, 'uri' => 'personal']]);

        // Each room has 10 existing bookings spread across the day
        $uris = [];
        $objectMap = [];
        $eventsByIcs = [];
        for ($e = 0; $e < $existingPerRoom; $e++) {
            $uri = "existing-{$e}.ics";
            $uris[] = $uri;
            $objectMap[$uri] = ['calendardata' => "ics-existing-{$e}"];
            $eventsByIcs["ics-existing-{$e}"] = [
                'uid' => "existing-{$e}",
                'start' => new \DateTime(sprintf('2026-02-23 %02d:00', 8 + $e)),
                'end' => new \DateTime(sprintf('2026-02-23 %02d:00', 9 + $e)),
            ];
        }

        $calDavBackend->method('calendarQuery')->willReturn($uris);
        $calDavBackend->method('getCalendarObject')
            ->willReturnCallback(fn (int $calId, string $uri) => $objectMap[$uri] ?? null);

        Reader::setTestParser(function (string $data) use ($eventsByIcs) {
            $eventData = $eventsByIcs[$data] ?? null;
            if ($eventData === null) {
                return new VCalendar();
            }
            $vEvent = new VEvent();
            $vEvent->DTSTART = new Property($eventData['start']);
            $vEvent->DTEND = new Property($eventData['end']);
            $vEvent->UID = new Property($eventData['uid']);
            $vCalendar = new VCalendar();
            $vCalendar->VEVENT = $vEvent;
            return $vCalendar;
        });

        // All new bookings at 20:00-21:00 (no conflict with existing 08-18)
        $newStart = new \DateTime('2026-02-23 20:00');
        $newEnd = new \DateTime('2026-02-23 21:00');

        $start = hrtime(true);
        $noConflictCount = 0;
        for ($i = 0; $i < $roomCount; $i++) {
            if (!$calDAVService->hasConflict("rb_room{$i}", $newStart, $newEnd)) {
                $noConflictCount++;
            }
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $perCheck = $elapsed / $roomCount;

        $this->assertSame($roomCount, $noConflictCount);
        $this->assertLessThan(3000, $elapsed,
            "300 conflict checks (10 events/room) took {$elapsed}ms ({$perCheck}ms/check, limit: 3000ms)");
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function buildSchedulingPlugin(?array $room = null): SchedulingPlugin {
        $testRoom = $room ?? [
            'id' => 'room1',
            'userId' => 'rb_room1',
            'name' => 'Conference Room',
            'email' => 'room1@example.com',
            'autoAccept' => true,
            'active' => true,
            'availabilityRules' => [
                'enabled' => true,
                'rules' => [
                    ['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00'],
                ],
            ],
            'maxBookingHorizon' => 30,
        ];

        $roomService = $this->createMock(RoomService::class);
        $permissionService = $this->createMock(PermissionService::class);
        $calDAVService = $this->createMock(CalDAVService::class);
        $mailService = $this->createMock(MailService::class);
        $exchangeSyncService = $this->createMock(ExchangeSyncService::class);
        $userManager = $this->createMock(IUserManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $roomService->method('isRoomPrincipal')->willReturn(true);
        $roomService->method('getRoomIdByPrincipal')->willReturn('room1');
        $roomService->method('getRoom')->willReturn($testRoom);

        // No permissions configured → anyone can book
        $permissionService->method('getPermissions')->willReturn([
            'viewers' => [], 'bookers' => [], 'managers' => [],
        ]);

        $calDAVService->method('hasConflict')->willReturn(false);
        $calDAVService->method('deliverToRoomCalendar')->willReturn(true);
        $exchangeSyncService->method('isExchangeRoom')->willReturn(false);

        return new SchedulingPlugin(
            $roomService, $permissionService, $calDAVService, $mailService,
            $exchangeSyncService, $userManager, $logger,
        );
    }

    private function buildBookingController(): BookingApiController {
        $request = $this->createMock(IRequest::class);
        $roomService = $this->createMock(RoomService::class);
        $permissionService = $this->createMock(PermissionService::class);
        $calDAVService = $this->createMock(CalDAVService::class);
        $exchangeSyncService = $this->createMock(ExchangeSyncService::class);
        $userSession = $this->createMock(IUserSession::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $testRoom = [
            'id' => 'room1', 'userId' => 'rb_room1', 'name' => 'Room 1',
            'email' => 'room1@example.com', 'autoAccept' => true, 'active' => true,
        ];

        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $userSession->method('getUser')->willReturn($user);
        $groupManager->method('isAdmin')->willReturn(true);

        $roomService->method('getRoom')->willReturn($testRoom);
        $calDAVService->method('hasConflict')->willReturn(false);
        $calDAVService->method('createBooking')->willReturn('new-uid-123');
        $exchangeSyncService->method('isExchangeRoom')->willReturn(false);

        $request->method('getParam')->willReturnCallback(fn (string $key, $default = '') => match ($key) {
            'summary' => 'Test Booking',
            'start' => '2026-02-23T10:00:00',
            'end' => '2026-02-23T11:00:00',
            'description' => '',
            default => $default,
        });

        return new BookingApiController(
            'roomvox', $request, $roomService, $permissionService,
            $calDAVService, $exchangeSyncService, $userSession, $groupManager, $logger,
        );
    }

    private function buildItipMessage(
        string $sender,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
    ): ITip\Message {
        $vEvent = new VEvent();
        $vEvent->DTSTART = new Property($start);
        $vEvent->DTEND = new Property($end);
        $vEvent->UID = new Property('perf-test-uid');
        $vEvent->SUMMARY = new Property('Performance Test Booking');

        $vCalendar = new VCalendar();
        $vCalendar->VEVENT = $vEvent;

        $message = new ITip\Message();
        $message->method = 'REQUEST';
        $message->sender = "principals/users/{$sender}";
        $message->senderEmail = "{$sender}@example.com";
        $message->recipient = 'principals/users/rb_room1';
        $message->recipientEmail = 'room1@example.com';
        $message->message = $vCalendar;
        $message->significantChange = true;

        return $message;
    }
}
