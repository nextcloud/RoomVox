<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\RoomService;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RoomServiceTest extends TestCase {
    private RoomService $service;
    private IAppConfig $appConfig;
    private ICrypto $crypto;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->crypto = $this->createMock(ICrypto::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new RoomService(
            $this->appConfig,
            $this->crypto,
            $this->logger,
        );
    }

    public function testStripMailto(): void {
        $this->assertSame('room@example.com', RoomService::stripMailto('mailto:room@example.com'));
        $this->assertSame('room@example.com', RoomService::stripMailto('MAILTO:room@example.com'));
    }

    public function testStripMailtoWithoutPrefix(): void {
        $this->assertSame('room@example.com', RoomService::stripMailto('room@example.com'));
        $this->assertSame('', RoomService::stripMailto(''));
    }

    public function testIsRoomAccountValid(): void {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                if ($key === 'room/testroom') {
                    return json_encode(['id' => 'testroom', 'name' => 'Test Room', 'email' => 'test@example.com']);
                }
                return $default;
            });

        $this->assertTrue($this->service->isRoomAccount('rb_testroom'));
    }

    public function testIsRoomAccountInvalidPrefix(): void {
        $this->assertFalse($this->service->isRoomAccount('normaluser'));
        $this->assertFalse($this->service->isRoomAccount(''));
    }

    public function testIsRoomAccountNonExistent(): void {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $this->assertFalse($this->service->isRoomAccount('rb_nonexistent'));
    }

    public function testExtractUserIdFromPrincipal(): void {
        $this->assertSame('rb_room1', $this->service->extractUserIdFromPrincipal('principals/users/rb_room1'));
        $this->assertSame('admin', $this->service->extractUserIdFromPrincipal('principals/users/admin'));
    }

    public function testExtractUserIdFromPrincipalUnknown(): void {
        $this->assertNull($this->service->extractUserIdFromPrincipal('unknown/format'));
    }

    public function testExtractUserIdFromMailto(): void {
        // Mock getAllRooms via getValueString
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                if ($key === 'rooms_index') {
                    return json_encode(['room1']);
                }
                if ($key === 'room/room1') {
                    return json_encode([
                        'id' => 'room1',
                        'userId' => 'rb_room1',
                        'name' => 'Room 1',
                        'email' => 'room1@company.com',
                        'smtpConfig' => null,
                    ]);
                }
                return $default;
            });

        $this->assertSame('rb_room1', $this->service->extractUserIdFromPrincipal('mailto:room1@company.com'));
        $this->assertNull($this->service->extractUserIdFromPrincipal('mailto:unknown@company.com'));
    }

    public function testBuildRoomLocationFullAddress(): void {
        $room = [
            'name' => 'Conference Room',
            'address' => 'Building A, Heidelberglaan 8, 3584 CS, Utrecht',
            'roomNumber' => '2.17',
        ];

        $result = $this->service->buildRoomLocation($room);
        $this->assertSame('Heidelberglaan 8, 3584 CS Utrecht (Building A, Room 2.17)', $result);
    }

    public function testBuildRoomLocationNoAddress(): void {
        $room = [
            'name' => 'Quick Room',
            'address' => '',
            'roomNumber' => '',
        ];

        $result = $this->service->buildRoomLocation($room);
        $this->assertSame('Quick Room', $result);
    }

    public function testBuildRoomLocationOnlyBuilding(): void {
        $room = [
            'name' => 'Room X',
            'address' => 'Tower B',
            'roomNumber' => '3.01',
        ];

        $result = $this->service->buildRoomLocation($room);
        // Single address part is treated as street, not building
        $this->assertSame('Tower B (Room 3.01)', $result);
    }

    public function testGetRoomByUserId(): void {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                if ($key === 'room/myroom') {
                    return json_encode(['id' => 'myroom', 'userId' => 'rb_myroom', 'name' => 'My Room']);
                }
                return $default;
            });

        $room = $this->service->getRoomByUserId('rb_myroom');
        $this->assertNotNull($room);
        $this->assertSame('myroom', $room['id']);
    }

    public function testGetRoomByUserIdInvalidPrefix(): void {
        $this->assertNull($this->service->getRoomByUserId('normaluser'));
    }
}
