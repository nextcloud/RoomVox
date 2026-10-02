<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * The administrator's choice about usage statistics, and the question that
 * asks for it.
 *
 * Follows the VoxCloud telemetry rules (design TELEMETRY.md 1.1.0, §2):
 * nothing is sent until an administrator says yes, a missing choice reads as
 * "no", and the question goes to administrators through the notification bell
 * at most once per app version.
 *
 * All state lives in four appconfig keys and nothing is stored per user.
 */
class TelemetryConsentService {
    public const KEY_ENABLED = 'telemetry_enabled';
    public const KEY_CONSENT_SCHEMA = 'telemetry_consent_schema';
    public const KEY_ASKED_VERSION = 'telemetry_asked_version';
    public const KEY_NEVER_ASK = 'telemetry_never_ask';

    public const NOTIFICATION_SUBJECT = 'telemetry_consent';
    public const NOTIFICATION_OBJECT_TYPE = 'telemetry';
    public const NOTIFICATION_OBJECT_ID = 'consent';

    public function __construct(
        private IAppConfig $appConfig,
        private INotificationManager $notificationManager,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether an administrator agreed to send usage statistics.
     *
     * Agreement is the switch being on *and* the field list it covers being
     * recorded, which only give() does. A 'true' without that record is not
     * a choice: up to 1.5.0 the admin pane wrote telemetry_enabled back on
     * every save of the general settings, so an administrator who never saw
     * the question can have one. A missing value means no as well.
     */
    public function isEnabled(): bool {
        return $this->appConfig->getValueString(Application::APP_ID, self::KEY_ENABLED, 'false') === 'true'
            && $this->appConfig->hasKey(Application::APP_ID, self::KEY_CONSENT_SCHEMA, null);
    }

    /**
     * The field-list version the administrator agreed to, 0 for none.
     *
     * Only meaningful while telemetry is on. Never higher than the schema this
     * app version knows.
     */
    public function getConsentedSchema(): int {
        if (!$this->appConfig->hasKey(Application::APP_ID, self::KEY_CONSENT_SCHEMA, null)) {
            return 0;
        }
        $schema = (int)$this->appConfig->getValueString(Application::APP_ID, self::KEY_CONSENT_SCHEMA, '');
        return max(1, min(TelemetryService::SCHEMA, $schema));
    }

    public function isNeverAsk(): bool {
        return $this->appConfig->getValueString(Application::APP_ID, self::KEY_NEVER_ASK, 'false') === 'true';
    }

    /**
     * "Share usage statistics": on, for the field list this version sends.
     * Used by the notification action and by the switch in the admin pane.
     */
    public function give(): void {
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_ENABLED, 'true');
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_CONSENT_SCHEMA, (string)TelemetryService::SCHEMA);
        $this->dismissNotifications();
        $this->logger->info('TelemetryConsentService: usage statistics switched on', ['schema' => TelemetryService::SCHEMA]);
    }

    /**
     * The switch in the admin pane turned off.
     */
    public function withdraw(): void {
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_ENABLED, 'false');
        $this->logger->info('TelemetryConsentService: usage statistics switched off');
    }

    /**
     * "Not now": nothing changes; the question returns with the next version.
     */
    public function postpone(): void {
        $this->dismissNotifications();
    }

    /**
     * "Never ask again": the question never returns. The admin pane stays the
     * place to change one's mind.
     */
    public function neverAsk(): void {
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_NEVER_ASK, 'true');
        $this->dismissNotifications();
    }

    /**
     * Switch off an installation that never made a choice.
     *
     * Earlier versions sent by default, so a missing row did not mean "no"
     * then. A 'true' without a recorded field list is no choice either: the
     * admin pane up to 1.5.0 saved telemetry_enabled with every change to the
     * general settings, so it cannot be told apart from a deliberate yes.
     * Both are written as 'false', and the administrator is asked. A yes
     * given through give() has its field list recorded and stays.
     *
     * @return bool true when the row was written
     */
    public function switchOffUndecided(): bool {
        if ($this->isEnabled()) {
            return false;
        }
        $hasRow = $this->appConfig->hasKey(Application::APP_ID, self::KEY_ENABLED, null);
        if ($hasRow && $this->appConfig->getValueString(Application::APP_ID, self::KEY_ENABLED, 'false') === 'false') {
            return false;
        }
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_ENABLED, 'false');
        return true;
    }

    /**
     * Whether the question is still open: telemetry is off, or on for a
     * shorter field list than this version sends -- and the administrator
     * did not ask never to be asked.
     */
    public function needsAsking(): bool {
        if ($this->isNeverAsk()) {
            return false;
        }
        if (!$this->isEnabled()) {
            return true;
        }
        return $this->getConsentedSchema() < TelemetryService::SCHEMA;
    }

    /**
     * Queue the question for every administrator, once per app version.
     *
     * @return int the number of administrators notified
     */
    public function queueNotificationIfNeeded(string $appVersion): int {
        if (!$this->needsAsking()) {
            return 0;
        }
        if ($this->appConfig->getValueString(Application::APP_ID, self::KEY_ASKED_VERSION, '') === $appVersion) {
            return 0;
        }

        // The question from an earlier version may still be unanswered; the
        // new one replaces it rather than sitting next to it.
        $this->dismissNotifications();

        $notified = 0;
        $admins = $this->groupManager->get('admin');
        foreach ($admins?->getUsers() ?? [] as $user) {
            if (!$user->isEnabled()) {
                continue;
            }
            $notification = $this->createNotification()
                ->setUser($user->getUID())
                ->setDateTime(new \DateTime());
            $this->notificationManager->notify($notification);
            $notified++;
        }

        $this->appConfig->setValueString(Application::APP_ID, self::KEY_ASKED_VERSION, $appVersion);
        return $notified;
    }

    /**
     * Mark the question as answered for every administrator. A notification
     * without a user marks the matching notification of all users.
     */
    public function dismissNotifications(): void {
        $this->notificationManager->markProcessed($this->createNotification());
    }

    private function createNotification(): INotification {
        return $this->notificationManager->createNotification()
            ->setApp(Application::APP_ID)
            ->setObject(self::NOTIFICATION_OBJECT_TYPE, self::NOTIFICATION_OBJECT_ID)
            ->setSubject(self::NOTIFICATION_SUBJECT);
    }
}
