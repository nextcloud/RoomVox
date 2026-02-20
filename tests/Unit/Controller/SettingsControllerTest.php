<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\AppInfo\Application;
use OCA\RoomVox\Controller\SettingsController;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {
    private IAppConfig $appConfig;
    private IRequest $request;
    private IGroupManager $groupManager;
    private IUserSession $userSession;
    private ICrypto $crypto;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->crypto = $this->createMock(ICrypto::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        // Default: admin user
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->willReturn(true);
    }

    private function buildController(): SettingsController {
        return new SettingsController(
            'roomvox',
            $this->request,
            $this->appConfig,
            $this->crypto,
            $this->userSession,
            $this->groupManager,
        );
    }

    // ── GET settings ───────────────────────────────────────────────

    public function testGetSettingsReturnsWebhookMaxInlineSync(): void {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(fn (string $app, string $key, string $default) => match ($key) {
                'exchange_webhook_max_inline_sync' => '5',
                'exchange_webhook_rate_limit' => '10',
                'exchange_client_secret' => '',
                'room_types' => '',
                default => $default,
            });

        $controller = $this->buildController();
        $response = $controller->get();

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(5, $data['exchangeWebhookMaxInlineSync']);
        $this->assertSame(10, $data['exchangeWebhookRateLimit']);
    }

    public function testGetSettingsDefaultWebhookMaxInlineSync(): void {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(fn (string $app, string $key, string $default) => $default);

        $controller = $this->buildController();
        $response = $controller->get();

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(1, $data['exchangeWebhookMaxInlineSync']);
        $this->assertSame(5, $data['exchangeWebhookRateLimit']);
    }

    public function testGetSettingsNonAdminReturns403(): void {
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->groupManager->method('isAdmin')->willReturn(false);

        $controller = $this->buildController();
        $response = $controller->get();

        $this->assertSame(403, $response->getStatus());
    }

    // ── SAVE settings ──────────────────────────────────────────────

    public function testSaveWebhookMaxInlineSync(): void {
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'exchangeWebhookMaxInlineSync' => 3,
                default => null,
            });

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with(Application::APP_ID, 'exchange_webhook_max_inline_sync', '3');

        $controller = $this->buildController();
        $response = $controller->save();

        $this->assertSame(200, $response->getStatus());
    }

    public function testSaveWebhookMaxInlineSyncClampsNegative(): void {
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'exchangeWebhookMaxInlineSync' => -5,
                default => null,
            });

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with(Application::APP_ID, 'exchange_webhook_max_inline_sync', '0');

        $controller = $this->buildController();
        $response = $controller->save();

        $this->assertSame(200, $response->getStatus());
    }

    public function testSaveWebhookMaxInlineSyncNotSentSkipsUpdate(): void {
        $this->request->method('getParam')
            ->willReturn(null);

        // Should not call setValueString at all when nothing is sent
        $this->appConfig->expects($this->never())
            ->method('setValueString');

        $controller = $this->buildController();
        $response = $controller->save();

        $this->assertSame(200, $response->getStatus());
    }

    public function testSaveWebhookRateLimit(): void {
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'exchangeWebhookRateLimit' => 10,
                default => null,
            });

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with(Application::APP_ID, 'exchange_webhook_rate_limit', '10');

        $controller = $this->buildController();
        $response = $controller->save();

        $this->assertSame(200, $response->getStatus());
    }

    public function testSaveWebhookRateLimitClampsNegative(): void {
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'exchangeWebhookRateLimit' => -3,
                default => null,
            });

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with(Application::APP_ID, 'exchange_webhook_rate_limit', '0');

        $controller = $this->buildController();
        $response = $controller->save();

        $this->assertSame(200, $response->getStatus());
    }

    public function testSaveNonAdminReturns403(): void {
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->groupManager->method('isAdmin')->willReturn(false);

        $controller = $this->buildController();
        $response = $controller->save();

        $this->assertSame(403, $response->getStatus());
    }

    // ── GET settings: exchange secret masked ───────────────────────

    public function testGetSettingsMasksClientSecret(): void {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(fn (string $app, string $key, string $default) => match ($key) {
                'exchange_client_secret' => 'encrypted-secret',
                'room_types' => '',
                default => $default,
            });

        $controller = $this->buildController();
        $response = $controller->get();

        $data = $response->getData();
        $this->assertSame('***', $data['exchangeClientSecret']);
    }

    public function testGetSettingsEmptyClientSecretNotMasked(): void {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(fn (string $app, string $key, string $default) => match ($key) {
                'exchange_client_secret' => '',
                'room_types' => '',
                default => $default,
            });

        $controller = $this->buildController();
        $response = $controller->get();

        $data = $response->getData();
        $this->assertSame('', $data['exchangeClientSecret']);
    }
}
