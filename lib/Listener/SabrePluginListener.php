<?php

declare(strict_types=1);

namespace OCA\ResaVox\Listener;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\ResaVox\Dav\SchedulingPlugin;
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
            $plugin = $this->container->get(SchedulingPlugin::class);
            $server->addPlugin($plugin);

            $this->logger->debug('ResaVox: SchedulingPlugin registered with Sabre DAV server');
        } catch (\Exception $e) {
            $this->logger->error('ResaVox: Failed to register SchedulingPlugin: ' . $e->getMessage());
        }
    }
}
