<?php

declare(strict_types=1);

namespace OCA\RoomVox\AppInfo;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\RoomVox\Connector\Room\RoomBackend;
use OCA\RoomVox\Listener\SabrePluginListener;
use OCA\RoomVox\Middleware\ApiTokenMiddleware;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCA\RoomVox\UserBackend\RoomUserBackend;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserManager;

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

        // Register API token middleware for public API authentication
        $context->registerMiddleware(ApiTokenMiddleware::class);
    }

    public function boot(IBootContext $context): void {
        $server = $context->getServerContainer();

        // Register the custom user backend for room service accounts
        $userManager = $server->get(IUserManager::class);
        $userManager->registerBackend($server->get(RoomUserBackend::class));

        // Wire up late injection to avoid circular dependency
        $permissionService = $server->get(PermissionService::class);
        $permissionService->setRoomService($server->get(RoomService::class));
    }
}
