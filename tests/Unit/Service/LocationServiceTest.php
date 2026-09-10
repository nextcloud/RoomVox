<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\LocationService;
use OCA\RoomVox\Service\RoomService;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LocationServiceTest extends TestCase {
    private array $config = [];
    private LocationService $locations;
    private RoomService $rooms;

    protected function setUp(): void {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            fn(string $app, string $key, string $default = '') => $this->config[$key] ?? $default
        );
        $config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
            $this->config[$key] = $value;
            return true;
        });
        $this->locations = new LocationService($config);
        $this->rooms = new RoomService(
            $config,
            $this->createMock(ICrypto::class),
            $this->createMock(ISecureRandom::class),
            $this->createMock(LoggerInterface::class),
            $this->locations,
        );
    }

    public function testLocationCanBeCreatedUpdatedAndDeleted(): void {
        $location = $this->locations->saveLocation(['name' => ' Vienna ', 'street' => 'Main Street 1']);
        $this->assertSame('Vienna', $location['name']);
        $updated = $this->locations->saveLocation(['city' => 'Wien'], $location['id']);
        $this->assertSame($location['id'], $updated['id']);
        $this->assertSame('Main Street 1', $updated['street']);
        $this->assertSame([$updated], $this->locations->getAllLocations());
        $this->assertTrue($this->locations->deleteLocation($location['id']));
        $this->assertSame([], $this->locations->getAllLocations());
        $this->assertNull($this->locations->saveLocation(['name' => 'Missing'], $location['id']));
    }

    public function testBlankNameIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->locations->saveLocation(['name' => '  ']);
    }

    public function testAssignedRoomUsesLatestLocationAddress(): void {
        $location = $this->locations->saveLocation([
            'name' => 'Head office', 'building' => 'Building A', 'street' => 'Main Street 1',
            'postalCode' => '1010', 'city' => 'Vienna', 'country' => 'Austria',
        ]);
        $room = $this->rooms->createRoom(['name' => 'Meeting room', 'locationId' => $location['id']]);
        $this->assertSame($location['id'], $room['locationId']);
        $this->assertSame('Building A, Main Street 1, 1010, Vienna, Austria', $room['address']);
        $this->locations->saveLocation(['street' => 'New Street 2'], $location['id']);
        $this->assertSame('Building A, New Street 2, 1010, Vienna, Austria', $this->rooms->getRoom($room['id'])['address']);
        $this->assertStringContainsString('New Street 2', $this->rooms->buildRoomLocation($room));
    }

    public function testAssignmentCanBeRemovedAndManualAddressUsed(): void {
        $location = $this->locations->saveLocation(['name' => 'Head office']);
        $room = $this->rooms->createRoom(['name' => 'Meeting room', 'locationId' => $location['id']]);
        $this->rooms->updateRoom($room['id'], ['locationId' => null, 'address' => 'Manual address']);
        $saved = $this->rooms->getRoom($room['id']);
        $this->assertNull($saved['locationId']);
        $this->assertSame('Manual address', $saved['address']);
    }

    public function testUnknownLocationCannotBeAssigned(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->rooms->createRoom(['name' => 'Meeting room', 'locationId' => 'missing']);
    }

    public function testLegacyRoomKeepsItsAddress(): void {
        $this->config['room/legacy'] = json_encode(['id' => 'legacy', 'name' => 'Legacy', 'roomNumber' => '1', 'address' => 'Existing address']);
        $this->assertSame('Existing address', $this->rooms->getRoom('legacy')['address']);
    }
}
