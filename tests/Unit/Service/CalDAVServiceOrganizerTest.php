<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\CalDAVService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CalDAVService::resolveOrganizerIdentity() — the helper introduced
 * for issue #14 that decides what to emit as the ORGANIZER property when a
 * booking is created via the REST API.
 */
class CalDAVServiceOrganizerTest extends TestCase {
    private CalDAVService $service;
    private IUserManager $userManager;

    protected function setUp(): void {
        $this->userManager = $this->createMock(IUserManager::class);
        $this->service = new CalDAVService(
            $this->createMock(CalDavBackend::class),
            $this->userManager,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testNcUserIdWithEmailAndDisplayName(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn('alice@nc.example');
        $user->method('getDisplayName')->willReturn('Alice Foo');
        $this->userManager->method('get')->with('alice')->willReturn($user);

        $result = $this->service->resolveOrganizerIdentity('alice');

        $this->assertSame(['email' => 'alice@nc.example', 'cn' => 'Alice Foo'], $result);
    }

    public function testNcUserIdWithoutEmailReturnsNull(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn(null);
        $this->userManager->method('get')->with('alice')->willReturn($user);

        $result = $this->service->resolveOrganizerIdentity('alice');

        $this->assertNull($result);
    }

    public function testNcUserIdWithDisplayNameEqualToEmailDropsCn(): void {
        // Some NC users have displayname == email; the helper should not emit
        // a redundant CN in that case (would produce noisy iCal).
        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn('bob@nc.example');
        $user->method('getDisplayName')->willReturn('bob@nc.example');
        $this->userManager->method('get')->with('bob')->willReturn($user);

        $result = $this->service->resolveOrganizerIdentity('bob');

        $this->assertSame(['email' => 'bob@nc.example', 'cn' => null], $result);
    }

    public function testExternalEmailWithoutNcMatch(): void {
        $this->userManager->method('getByEmail')->with('extern@company.com')->willReturn([]);

        $result = $this->service->resolveOrganizerIdentity('extern@company.com');

        $this->assertSame(['email' => 'extern@company.com', 'cn' => null], $result);
    }

    public function testExternalEmailMatchingExactlyOneNcUserEnrichesCn(): void {
        $matched = $this->createMock(IUser::class);
        $matched->method('getDisplayName')->willReturn('Charlie Quux');
        $this->userManager->method('getByEmail')->with('charlie@nc.example')->willReturn([$matched]);

        $result = $this->service->resolveOrganizerIdentity('charlie@nc.example');

        $this->assertSame(['email' => 'charlie@nc.example', 'cn' => 'Charlie Quux'], $result);
    }

    public function testExternalEmailMatchingMultipleNcUsersOmitsCn(): void {
        $a = $this->createMock(IUser::class);
        $b = $this->createMock(IUser::class);
        $this->userManager->method('getByEmail')->with('shared@nc.example')->willReturn([$a, $b]);

        $result = $this->service->resolveOrganizerIdentity('shared@nc.example');

        $this->assertSame(['email' => 'shared@nc.example', 'cn' => null], $result);
    }

    public function testEmptyInputReturnsNull(): void {
        $this->userManager->expects($this->never())->method('get');
        $this->userManager->expects($this->never())->method('getByEmail');

        $this->assertNull($this->service->resolveOrganizerIdentity(''));
        $this->assertNull($this->service->resolveOrganizerIdentity('   '));
    }

    public function testUnknownUserIdReturnsNull(): void {
        $this->userManager->method('get')->with('ghost')->willReturn(null);

        $result = $this->service->resolveOrganizerIdentity('ghost');

        $this->assertNull($result);
    }
}
