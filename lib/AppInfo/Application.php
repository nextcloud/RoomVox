<?php

declare(strict_types=1);

namespace OCA\ResaVox\AppInfo;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\ResaVox\Connector\Room\RoomBackend;
use OCA\ResaVox\Listener\SabrePluginListener;
use OCA\ResaVox\UserBackend\RoomUserBackend;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserManager;

class Application extends App implements IBootstrap {
    public const APP_ID = 'resavox';

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
    }

    public function boot(IBootContext $context): void {
        $server = $context->getServerContainer();

        // Register the custom user backend for room service accounts
        $userManager = $server->get(IUserManager::class);
        $userManager->registerBackend($server->get(RoomUserBackend::class));
    }
}
