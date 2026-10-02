<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Permissions store bare user and group ids, so a deleted principal leaves an
 * entry that can never match again. It grants nothing, but it stays listed in
 * the permission editor, and a stale group silently resolves to no managers at
 * all — which is how approval requests end up reaching nobody.
 */
class PermissionCleanupTest extends TestCase {
    private PermissionService $service;
    private IAppConfig $appConfig;
    private LoggerInterface $logger;

    /** @var array<string, string> The permission keys the service has written */
    private array $written = [];

    protected function setUp(): void {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new PermissionService(
            $this->appConfig,
            $this->createMock(IGroupManager::class),
            $this->logger,
        );
    }

    /**
     * Back the service with stored permissions and capture what it writes back.
     * Reads see earlier writes, as IAppConfig does.
     *
     * @param array<string, array> $stored key → permission structure
     * @param array<int, array> $rooms Rooms known to RoomService (id, groupId)
     */
    private function withStoredPermissions(array $stored, array $rooms = [['id' => 'room1']]): void {
        $encoded = array_map(static fn ($perms) => json_encode($perms), $stored);

        $this->appConfig->method('getAllValues')
            ->willReturnCallback(function (string $app, string $prefix) use ($encoded) {
                return array_filter(
                    array_merge($encoded, $this->written),
                    static fn ($key) => str_starts_with($key, $prefix),
                    ARRAY_FILTER_USE_KEY
                );
            });

        $roomService = $this->createMock(RoomService::class);
        $roomService->method('getAllRooms')->willReturn($rooms);
        $this->service->setRoomService($roomService);

        $this->appConfig->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) {
                $this->written[$key] = $value;
                return true;
            });
    }

    private function writtenEntries(string $key, string $role): array {
        $this->assertArrayHasKey($key, $this->written, "Expected $key to be rewritten");
        return json_decode($this->written[$key], true)[$role];
    }

    public function testDeletedUserIsRemovedFromEveryRole(): void {
        $this->withStoredPermissions([
            'permissions/room1' => [
                'viewers' => [['type' => 'user', 'id' => 'alice']],
                'bookers' => [['type' => 'user', 'id' => 'alice'], ['type' => 'user', 'id' => 'bob']],
                'managers' => [['type' => 'user', 'id' => 'carol']],
            ],
        ]);

        $removed = $this->service->removeEntriesFor('user', 'alice');

        $this->assertSame(2, $removed);
        $this->assertSame([], $this->writtenEntries('permissions/room1', 'viewers'));
        $this->assertSame(
            [['type' => 'user', 'id' => 'bob']],
            $this->writtenEntries('permissions/room1', 'bookers')
        );
    }

    /**
     * A user and a group may share an id; only the matching type is removed.
     */
    public function testUserAndGroupWithTheSameIdAreDistinguished(): void {
        $this->withStoredPermissions([
            'permissions/room1' => [
                'viewers' => [],
                'bookers' => [],
                'managers' => [
                    ['type' => 'user', 'id' => 'staff'],
                    ['type' => 'group', 'id' => 'staff'],
                ],
            ],
        ]);

        $removed = $this->service->removeEntriesFor('group', 'staff');

        $this->assertSame(1, $removed);
        $this->assertSame(
            [['type' => 'user', 'id' => 'staff']],
            $this->writtenEntries('permissions/room1', 'managers')
        );
    }

    /**
     * Room-group permissions are stored under their own prefix and must be swept
     * as well, or a deleted principal keeps inherited access.
     */
    public function testRoomGroupPermissionsAreSweptToo(): void {
        $this->withStoredPermissions([
            'group_permissions/floor-3' => [
                'viewers' => [['type' => 'group', 'id' => 'temps']],
                'bookers' => [],
                'managers' => [],
            ],
        ]);

        $removed = $this->service->removeEntriesFor('group', 'temps');

        $this->assertSame(1, $removed);
        $this->assertSame([], $this->writtenEntries('group_permissions/floor-3', 'viewers'));
    }

    /**
     * Losing the last manager leaves a room with nobody to approve its requests.
     * That is a legitimate outcome of deleting the account, but it must not
     * happen silently.
     */
    public function testLosingTheLastManagerIsLogged(): void {
        $this->withStoredPermissions([
            'permissions/room1' => [
                'viewers' => [],
                'bookers' => [],
                'managers' => [['type' => 'user', 'id' => 'carol']],
            ],
        ]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('no managers left'));

        $this->service->removeEntriesFor('user', 'carol');
    }

    /**
     * A room that still has another manager is an ordinary removal.
     */
    public function testRemovingOneOfSeveralManagersIsNotWarnedAbout(): void {
        $this->withStoredPermissions([
            'permissions/room1' => [
                'viewers' => [],
                'bookers' => [],
                'managers' => [
                    ['type' => 'user', 'id' => 'carol'],
                    ['type' => 'user', 'id' => 'dave'],
                ],
            ],
        ]);

        $this->logger->expects($this->never())->method('warning');

        $this->service->removeEntriesFor('user', 'carol');
    }

    /**
     * The room's own entry loses its only manager, but its room group still
     * names one: someone can still approve, so there is nothing to warn about.
     */
    public function testRoomWhoseGroupStillHasAManagerIsNotWarnedAbout(): void {
        $this->withStoredPermissions([
            'permissions/room1' => [
                'viewers' => [],
                'bookers' => [],
                'managers' => [['type' => 'user', 'id' => 'carol']],
            ],
            'group_permissions/floor-3' => [
                'viewers' => [],
                'bookers' => [],
                'managers' => [['type' => 'group', 'id' => 'facilities']],
            ],
        ], [['id' => 'room1', 'groupId' => 'floor-3']]);

        $this->logger->expects($this->never())->method('warning');

        $this->service->removeEntriesFor('user', 'carol');
    }

    /**
     * Emptying a room group's managers only matters for the rooms in it that
     * have no manager of their own.
     */
    public function testEmptiedGroupWarnsOnlyForRoomsWithoutTheirOwnManager(): void {
        $this->withStoredPermissions([
            'group_permissions/floor-3' => [
                'viewers' => [],
                'bookers' => [],
                'managers' => [['type' => 'group', 'id' => 'facilities']],
            ],
            'permissions/room1' => [
                'viewers' => [],
                'bookers' => [],
                'managers' => [['type' => 'user', 'id' => 'dave']],
            ],
        ], [
            ['id' => 'room1', 'groupId' => 'floor-3'],
            ['id' => 'room2', 'groupId' => 'floor-3'],
            ['id' => 'room3', 'groupId' => 'other'],
        ]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('room "room2" has no managers left'));

        $this->service->removeEntriesFor('group', 'facilities');
    }

    /**
     * Rooms the principal never had access to must not be rewritten.
     */
    public function testUnrelatedRoomsAreLeftUntouched(): void {
        $this->withStoredPermissions([
            'permissions/room1' => [
                'viewers' => [['type' => 'user', 'id' => 'bob']],
                'bookers' => [],
                'managers' => [],
            ],
        ]);

        $removed = $this->service->removeEntriesFor('user', 'alice');

        $this->assertSame(0, $removed);
        $this->assertSame([], $this->written);
    }
}
