<?php

declare(strict_types=1);

namespace OCA\RoomVox\BackgroundJob;

use OCA\RoomVox\Service\TelemetryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Background job that periodically sends anonymous telemetry data.
 * This is opt-out and runs when telemetry is enabled (default).
 * Runs every 24 hours with random jitter to spread load.
 */
class TelemetryJob extends TimedJob {
    private const DEFAULT_INTERVAL_HOURS = 24;
    private const JITTER_MAX_MINUTES = 120;

    public function __construct(
        ITimeFactory $time,
        private TelemetryService $telemetryService,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);

        $intervalHours = self::DEFAULT_INTERVAL_HOURS;
        $jitterMinutes = $this->getStableJitter();

        $this->setInterval(($intervalHours * 60 * 60) + ($jitterMinutes * 60));
    }

    /**
     * Get a stable jitter value unique to this installation.
     * Uses a hash of the instance ID to generate consistent random delay.
     */
    private function getStableJitter(): int {
        $instanceId = $this->config->getSystemValue('instanceid', '');
        if (empty($instanceId)) {
            return random_int(0, self::JITTER_MAX_MINUTES);
        }

        $hash = crc32($instanceId . 'roomvox_telemetry_jitter_v1');
        return abs($hash) % (self::JITTER_MAX_MINUTES + 1);
    }

    /**
     * Run the background job.
     * @param mixed $argument Not used
     */
    protected function run($argument): void {
        if (!$this->telemetryService->isEnabled()) {
            $this->logger->debug('TelemetryJob: Telemetry is disabled, skipping');
            return;
        }

        $this->logger->info('TelemetryJob: Starting telemetry report');

        try {
            $success = $this->telemetryService->sendReport();

            if ($success) {
                $this->logger->info('TelemetryJob: Telemetry report sent successfully');
            } else {
                $this->logger->warning('TelemetryJob: Telemetry report failed');
            }
        } catch (\Exception $e) {
            $this->logger->warning('TelemetryJob: Exception during telemetry send', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
