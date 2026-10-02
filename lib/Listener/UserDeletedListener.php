<?php

declare(strict_types=1);

namespace OCA\RoomVox\Listener;

use OCA\RoomVox\Service\PermissionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * Drop a deleted account's permission entries.
 *
 * Without this the account keeps a Viewer/Booker/Manager entry on every room it
 * was given access to. The entry grants nothing once the account is gone, but it
 * stays listed in the permission editor, where it cannot be told apart from a
 * live account.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
    public function __construct(
        private PermissionService $permissionService,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof UserDeletedEvent)) {
            return;
        }

        $this->permissionService->removeEntriesFor('user', $event->getUser()->getUID());
    }
}
