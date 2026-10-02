<?php

declare(strict_types=1);

namespace OCA\RoomVox\Listener;

use OCA\RoomVox\Service\PermissionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;

/**
 * Drop a deleted group's permission entries.
 *
 * A group entry that no backend can resolve is worse than a stale user entry:
 * the room keeps listing a group of managers while resolving it yields nobody,
 * so approval requests quietly reach no one. Version 1.5.0 started logging that
 * case; removing the entry when the group is deleted addresses the cause for
 * groups that are genuinely gone.
 *
 * A group that merely fails to resolve — an LDAP backend that is unreachable or
 * not fully configured — does not raise this event, so its permissions are left
 * untouched and come back with the backend.
 *
 * @template-implements IEventListener<GroupDeletedEvent>
 */
class GroupDeletedListener implements IEventListener {
    public function __construct(
        private PermissionService $permissionService,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof GroupDeletedEvent)) {
            return;
        }

        $this->permissionService->removeEntriesFor('group', $event->getGroup()->getGID());
    }
}
