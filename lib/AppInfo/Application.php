<?php

declare(strict_types=1);

namespace OCA\RoomVox\AppInfo;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\RoomVox\Connector\Room\RoomBackend;
use OCA\RoomVox\Listener\GroupDeletedListener;
use OCA\RoomVox\Listener\SabrePluginListener;
use OCA\RoomVox\Listener\UserDeletedListener;
use OCA\RoomVox\Middleware\ApiTokenMiddleware;
use OCA\RoomVox\Notification\Notifier;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCA\RoomVox\UserBackend\RoomUserBackend;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IUserManager;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'roomvox';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register the CalDAV Room Backend so rooms appear as bookable resources
        $context->registerCalendarRoomBackend(RoomBackend::class);

        // Register the Sabre plugin listener for scheduling (iTIP handling)
        $context->registerEventListener(
            SabrePluginAuthInitEvent::class,
            SabrePluginListener::class
        );

        // Clear permission entries when the account or group they name is deleted
        $context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
        $context->registerEventListener(GroupDeletedEvent::class, GroupDeletedListener::class);

        // Register API token middleware for public API authentication
        $context->registerMiddleware(ApiTokenMiddleware::class);

        // Renders the usage-statistics question in the notification bell
        $context->registerNotifierService(Notifier::class);
    }

    public function boot(IBootContext $context): void {
        $server = $context->getServerContainer();

        // Register the custom user backend for room service accounts
        $userManager = $server->get(IUserManager::class);
        $userManager->registerBackend($server->get(RoomUserBackend::class));

        // Wire up late injection to avoid circular dependency
        $permissionService = $server->get(PermissionService::class);
        $permissionService->setRoomService($server->get(RoomService::class));

        // CalDAV service needs room lookups to tell apart the attendee lines of
        // two rooms booked on the same event (issue #22)
        $server->get(CalDAVService::class)->setRoomService($server->get(RoomService::class));

        // Room calendars are created by Nextcloud's room manager (issue #44 follow-up)
        $server->get(CalDAVService::class)->setRoomManager($server->get(IRoomManager::class));

        // Wire Exchange sync service into CalDAV service for conflict checking
        try {
            $exchangeSyncService = $server->get(ExchangeSyncService::class);
            $calDAVService = $server->get(CalDAVService::class);
            $calDAVService->setExchangeSyncService($exchangeSyncService);
        } catch (\Throwable $e) {
            // Exchange service not available — features gracefully degrade
        }
    }
}
