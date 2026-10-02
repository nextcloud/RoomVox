<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Listener;

use OCA\RoomVox\Listener\GroupDeletedListener;
use OCA\RoomVox\Listener\UserDeletedListener;
use OCA\RoomVox\Service\PermissionService;
use OCP\EventDispatcher\Event;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IGroup;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

class DeletedPrincipalListenersTest extends TestCase {
    public function testUserListenerRemovesThatUsersEntries(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->expects($this->once())
            ->method('removeEntriesFor')
            ->with('user', 'alice');

        (new UserDeletedListener($permissionService))->handle(new UserDeletedEvent($user));
    }

    public function testGroupListenerRemovesThatGroupsEntries(): void {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn('temps');

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->expects($this->once())
            ->method('removeEntriesFor')
            ->with('group', 'temps');

        (new GroupDeletedListener($permissionService))->handle(new GroupDeletedEvent($group));
    }

    /**
     * Listeners are registered per event, but each still guards its own type so
     * an unrelated event cannot trigger a sweep.
     */
    public function testUnrelatedEventIsIgnored(): void {
        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->expects($this->never())->method('removeEntriesFor');

        $unrelated = new class extends Event {};

        (new UserDeletedListener($permissionService))->handle($unrelated);
        (new GroupDeletedListener($permissionService))->handle($unrelated);
    }
}
