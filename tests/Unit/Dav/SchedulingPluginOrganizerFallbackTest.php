<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Dav;

use OCA\RoomVox\Dav\SchedulingPlugin;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SchedulingPlugin::buildOrganizerFromCalendarOwner() — the
 * helper that resolves a Nextcloud user id (from the calendar path) into
 * a real email + display name, used by the eM Client LOCATION fallback
 * to construct a valid ORGANIZER property (issue #5).
 */
class SchedulingPluginOrganizerFallbackTest extends TestCase {
    private SchedulingPlugin $plugin;
    private IUserManager $userManager;

    protected function setUp(): void {
        $this->userManager = $this->createMock(IUserManager::class);

        $this->plugin = new SchedulingPlugin(
            $this->createMock(RoomService::class),
            $this->createMock(PermissionService::class),
            $this->createMock(CalDAVService::class),
            $this->createMock(MailService::class),
            $this->createMock(ExchangeSyncService::class),
            $this->userManager,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function invoke(string $calendarOwner): ?array {
        $method = new \ReflectionMethod($this->plugin, 'buildOrganizerFromCalendarOwner');
        return $method->invoke($this->plugin, $calendarOwner);
    }

    private function mockUser(string $uid, ?string $email, ?string $displayName): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getEMailAddress')->willReturn($email);
        $user->method('getDisplayName')->willReturn($displayName ?? '');
        return $user;
    }

    public function testReturnsEmailAndDisplayNameWhenBothPresent(): void {
        $this->userManager->method('get')->with('sebastian')
            ->willReturn($this->mockUser('sebastian', 'sebastian@lautwerfer.de', 'Sebastian Rzepus'));

        $result = $this->invoke('sebastian');

        $this->assertSame([
            'email' => 'sebastian@lautwerfer.de',
            'cn' => 'Sebastian Rzepus',
        ], $result);
    }

    public function testReturnsEmailWithNullCnWhenNoDisplayName(): void {
        $this->userManager->method('get')->with('alice')
            ->willReturn($this->mockUser('alice', 'alice@example.com', null));

        $result = $this->invoke('alice');

        $this->assertSame([
            'email' => 'alice@example.com',
            'cn' => null,
        ], $result);
    }

    public function testReturnsNullWhenUserHasNoEmail(): void {
        $this->userManager->method('get')->with('admin')
            ->willReturn($this->mockUser('admin', null, 'Admin'));

        $this->assertNull($this->invoke('admin'));
    }

    public function testReturnsNullWhenUserHasEmptyEmail(): void {
        $this->userManager->method('get')->with('admin')
            ->willReturn($this->mockUser('admin', '', 'Admin'));

        $this->assertNull($this->invoke('admin'));
    }

    public function testReturnsNullWhenUserDoesNotExist(): void {
        $this->userManager->method('get')->with('ghost')->willReturn(null);

        $this->assertNull($this->invoke('ghost'));
    }
}
