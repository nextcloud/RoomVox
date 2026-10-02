<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\LicenseController;
use OCA\RoomVox\Controller\SettingsController;
use OCA\RoomVox\Controller\TelemetryConsentController;
use OCA\RoomVox\Service\LicenseService;
use OCA\RoomVox\Service\TelemetryConsentService;
use OCA\RoomVox\Service\TelemetryService;
use OCA\RoomVox\Tests\Unit\Support\InMemoryAppConfig;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The endpoints that change the usage-statistics choice: the three
 * notification actions and the one switch in the Support pane.
 */
class TelemetryControllersTest extends TestCase {
    use InMemoryAppConfig;

    private int $processed = 0;

    protected function setUp(): void {
        $this->appValues = [];
        $this->processed = 0;
    }

    private function consent(): TelemetryConsentService {
        $notifications = $this->createMock(INotificationManager::class);
        $notifications->method('createNotification')->willReturnCallback(function () {
            $n = $this->createMock(INotification::class);
            foreach (['setApp', 'setObject', 'setSubject'] as $setter) {
                $n->method($setter)->willReturnSelf();
            }
            return $n;
        });
        $notifications->method('markProcessed')->willReturnCallback(function () {
            $this->processed++;
        });
        return new TelemetryConsentService(
            $this->createInMemoryAppConfig(),
            $notifications,
            $this->createMock(IGroupManager::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function consentController(): TelemetryConsentController {
        return new TelemetryConsentController('roomvox', $this->createMock(IRequest::class), $this->consent());
    }

    // ── Notification actions ──────────────────────────────────────

    public function testShareSwitchesOnRecordsSchemaAndDismisses(): void {
        $response = $this->consentController()->share();

        $this->assertSame(['enabled' => true], $response->getData());
        $this->assertSame('true', $this->appValues['telemetry_enabled']);
        $this->assertSame((string)TelemetryService::SCHEMA, $this->appValues['telemetry_consent_schema']);
        $this->assertSame(1, $this->processed);
    }

    public function testNotNowChangesNothingButDismisses(): void {
        $response = $this->consentController()->postpone();

        $this->assertSame(['enabled' => false], $response->getData());
        $this->assertSame([], $this->appValues);
        $this->assertSame(1, $this->processed);
    }

    public function testNeverAskAgainKeepsTelemetryOffAndDismisses(): void {
        $response = $this->consentController()->neverAsk();

        $this->assertSame(['enabled' => false], $response->getData());
        $this->assertSame(['telemetry_never_ask' => 'true'], $this->appValues);
        $this->assertSame(1, $this->processed);
    }

    /**
     * Admin only and CSRF-checked: neither opt-out attribute may appear on
     * the class or on any action.
     */
    public function testConsentActionsAreAdminOnlyAndCsrfChecked(): void {
        $class = new \ReflectionClass(TelemetryConsentController::class);
        $this->assertSame([], $class->getAttributes(NoAdminRequired::class));
        foreach (['share', 'postpone', 'neverAsk'] as $method) {
            $reflection = $class->getMethod($method);
            $this->assertSame([], $reflection->getAttributes(NoAdminRequired::class), $method);
            $this->assertSame([], $reflection->getAttributes(NoCSRFRequired::class), $method);
        }
    }

    // ── The switch in the Support pane ────────────────────────────

    private function licenseController(IRequest $request, ?TelemetryService $telemetry = null): LicenseController {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn(true);

        if ($telemetry === null) {
            $telemetry = $this->createMock(TelemetryService::class);
            $telemetry->method('getStatus')->willReturn(['enabled' => 'from-status']);
        }

        return new LicenseController(
            'roomvox',
            $request,
            $this->createMock(LicenseService::class),
            $telemetry,
            $this->consent(),
            $session,
            $groups,
        );
    }

    private function request(array $params): IRequest {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $params[$key] ?? $default);
        return $request;
    }

    public function testSwitchingOnInThePaneIsConsentAndDismisses(): void {
        $response = $this->licenseController($this->request(['enabled' => true]))->setTelemetry();

        $this->assertTrue($response->getData()['success']);
        $this->assertSame('true', $this->appValues['telemetry_enabled']);
        $this->assertSame((string)TelemetryService::SCHEMA, $this->appValues['telemetry_consent_schema']);
        $this->assertSame(1, $this->processed);
    }

    public function testSwitchingOffInThePane(): void {
        $this->appValues['telemetry_enabled'] = 'true';
        $this->licenseController($this->request(['enabled' => false]))->setTelemetry();
        $this->assertSame('false', $this->appValues['telemetry_enabled']);
    }

    /** Only a real boolean true switches on; a string or a missing value does not. */
    public function testOnlyBooleanTrueSwitchesOn(): void {
        $this->licenseController($this->request(['enabled' => 'false']))->setTelemetry();
        $this->assertSame('false', $this->appValues['telemetry_enabled']);

        $this->licenseController($this->request([]))->setTelemetry();
        $this->assertSame('false', $this->appValues['telemetry_enabled']);
    }

    public function testSendNowPassesRecentlySentThrough(): void {
        $telemetry = $this->createMock(TelemetryService::class);
        $telemetry->method('sendReportWithDetails')->willReturn(['success' => false, 'reason' => 'recently_sent']);
        $telemetry->expects($this->never())->method('isEnabled');

        $data = $this->licenseController($this->request([]), $telemetry)->sendTelemetry()->getData();

        $this->assertFalse($data['success']);
        $this->assertSame('recently_sent', $data['reason']);
        $this->assertArrayNotHasKey('telemetry_enabled', $this->appValues, 'send-now must not switch telemetry on');
    }

    // ── No second switch ──────────────────────────────────────────

    public function testSettingsEndpointNeitherReadsNorWritesTelemetry(): void {
        $appConfig = $this->createInMemoryAppConfig();
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn(true);

        $controller = new SettingsController(
            'roomvox',
            $this->request(['telemetryEnabled' => true]),
            $appConfig,
            $this->createMock(ICrypto::class),
            $session,
            $groups,
        );

        $controller->save();
        $this->assertArrayNotHasKey('telemetry_enabled', $this->appValues);
        $this->assertArrayNotHasKey('telemetryEnabled', $controller->get()->getData());
    }
}
