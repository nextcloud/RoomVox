<?php

declare(strict_types=1);

namespace OCA\RoomVox\Exception;

/**
 * Thrown when a room would be given an email address that another room already
 * uses.
 *
 * The address is a room's scheduling identity: iMIP invitations, accept/decline
 * replies and the Exchange resource-mailbox link are all keyed on it. Two rooms
 * sharing one address makes the routing of those messages ambiguous, so the
 * duplicate is refused rather than stored.
 */
class EmailAlreadyUsedException extends \RuntimeException {
    public function __construct(
        private string $email,
        private string $conflictingRoomId,
    ) {
        parent::__construct(
            sprintf('Email address "%s" is already used by room "%s"', $email, $conflictingRoomId)
        );
    }

    public function getEmail(): string {
        return $this->email;
    }

    /**
     * The room that already holds the address.
     */
    public function getConflictingRoomId(): string {
        return $this->conflictingRoomId;
    }
}
