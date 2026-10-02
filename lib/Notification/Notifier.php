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
                'Added since you agreed: %s. These figures stay on this server until you agree. Everything you agreed to before is still sent to licenses.voxcloud.nl, run by VoxCloud. No personal data, content or names are sent.',
                [implode(', ', array_column($added, 'label'))]
            ));
        } else {
            $notification->setParsedSubject($l->t('Share RoomVox usage statistics with VoxCloud?'));
            $notification->setParsedMessage($l->t(
                'Once a day, RoomVox would send these figures about this server to licenses.voxcloud.nl, run by VoxCloud: %s. No personal data, content or names are sent. The RoomVox admin settings say what each figure is used for.',
                [implode(', ', array_column($fields, 'label'))]
            ));
        }

        $notification->setLink(
            $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => Application::APP_ID]) . '#usage-statistics'
        );
        $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));

        // Three actions of the same weight: none is primary.
        $actions = [
            [$l->t('Share usage statistics'), 'roomvox.telemetry_consent.share', IAction::TYPE_POST],
            [$l->t('Not now'), 'roomvox.telemetry_consent.postpone', IAction::TYPE_POST],
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
