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
}
