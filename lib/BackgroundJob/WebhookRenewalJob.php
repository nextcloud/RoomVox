<?php

declare(strict_types=1);

namespace OCA\RoomVox\BackgroundJob;

use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\Exchange\WebhookService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs every 24 hours to renew webhook subscriptions and create
 * missing subscriptions for Exchange-enabled rooms.
 */
class WebhookRenewalJob extends TimedJob {
    private const INTERVAL_MINUTES = 1440;

    public function __construct(
        ITimeFactory $time,
        private WebhookService $webhookService,
        private ExchangeSyncService $syncService,
        private RoomService $roomService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL_MINUTES * 60);
    }

    protected function run($argument): void {
        if (!$this->syncService->isGloballyEnabled()) {
            return;
        }

        $rooms = $this->roomService->getAllRooms();

        foreach ($rooms as $room) {
            if (!$this->syncService->isExchangeRoom($room)) {
                continue;
            }

            $subscriptionId = $room['exchangeConfig']['webhookSubscriptionId'] ?? null;

            try {
                if ($subscriptionId === null) {
                    $this->webhookService->createSubscription($room);
                } elseif ($this->webhookService->needsRenewal($room)) {
                    $this->webhookService->renewSubscription($room);
                }
            } catch (\Throwable $e) {
                $this->logger->error("WebhookRenewalJob: Failed for room {$room['id']}: " . $e->getMessage());
            }
        }
    }
}
