<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\LicenseService;
use OCA\RoomVox\Service\RoomGroupService;
use OCA\RoomVox\Service\RoomService;
use OCA\RoomVox\Service\TelemetryService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the Exchange figures in the telemetry payload.
 *
 * Two things are worth a test here. The count has to agree with
 * ExchangeSyncService::isExchangeRoom(), because a figure that quietly
 * disagrees with what actually syncs is worse than no figure at all -- it
 * would be read as "nobody uses this" right before the integration is
 * dropped. And the payload must never carry the tenant id, client id or
 * client secret that sit next to the flag in appconfig.
 */
class TelemetryServiceExchangeTest extends TestCase {
    private TelemetryService $service;
    private IConfig $config;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);

        $this->service = new TelemetryService(
            $this->createMock(IClientService::class),
            $this->config,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IUserManager::class),
            $this->createMock(RoomService::class),
            $this->createMock(RoomGroupService::class),
            $this->createMock(LicenseService::class),
        );
    }

    private function countExchangeRooms(array $rooms): int {
        $method = new \ReflectionMethod(TelemetryService::class, 'calculateRoomStats');
        $method->setAccessible(true);
        return $method->invoke($this->service, $rooms)['roomsWithExchange'];
    }

    private function room(?array $exchangeConfig = null): array {
        $room = ['id' => 'r1', 'roomType' => 'meeting', 'capacity' => 4];
        if ($exchangeConfig !== null) {
            $room['exchangeConfig'] = $exchangeConfig;
        }
        return $room;
    }

    public function testCountsRoomWithResourceEmailAndSyncEnabled(): void {
        $this->assertSame(1, $this->countExchangeRooms([
            $this->room(['resourceEmail' => 'room@example.com', 'syncEnabled' => true]),
        ]));
    }

    /** A configured but switched-off room is not in use. */
    public function testSkipsRoomWithSyncDisabled(): void {
        $this->assertSame(0, $this->countExchangeRooms([
            $this->room(['resourceEmail' => 'room@example.com', 'syncEnabled' => false]),
        ]));
    }

    /** syncEnabled without a mailbox syncs nothing. */
    public function testSkipsRoomWithoutResourceEmail(): void {
        $this->assertSame(0, $this->countExchangeRooms([
            $this->room(['resourceEmail' => '', 'syncEnabled' => true]),
        ]));
    }

    public function testSkipsRoomWithoutExchangeConfig(): void {
        $this->assertSame(0, $this->countExchangeRooms([$this->room()]));
    }

    /** Rooms predating the integration store null; that must not warn or crash. */
    public function testHandlesNullExchangeConfig(): void {
        $this->assertSame(0, $this->countExchangeRooms([
            ['id' => 'r1', 'roomType' => 'meeting', 'exchangeConfig' => null],
        ]));
    }

    public function testCountsOnlyQualifyingRoomsInMixedSet(): void {
        $this->assertSame(2, $this->countExchangeRooms([
            $this->room(['resourceEmail' => 'a@example.com', 'syncEnabled' => true]),
            $this->room(['resourceEmail' => 'b@example.com', 'syncEnabled' => true]),
            $this->room(['resourceEmail' => 'c@example.com', 'syncEnabled' => false]),
            $this->room(),
        ]));
    }

    public function testFlagReadsExchangeEnabledKey(): void {
        $this->config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default) => $key === 'exchange_enabled' ? 'true' : $default);

        $method = new \ReflectionMethod(TelemetryService::class, 'isExchangeSyncEnabled');
        $method->setAccessible(true);
        $this->assertTrue($method->invoke($this->service));
    }

    public function testFlagDefaultsToFalse(): void {
        $this->config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default) => $default);

        $method = new \ReflectionMethod(TelemetryService::class, 'isExchangeSyncEnabled');
        $method->setAccessible(true);
        $this->assertFalse($method->invoke($this->service));
    }

    /**
     * The credentials live in appconfig right next to the flag. Reading any of
     * them here would put them one careless array_merge away from the payload.
     */
    public function testNeverReadsExchangeCredentials(): void {
        $forbidden = ['exchange_tenant_id', 'exchange_client_id', 'exchange_client_secret'];

        $this->config->method('getAppValue')
            ->willReturnCallback(function ($app, $key, $default) use ($forbidden) {
                $this->assertNotContains($key, $forbidden, "telemetry read the credential key {$key}");
                return $default;
            });

        $method = new \ReflectionMethod(TelemetryService::class, 'isExchangeSyncEnabled');
        $method->setAccessible(true);
        $method->invoke($this->service);
    }
}
