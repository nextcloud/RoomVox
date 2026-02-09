<?php

declare(strict_types=1);

namespace OCA\RoomBooking\UserBackend;

use OCA\RoomBooking\Service\RoomService;
use OC\User\Backend;
use OCP\User\Backend\ICheckPasswordBackend;
use OCP\User\Backend\ICountUsersBackend;
use OCP\User\Backend\IGetDisplayNameBackend;
use Psr\Log\LoggerInterface;

/**
 * Custom user backend for room service accounts.
 *
 * Room accounts are invisible in Nextcloud (never appear in search, cannot login)
 * but exist as CalDAV principals for scheduling purposes.
 */
class RoomUserBackend extends Backend implements ICheckPasswordBackend, IGetDisplayNameBackend, ICountUsersBackend {
    public function __construct(
        private RoomService $roomService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getBackendName(): string {
        return 'RoomBooking';
    }

    /**
     * Room account exists if it's registered in room config
     */
    public function userExists($uid): bool {
        return $this->roomService->isRoomAccount($uid);
    }

    /**
     * NEVER return users in search results — rooms must be invisible
     * @return string[]
     */
    public function getUsers($search = '', $limit = null, $offset = null): array {
        return [];
    }

    /**
     * Login is ALWAYS blocked for room accounts
     */
    public function checkPassword(string $loginName, string $password): string|false {
        return false;
    }

    /**
     * Display name is the room name
     */
    public function getDisplayName($uid): string {
        $room = $this->roomService->getRoomByUserId($uid);
        return $room ? $room['name'] : $uid;
    }

    /**
     * Never return display names in search
     * @return array<string, string>
     */
    public function getDisplayNames($search = '', $limit = null, $offset = null): array {
        return [];
    }

    /**
     * Report 0 users — rooms are not real users
     */
    public function countUsers(): int {
        return 0;
    }

    /**
     * Implement actions bitmap
     * We support: CHECK_PASSWORD (to block it) and GET_DISPLAYNAME
     */
    public function implementsActions($actions): bool {
        return (bool)((
            Backend::CHECK_PASSWORD |
            Backend::GET_DISPLAYNAME |
            Backend::COUNT_USERS
        ) & $actions);
    }
}
