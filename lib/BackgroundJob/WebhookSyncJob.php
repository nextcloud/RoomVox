<?php

declare(strict_types=1);

namespace OCA\RoomVox\BackgroundJob;

use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One-shot job triggered by a webhook notification.
 * Runs pullExchangeChanges() for a single room.
 */
class WebhookSyncJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private ExchangeSyncService $syncService,
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

        try {
            $result = $this->syncService->pullExchangeChanges($room);

            if ($result->hasChanges()) {
                $this->logger->info(
                    "WebhookSyncJob: Room {$roomId}: {$result->created} created, "
                    . "{$result->updated} updated, {$result->deleted} deleted"
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error("WebhookSyncJob: Failed for room {$roomId}: " . $e->getMessage());
        }
    }
}
