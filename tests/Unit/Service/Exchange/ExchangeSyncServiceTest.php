<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service\Exchange;

use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\Exchange\GraphApiClient;
use OCA\RoomVox\Service\RoomService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExchangeSyncServiceTest extends TestCase {
    private ExchangeSyncService $service;
    private GraphApiClient $graphClient;
    private CalDAVService $calDAVService;
    private RoomService $roomService;

    protected function setUp(): void {
        $this->graphClient = $this->createMock(GraphApiClient::class);
        $this->calDAVService = $this->createMock(CalDAVService::class);
        $this->roomService = $this->createMock(RoomService::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new ExchangeSyncService(
            $this->graphClient,
            $this->calDAVService,
            $this->roomService,
            $logger,
        );
    }

    public function testIsExchangeRoomEnabled(): void {
        $this->graphClient->method('isConfigured')->willReturn(true);

        $room = [
            'id' => 'room1',
            'exchangeConfig' => [
                'resourceEmail' => 'room@company.com',
                'syncEnabled' => true,
            ],
        ];

        $this->assertTrue($this->service->isExchangeRoom($room));
    }

    public function testIsExchangeRoomDisabledGlobally(): void {
        $this->graphClient->method('isConfigured')->willReturn(false);

        $room = [
            'id' => 'room1',
            'exchangeConfig' => [
                'resourceEmail' => 'room@company.com',
                'syncEnabled' => true,
            ],
        ];

        $this->assertFalse($this->service->isExchangeRoom($room));
    }

    public function testIsExchangeRoomNoConfig(): void {
        $this->graphClient->method('isConfigured')->willReturn(true);

        $room = ['id' => 'room1', 'exchangeConfig' => null];
        $this->assertFalse($this->service->isExchangeRoom($room));
    }

    public function testIsExchangeRoomSyncDisabled(): void {
        $this->graphClient->method('isConfigured')->willReturn(true);

        $room = [
            'id' => 'room1',
            'exchangeConfig' => [
                'resourceEmail' => 'room@company.com',
                'syncEnabled' => false,
            ],
        ];

        $this->assertFalse($this->service->isExchangeRoom($room));
    }

    public function testIsExchangeRoomNoEmail(): void {
        $this->graphClient->method('isConfigured')->willReturn(true);

        $room = [
            'id' => 'room1',
            'exchangeConfig' => [
                'resourceEmail' => '',
                'syncEnabled' => true,
            ],
        ];

        $this->assertFalse($this->service->isExchangeRoom($room));
    }

    public function testPullChangesSkipsNonExchangeRoom(): void {
        $this->graphClient->method('isConfigured')->willReturn(false);

        $room = ['id' => 'room1', 'exchangeConfig' => null];
        $result = $this->service->pullExchangeChanges($room);

        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->deleted);
    }

    // ── Exchange Conflict Check ─────────────────────────────────────

    private array $exchangeRoom = [
        'id' => 'room1',
        'exchangeConfig' => [
            'resourceEmail' => 'room@company.com',
            'syncEnabled' => true,
        ],
    ];

    /**
     * Helper: build a Graph API calendarView response with one event.
     */
    private function buildCalendarViewResponse(array $eventOverrides = []): array {
        $event = array_merge([
            'id' => 'exchange-event-1',
            'subject' => 'Meeting',
            'start' => ['dateTime' => '2026-02-20T10:00:00', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-02-20T11:00:00', 'timeZone' => 'UTC'],
            'isCancelled' => false,
            'showAs' => 'busy',
            'singleValueExtendedProperties' => [],
        ], $eventOverrides);

        return ['value' => [$event]];
    }

    public function testHasExchangeConflictBusy(): void {
        $this->graphClient->method('isConfigured')->willReturn(true);
        $this->graphClient->method('get')->willReturn(
            $this->buildCalendarViewResponse(['showAs' => 'busy'])
        );

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertTrue($result);
    }

    public function testHasExchangeConflictTentative(): void {
        $this->graphClient->method('isConfigured')->willReturn(true);
        $this->graphClient->method('get')->willReturn(
            $this->buildCalendarViewResponse(['showAs' => 'tentative'])
        );

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertTrue($result);
    }

    public function testHasExchangeConflictFree(): void {
        $this->graphClient->method('isConfigured')->willReturn(true);
        $this->graphClient->method('get')->willReturn(
            $this->buildCalendarViewResponse(['showAs' => 'free'])
        );

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertFalse($result);
    }

    public function testHasExchangeConflictOof(): void {
        $this->graphClient->method('isConfigured')->willReturn(true);
        $this->graphClient->method('get')->willReturn(
            $this->buildCalendarViewResponse(['showAs' => 'oof'])
        );

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertTrue($result);
    }

    public function testHasExchangeConflictWorkingElsewhere(): void {
        $this->graphClient->method('isConfigured')->willReturn(true);
        $this->graphClient->method('get')->willReturn(
            $this->buildCalendarViewResponse(['showAs' => 'workingElsewhere'])
        );

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertTrue($result);
    }

    public function testHasExchangeConflictCancelled(): void {
        $this->graphClient->method('isConfigured')->willReturn(true);
        $this->graphClient->method('get')->willReturn(
            $this->buildCalendarViewResponse(['isCancelled' => true])
        );

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertFalse($result);
    }

    public function testHasExchangeConflictExcludeUid(): void {
        $this->graphClient->method('isConfigured')->willReturn(true);
        $this->graphClient->method('get')->willReturn(
            $this->buildCalendarViewResponse([
                'singleValueExtendedProperties' => [
                    ['id' => GraphApiClient::ROOMVOX_UID_PROP, 'value' => 'booking-123'],
                ],
            ])
        );

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
            'booking-123',
        );

        $this->assertFalse($result);
    }

    public function testHasExchangeConflictNoEvents(): void {
        $this->graphClient->method('isConfigured')->willReturn(true);
        $this->graphClient->method('get')->willReturn(['value' => []]);

        $result = $this->service->hasExchangeConflict(
            $this->exchangeRoom,
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertFalse($result);
    }

    public function testHasExchangeConflictNotExchangeRoom(): void {
        $this->graphClient->method('isConfigured')->willReturn(false);

        $room = ['id' => 'room1', 'exchangeConfig' => null];

        $result = $this->service->hasExchangeConflict(
            $room,
            new \DateTime('2026-02-20 10:00'),
            new \DateTime('2026-02-20 11:00'),
        );

        $this->assertFalse($result);
    }
}
