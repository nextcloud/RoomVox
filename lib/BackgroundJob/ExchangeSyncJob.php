<?php

declare(strict_types=1);

namespace OCA\RoomVox\BackgroundJob;

use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class ExchangeSyncJob extends TimedJob {
    private const INTERVAL_MINUTES = 15;

    public function __construct(
        ITimeFactory $time,
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

            try {
                $result = $this->syncService->pullExchangeChanges($room);

                if ($result->hasChanges()) {
                    $this->logger->info(
                        "ExchangeSyncJob: Room {$room['id']}: {$result->created} created, "
                        . "{$result->updated} updated, {$result->deleted} deleted"
                    );
                }

                if (!empty($result->errors)) {
                    $this->logger->warning(
                        "ExchangeSyncJob: Room {$room['id']} sync errors: "
                        . implode('; ', $result->errors)
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    "ExchangeSyncJob: Failed for room {$room['id']}: " . $e->getMessage()
                );
            }
        }
    }
}
