<?php

declare(strict_types=1);

namespace OCA\RoomVox\Migration;

use OCA\RoomVox\AppInfo\Application;
use OCA\RoomVox\Service\TelemetryConsentService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Settle the usage-statistics choice after an install or upgrade.
 *
 * 1. An installation that never chose is switched off. Earlier versions sent
 *    by default; from this version on nothing is sent until an administrator
 *    agrees. An explicit 'true' stays.
 * 2. While the question is open, it is queued in the notification bell of
 *    every administrator -- once per app version, the way Nextcloud's own
 *    survey_client asks. Never after "Never ask again".
 *
 * Safe to run more than once.
 */
class QueueTelemetryConsent implements IRepairStep {
    public function __construct(
        private TelemetryConsentService $consent,
        private IAppManager $appManager,
    ) {
    }

    public function getName(): string {
        return 'Ask administrators about RoomVox usage statistics';
    }

    public function run(IOutput $output): void {
        if ($this->consent->switchOffUndecided()) {
            $output->info('Usage statistics are off until an administrator agrees');
        }

        // installed_version is written only after the repair steps, so the
        // version comes from info.xml.
        $version = $this->appManager->getAppVersion(Application::APP_ID, false);
        $notified = $this->consent->queueNotificationIfNeeded($version);
        if ($notified > 0) {
            $output->info("Asked {$notified} administrators about usage statistics");
        }
    }
}
