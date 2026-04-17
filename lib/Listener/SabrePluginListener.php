<?php

declare(strict_types=1);

namespace OCA\RoomVox\Listener;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\RoomVox\Dav\RoomVisibilityPlugin;
use OCA\RoomVox\Dav\SchedulingPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Listener to register the SchedulingPlugin with the Sabre DAV server.
 *
 * @template-implements IEventListener<SabrePluginAuthInitEvent>
 */
class SabrePluginListener implements IEventListener {
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof SabrePluginAuthInitEvent)) {
            return;
        }

        try {
            $server = $event->getServer();
            $server->addPlugin($this->container->get(SchedulingPlugin::class));
            $server->addPlugin($this->container->get(RoomVisibilityPlugin::class));

            $this->logger->debug('RoomVox: Sabre plugins registered (scheduling + visibility)');
        } catch (\Exception $e) {
            $this->logger->error('RoomVox: Failed to register Sabre plugins: ' . $e->getMessage());
        }
    }
}
