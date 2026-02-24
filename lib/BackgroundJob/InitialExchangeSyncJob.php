<?php

declare(strict_types=1);

namespace OCA\RoomVox\BackgroundJob;

use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\Exchange\WebhookService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One-shot job triggered when a room is linked to Exchange.
 * Runs fullSync() to import all existing Exchange events,
 * then creates a webhook subscription for real-time updates.
 */
class InitialExchangeSyncJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private ExchangeSyncService $syncService,
        private WebhookService $webhookService,
        private RoomService $roomService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void {
        $roomId = $argument['roomId'] ?? '';
        if ($roomId === '') {
            return;
        }

        $room = $this->roomService->getRoom($roomId);
        if ($room === null || !$this->syncService->isExchangeRoom($room)) {
            return;
        }

        $this->roomService->updateExchangeInitialSyncStatus($roomId, 'syncing', null);

        try {
            $result = $this->syncService->fullSync($room);

            $this->roomService->updateExchangeInitialSyncStatus($roomId, 'completed', null);

            $this->logger->info(
                "InitialExchangeSyncJob: Room {$roomId}: {$result->created} created, "
                . "{$result->updated} updated, {$result->deleted} deleted"
            );

            // Create webhook subscription for real-time updates
            $freshRoom = $this->roomService->getRoom($roomId);
            if ($freshRoom !== null) {
                $this->webhookService->createSubscription($freshRoom);
            }
        } catch (\Throwable $e) {
            $this->roomService->updateExchangeInitialSyncStatus($roomId, 'failed', $e->getMessage());
            $this->logger->error("InitialExchangeSyncJob: Failed for room {$roomId}: " . $e->getMessage());
        }
    }
}
