<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Notification;

use OCA\RoomVox\Notification\Notifier;
use OCA\RoomVox\Service\TelemetryConsentService;
use OCA\RoomVox\Service\TelemetryService;
use OCA\RoomVox\Tests\Unit\Support\InMemoryAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IAction;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The usage-statistics question in the notification bell (TELEMETRY.md §2).
 */
class NotifierTest extends TestCase {
    use InMemoryAppConfig;

    /** @var array<int, array{label: string, link: string, method: string, primary: bool}> */
    private array $actions = [];
    private string $subject = '';
    private string $message = '';
    private string $link = '';

    protected function setUp(): void {
        $this->appValues = [];
        $this->actions = [];
    }

    private function notifier(): Notifier {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $text, $params = []) => vsprintf($text, (array)$params));
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l);

        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRouteAbsolute')->willReturnCallback(
            fn (string $route, array $params = []) => 'https://cloud.example/' . $route . '/' . implode('/', $params)
        );
        $url->method('linkToOCSRouteAbsolute')->willReturnCallback(fn (string $route) => 'https://cloud.example/ocs/' . $route);
        $url->method('imagePath')->willReturn('/apps/roomvox/img/app-dark.svg');
        $url->method('getAbsoluteURL')->willReturnArgument(0);

        $telemetry = $this->createMock(TelemetryService::class);
        $telemetry->method('getFieldDefinitions')->willReturn([
            'totalUsers' => ['label' => 'Number of user accounts', 'purpose' => 'Licence sizing', 'schema' => 1],
            'totalRooms' => ['label' => 'Number of rooms', 'purpose' => 'Usage', 'schema' => 1],
        ]);

        $notifications = $this->createMock(INotificationManager::class);
        $consent = new TelemetryConsentService(
            $this->createInMemoryAppConfig(),
            $notifications,
            $this->createMock(IGroupManager::class),
            $this->createMock(LoggerInterface::class),
        );

        return new Notifier($factory, $url, $telemetry, $consent);
    }

    private function notification(string $app = 'roomvox', string $subject = 'telemetry_consent'): INotification {
        $n = $this->createMock(INotification::class);
        $n->method('getApp')->willReturn($app);
        $n->method('getSubject')->willReturn($subject);
        $n->method('setParsedSubject')->willReturnCallback(function (string $s) use ($n) {
            $this->subject = $s;
            return $n;
        });
        $n->method('setParsedMessage')->willReturnCallback(function (string $m) use ($n) {
            $this->message = $m;
            return $n;
        });
        $n->method('setLink')->willReturnCallback(function (string $link) use ($n) {
            $this->link = $link;
            return $n;
        });
        $n->method('setIcon')->willReturnSelf();
        $records = new \SplObjectStorage();
        $n->method('createAction')->willReturnCallback(function () use ($records) {
            $record = new \ArrayObject(['label' => '', 'link' => '', 'method' => '', 'primary' => true]);
            $action = $this->createMock(IAction::class);
            $action->method('setParsedLabel')->willReturnCallback(function (string $label) use ($record, $action) {
                $record['label'] = $label;
                return $action;
            });
            $action->method('setLink')->willReturnCallback(function (string $link, string $method) use ($record, $action) {
                $record['link'] = $link;
                $record['method'] = $method;
                return $action;
            });
            $action->method('setPrimary')->willReturnCallback(function (bool $primary) use ($record, $action) {
                $record['primary'] = $primary;
                return $action;
            });
            $records[$action] = $record;
            return $action;
        });
        $n->method('addParsedAction')->willReturnCallback(function (IAction $action) use ($n, $records) {
            $this->actions[] = $records[$action]->getArrayCopy();
            return $n;
        });
        return $n;
    }

    public function testPreparesThreeActionsOfEqualWeight(): void {
        $this->notifier()->prepare($this->notification(), 'en');

        $this->assertSame(
            ['Share usage statistics', 'Not now', 'Never ask again'],
            array_column($this->actions, 'label')
        );
        $this->assertSame([false, false, false], array_column($this->actions, 'primary'));
        $this->assertSame(['POST', 'POST', 'POST'], array_column($this->actions, 'method'));
        $this->assertSame([
            'https://cloud.example/ocs/roomvox.telemetry_consent.share',
            'https://cloud.example/ocs/roomvox.telemetry_consent.postpone',
            'https://cloud.example/ocs/roomvox.telemetry_consent.never_ask',
        ], array_column($this->actions, 'link'));
    }

    public function testTextNamesWhatIsSentAndWhereItGoes(): void {
        $this->notifier()->prepare($this->notification(), 'en');

        $this->assertStringContainsString('Number of user accounts', $this->message);
        $this->assertStringContainsString('Number of rooms', $this->message);
        $this->assertStringContainsString('licenses.voxcloud.nl', $this->message);
        $this->assertStringContainsString('VoxCloud', $this->message);
        $this->assertStringNotContainsStringIgnoringCase('anonym', $this->subject . $this->message);
    }

    public function testLinksToTheFieldListInTheAdminPane(): void {
        $this->notifier()->prepare($this->notification(), 'en');
        $this->assertSame('https://cloud.example/settings.AdminSettings.index/roomvox#usage-statistics', $this->link);
    }

    public function testThrowsForAnUnknownSubject(): void {
        $this->expectException(UnknownNotificationException::class);
        $this->notifier()->prepare($this->notification('roomvox', 'something_else'), 'en');
    }

    public function testThrowsForAnotherApp(): void {
        $this->expectException(UnknownNotificationException::class);
        $this->notifier()->prepare($this->notification('files', 'telemetry_consent'), 'en');
    }

    public function testAnsweredQuestionIsRemoved(): void {
        $this->appValues['telemetry_enabled'] = 'true';
        $this->appValues['telemetry_consent_schema'] = '1';

        $this->expectException(AlreadyProcessedException::class);
        $this->notifier()->prepare($this->notification(), 'en');
    }

    public function testNeverAskRemovesTheQuestion(): void {
        $this->appValues['telemetry_never_ask'] = 'true';

        $this->expectException(AlreadyProcessedException::class);
        $this->notifier()->prepare($this->notification(), 'en');
    }
}
