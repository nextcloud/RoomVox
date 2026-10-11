<?php

declare(strict_types=1);

namespace OCA\RoomVox\Notification;

use OCA\RoomVox\AppInfo\Application;
use OCA\RoomVox\Service\TelemetryConsentService;
use OCA\RoomVox\Service\TelemetryService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders the usage-statistics question in the notification bell.
 *
 * The text names what is sent from the same field definition the report and
 * the admin pane use, links to the full list with the purpose of each field,
 * and offers three actions of equal weight (TELEMETRY.md §2).
 */
class Notifier implements INotifier {
    /** Where the statistics go; a host name, passed into the text as a parameter */
    private const LICENSE_SERVER = 'licenses.voxcloud.nl';

    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
        private TelemetryService $telemetryService,
        private TelemetryConsentService $consent,
    ) {
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        // TRANSLATORS Product name; do not translate
        return $this->l10nFactory->get(Application::APP_ID)->t('RoomVox');
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== Application::APP_ID
            || $notification->getSubject() !== TelemetryConsentService::NOTIFICATION_SUBJECT) {
            throw new UnknownNotificationException();
        }

        // Answered in the meantime, from the pane or by another administrator.
        if (!$this->consent->needsAsking()) {
            throw new AlreadyProcessedException();
        }

        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $fields = $this->telemetryService->getFieldDefinitions($l);

        if ($this->consent->isEnabled()) {
            // On for an older list: name only what was added.
            $consented = $this->consent->getConsentedSchema();
            $added = array_filter($fields, fn (array $field) => $field['schema'] > $consented);
            $notification->setParsedSubject($l->t('RoomVox would like to send more usage statistics'));
            $notification->setParsedMessage($l->t(
                // TRANSLATORS Notification text; %1$s is a list of usage statistics fields, %2$s a host name (licenses.voxcloud.nl). VoxCloud is a company name
                'Added since you agreed: %1$s. These figures stay on this server until you agree. Everything you agreed to before is still sent to %2$s, run by VoxCloud. No personal data, content or names are sent.',
                [implode(', ', array_column($added, 'label')), self::LICENSE_SERVER]
            ));
        } else {
            // TRANSLATORS Notification title. VoxCloud is the company that makes RoomVox; keep both names
            $notification->setParsedSubject($l->t('Share RoomVox usage statistics with VoxCloud?'));
            $notification->setParsedMessage($l->t(
                // TRANSLATORS Notification text; %1$s is a host name (licenses.voxcloud.nl), %2$s a list of usage statistics fields. VoxCloud is a company name
                'Once a day, RoomVox would send these figures about this server to %1$s, run by VoxCloud: %2$s. No personal data, content or names are sent. The RoomVox administration settings say what each figure is used for.',
                [self::LICENSE_SERVER, implode(', ', array_column($fields, 'label'))]
            ));
        }

        $notification->setLink(
            $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => Application::APP_ID]) . '#usage-statistics'
        );
        $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));

        // Three actions of the same weight: none is primary.
        $actions = [
            // TRANSLATORS Button and switch label: agree to send usage statistics
            [$l->t('Share usage statistics'), 'roomvox.telemetry_consent.share', IAction::TYPE_POST],
            // TRANSLATORS Button in a notification: ask again later
            [$l->t('Not now'), 'roomvox.telemetry_consent.postpone', IAction::TYPE_POST],
            // TRANSLATORS Button in a notification: stop asking
            [$l->t('Never ask again'), 'roomvox.telemetry_consent.never_ask', IAction::TYPE_POST],
        ];
        foreach ($actions as [$label, $route, $method]) {
            $action = $notification->createAction();
            $action->setParsedLabel($label)
                ->setLink($this->urlGenerator->linkToOCSRouteAbsolute($route), $method)
                ->setPrimary(false);
            $notification->addParsedAction($action);
        }

        return $notification;
    }
}
