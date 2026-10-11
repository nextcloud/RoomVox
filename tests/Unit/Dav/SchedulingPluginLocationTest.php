<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Dav;

use OCA\RoomVox\Dav\SchedulingPlugin;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A LOCATION-only booking (eM Client) is matched to a room by its text.
 * RoomVox writes that text in English, as stored content, so the English
 * form "… (Building A, Room 2.17)" must find the room on any instance, and
 * text that is not this room's must not.
 */
class SchedulingPluginLocationTest extends TestCase {
    private const ROOM = [
        'id' => 'board',
        'userId' => 'rb_board',
        'name' => 'Boardroom',
        'email' => 'board@example.com',
        'address' => 'Building A, Heidelberglaan 8, 3584 CS, Utrecht',
        'roomNumber' => '2.17',
        'active' => true,
    ];

    private function plugin(): SchedulingPlugin {
        // A real RoomService, so both LOCATION forms are built by the
        // production code; only the room list is stubbed.
        $roomService = $this->getMockBuilder(RoomService::class)
            ->setConstructorArgs([
                $this->createMock(IAppConfig::class),
                $this->createMock(ICrypto::class),
                $this->createMock(ISecureRandom::class),
                $this->createMock(LoggerInterface::class),
            ])
            ->onlyMethods(['getAllRooms'])
            ->getMock();
        $roomService->method('getAllRooms')->willReturn(['board' => self::ROOM]);

        return new SchedulingPlugin(
            $roomService,
            $this->createMock(PermissionService::class),
            $this->createMock(CalDAVService::class),
            $this->createMock(MailService::class),
            $this->createMock(ExchangeSyncService::class),
            $this->createMock(IUserManager::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function find(string $location): ?array {
        $plugin = $this->plugin();
        return (new \ReflectionMethod($plugin, 'findRoomByLocation'))->invoke($plugin, $location);
    }

    public function testEnglishLocationMatches(): void {
        $room = $this->find('Heidelberglaan 8, 3584 CS Utrecht (Building A, Room 2.17)');

        $this->assertSame('board', $room['id'] ?? null);
    }

    public function testMatchIgnoresCase(): void {
        $room = $this->find('heidelberglaan 8, 3584 cs utrecht (building a, room 2.17)');

        $this->assertSame('board', $room['id'] ?? null);
    }

    public function testUnrelatedTextDoesNotMatch(): void {
        $this->assertNull($this->find('Heidelberglaan 8, 3584 CS Utrecht (Building B, Room 2.17)'));
        $this->assertNull($this->find('Coffee corner'));
    }
}
