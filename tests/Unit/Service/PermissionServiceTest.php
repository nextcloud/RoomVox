<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PermissionServiceTest extends TestCase {
    private PermissionService $service;
    private IAppConfig $appConfig;
    private IGroupManager $groupManager;

    protected function setUp(): void {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new PermissionService(
            $this->appConfig,
            $this->groupManager,
            $logger,
        );
    }

    public function testGetEffectiveRoleManager(): void {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                if ($key === 'permissions/room1') {
                    return json_encode([
                        'viewers' => [],
                        'bookers' => [],
                        'managers' => [['type' => 'user', 'id' => 'alice']],
                    ]);
                }
                return $default;
            });

        $this->assertSame('manager', $this->service->getEffectiveRole('alice', 'room1'));
        $this->assertSame('none', $this->service->getEffectiveRole('bob', 'room1'));
    }

    public function testGetEffectiveRoleBooker(): void {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                if ($key === 'permissions/room1') {
                    return json_encode([
                        'viewers' => [],
                        'bookers' => [['type' => 'user', 'id' => 'bob']],
                        'managers' => [],
                    ]);
                }
                return $default;
            });

        $this->assertSame('booker', $this->service->getEffectiveRole('bob', 'room1'));
    }

    public function testGetEffectiveRoleViaGroupMembership(): void {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')
            ->willReturnCallback(function (string $userId, string $groupId) {
                return $userId === 'charlie' && $groupId === 'team-a';
            });

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                if ($key === 'permissions/room1') {
                    return json_encode([
                        'viewers' => [],
                        'bookers' => [['type' => 'group', 'id' => 'team-a']],
                        'managers' => [],
                    ]);
                }
                return $default;
            });

        $this->assertSame('booker', $this->service->getEffectiveRole('charlie', 'room1'));
        $this->assertSame('none', $this->service->getEffectiveRole('dave', 'room1'));
    }

    public function testCanViewBookManage(): void {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                if ($key === 'permissions/room1') {
                    return json_encode([
                        'viewers' => [['type' => 'user', 'id' => 'viewer1']],
                        'bookers' => [['type' => 'user', 'id' => 'booker1']],
                        'managers' => [['type' => 'user', 'id' => 'manager1']],
                    ]);
                }
                return $default;
            });

        // Viewer can view, not book, not manage
        $this->assertTrue($this->service->canView('viewer1', 'room1'));
        $this->assertFalse($this->service->canBook('viewer1', 'room1'));
        $this->assertFalse($this->service->canManage('viewer1', 'room1'));

        // Booker can view + book, not manage
        $this->assertTrue($this->service->canView('booker1', 'room1'));
        $this->assertTrue($this->service->canBook('booker1', 'room1'));
        $this->assertFalse($this->service->canManage('booker1', 'room1'));

        // Manager can do everything
        $this->assertTrue($this->service->canView('manager1', 'room1'));
        $this->assertTrue($this->service->canBook('manager1', 'room1'));
        $this->assertTrue($this->service->canManage('manager1', 'room1'));
    }

    public function testAdminBypassesPermissions(): void {
        $this->groupManager->method('isAdmin')
            ->willReturnCallback(fn(string $uid) => $uid === 'admin');

        $this->appConfig->method('getValueString')->willReturn('');

        $this->assertTrue($this->service->canView('admin', 'room1'));
        $this->assertTrue($this->service->canBook('admin', 'room1'));
        $this->assertTrue($this->service->canManage('admin', 'room1'));
    }

    public function testEffectivePermissionsMergesGroupPerms(): void {
        // Set up RoomService with a room that has a group
        $roomService = $this->createMock(RoomService::class);
        $roomService->method('getRoom')
            ->with('room1')
            ->willReturn(['id' => 'room1', 'groupId' => 'group-a']);

        $this->service->setRoomService($roomService);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                if ($key === 'permissions/room1') {
                    return json_encode([
                        'viewers' => [['type' => 'user', 'id' => 'alice']],
                        'bookers' => [],
                        'managers' => [],
                    ]);
                }
                if ($key === 'group_permissions/group-a') {
                    return json_encode([
                        'viewers' => [],
                        'bookers' => [['type' => 'user', 'id' => 'bob']],
                        'managers' => [],
                    ]);
                }
                return $default;
            });

        $effective = $this->service->getEffectivePermissions('room1');
        $this->assertCount(1, $effective['viewers']);
        $this->assertCount(1, $effective['bookers']);
        $this->assertSame('alice', $effective['viewers'][0]['id']);
        $this->assertSame('bob', $effective['bookers'][0]['id']);
    }
}
