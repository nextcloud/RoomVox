<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Migration\QueueTelemetryConsent;
use OCA\RoomVox\Service\TelemetryConsentService;
use OCA\RoomVox\Tests\Unit\Support\InMemoryAppConfig;
use OCP\App\IAppManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The administrator's choice, and the repair step that asks for it
 * (design TELEMETRY.md 1.1.0, §2).
 */
class TelemetryConsentServiceTest extends TestCase {
    use InMemoryAppConfig;

    private INotificationManager $notifications;
    private IGroupManager $groupManager;
    /** @var string[] users notified, in order */
    private array $notified = [];
    private int $processed = 0;

    protected function setUp(): void {
        $this->appValues = [];
        $this->notified = [];
        $this->processed = 0;

        $this->notifications = $this->createMock(INotificationManager::class);
        $this->notifications->method('createNotification')->willReturnCallback(function () {
            $user = '';
            $n = $this->createMock(INotification::class);
            $n->method('setUser')->willReturnCallback(function (string $uid) use (&$user, $n) {
                $user = $uid;
                return $n;
            });
            $n->method('getUser')->willReturnCallback(function () use (&$user) {
                return $user;
            });
            foreach (['setApp', 'setObject', 'setSubject', 'setDateTime'] as $setter) {
                $n->method($setter)->willReturnSelf();
            }
            return $n;
        });
        $this->notifications->method('notify')->willReturnCallback(function (INotification $n) {
            $this->notified[] = $n->getUser();
        });
        $this->notifications->method('markProcessed')->willReturnCallback(function () {
            $this->processed++;
        });

        $admins = [];
        foreach (['alice' => true, 'bob' => true, 'carol' => false] as $uid => $enabled) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('isEnabled')->willReturn($enabled);
            $admins[] = $user;
        }
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn($admins);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->groupManager->method('get')->willReturnCallback(fn (string $gid) => $gid === 'admin' ? $group : null);
    }

    private function consent(): TelemetryConsentService {
        return new TelemetryConsentService(
            $this->createInMemoryAppConfig(),
            $this->notifications,
            $this->groupManager,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function runRepairStep(string $version = '1.6.0'): void {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppVersion')->willReturn($version);
        (new QueueTelemetryConsent($this->consent(), $appManager))->run($this->createMock(IOutput::class));
    }

    // ── Reading the choice ────────────────────────────────────────

    public function testMissingRowReadsAsOff(): void {
        $this->assertFalse($this->consent()->isEnabled());
    }

    public function testARecordedYesReadsAsOn(): void {
        $this->appValues['telemetry_enabled'] = 'true';
        $this->appValues['telemetry_consent_schema'] = '1';
        $this->assertTrue($this->consent()->isEnabled());
        $this->assertSame(1, $this->consent()->getConsentedSchema());
    }

    /**
     * Up to 1.5.0 every save of the general settings wrote 'true' back, so a
     * 'true' without a recorded field list is not a choice.
     */
    public function testATrueWithoutARecordedFieldListReadsAsOff(): void {
        $this->appValues['telemetry_enabled'] = 'true';
        $this->assertFalse($this->consent()->isEnabled());
        $this->assertSame(0, $this->consent()->getConsentedSchema());
        $this->assertTrue($this->consent()->needsAsking());
    }

    // ── The repair step ───────────────────────────────────────────

    public function testRepairSwitchesOffAnInstallationThatNeverChose(): void {
        $this->runRepairStep();
        $this->assertSame('false', $this->appValues['telemetry_enabled']);
    }

    public function testRepairKeepsARecordedYes(): void {
        $this->appValues['telemetry_enabled'] = 'true';
        $this->appValues['telemetry_consent_schema'] = '1';
        $this->runRepairStep();

        $this->assertSame('true', $this->appValues['telemetry_enabled']);
        $this->assertSame([], $this->notified, 'an administrator who said yes is not asked again');
    }

    public function testRepairSwitchesOffAYesWithoutARecordedFieldListAndAsks(): void {
        $this->appValues['telemetry_enabled'] = 'true';
        $this->runRepairStep('1.6.0');

        $this->assertSame('false', $this->appValues['telemetry_enabled']);
        $this->assertSame(['alice', 'bob'], $this->notified);
    }

    public function testRepairLeavesAnExplicitNo(): void {
        $this->appValues['telemetry_enabled'] = 'false';
        $this->assertFalse($this->consent()->switchOffUndecided(), 'nothing to write');
    }

    public function testRepairAsksEveryEnabledAdministratorOncePerVersion(): void {
        $this->runRepairStep('1.6.0');
        $this->assertSame(['alice', 'bob'], $this->notified);
        $this->assertSame('1.6.0', $this->appValues['telemetry_asked_version']);

        $this->runRepairStep('1.6.0');
        $this->assertSame(['alice', 'bob'], $this->notified, 'asked twice for one version');

        $this->runRepairStep('1.7.0');
        $this->assertSame(['alice', 'bob', 'alice', 'bob'], $this->notified);
        $this->assertSame('1.7.0', $this->appValues['telemetry_asked_version']);
    }

    public function testRepairNeverAsksAfterNeverAskAgain(): void {
        $this->appValues['telemetry_never_ask'] = 'true';
        $this->runRepairStep('1.6.0');
        $this->runRepairStep('1.7.0');

        $this->assertSame([], $this->notified);
        $this->assertSame('false', $this->appValues['telemetry_enabled']);
    }

    // ── The three answers ─────────────────────────────────────────

    public function testShareSwitchesOnForTheCurrentSchemaAndDismisses(): void {
        $this->consent()->give();

        $this->assertSame('true', $this->appValues['telemetry_enabled']);
        $this->assertSame('1', $this->appValues['telemetry_consent_schema']);
        $this->assertSame(1, $this->processed);
        $this->assertFalse($this->consent()->needsAsking());
    }

    public function testNotNowOnlyDismisses(): void {
        $this->appValues['telemetry_enabled'] = 'false';
        $before = $this->appValues;

        $this->consent()->postpone();

        $this->assertSame($before, $this->appValues);
        $this->assertSame(1, $this->processed);
        $this->assertTrue($this->consent()->needsAsking());
    }

    public function testNeverAskAgainIsRememberedAndDismisses(): void {
        $this->consent()->neverAsk();

        $this->assertSame('true', $this->appValues['telemetry_never_ask']);
        $this->assertArrayNotHasKey('telemetry_enabled', $this->appValues);
        $this->assertSame(1, $this->processed);
        $this->assertFalse($this->consent()->needsAsking());
    }

    public function testWithdrawSwitchesOff(): void {
        $this->appValues['telemetry_enabled'] = 'true';
        $this->consent()->withdraw();
        $this->assertSame('false', $this->appValues['telemetry_enabled']);
    }
}
