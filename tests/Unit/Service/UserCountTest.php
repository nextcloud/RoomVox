<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\LicenseService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins down what counts as a "named user": every account, disabled included.
 *
 * Nextcloud's own wording is "accounts that can log in", which reads as
 * excluding disabled accounts. We count them anyway, and the reason is worth
 * writing down because the phrasing invites the opposite conclusion.
 *
 * That wording describes a Microsoft-shaped model, where a licence is the key
 * that switches a product on: an account without one has no access, so it
 * genuinely costs nothing. Nothing in RoomVox is gated — it runs in full for
 * every account on the server, licensed or not — so "can log in" no longer
 * marks the line between who is served and who is not. A disabled account also
 * keeps its rooms, bookings and file ownership: the seat is retired, not
 * released.
 *
 * The disabled count is still measured and reported on its own, so the licence
 * server can move to a different basis without an app release.
 */
class UserCountTest extends TestCase {
    private LicenseService $service;
    private IUserManager $userManager;

    protected function setUp(): void {
        $this->userManager = $this->createMock(IUserManager::class);

        // IDBConnection cannot be mocked here — OCP's IQueryBuilder references
        // Doctrine types that the standalone suite does not autoload. The
        // counting methods never touch the database, so the service is built
        // without running the constructor and only the dependency they do use
        // is injected.
        $this->service = (new \ReflectionClass(LicenseService::class))->newInstanceWithoutConstructor();
        $this->setPrivate('userManager', $this->userManager);
        $this->setPrivate('logger', $this->createMock(LoggerInterface::class));
    }

    private function setPrivate(string $property, object $value): void {
        $p = new \ReflectionProperty(LicenseService::class, $property);
        $p->setAccessible(true);
        $p->setValue($this->service, $value);
    }

    /** @param bool[] $enabled one entry per account */
    private function givenAccounts(array $enabled): void {
        $users = [];
        foreach ($enabled as $isEnabled) {
            $user = $this->createMock(IUser::class);
            $user->method('isEnabled')->willReturn($isEnabled);
            $users[] = $user;
        }

        $this->userManager->method('callForAllUsers')
            ->willReturnCallback(function (callable $fn) use ($users) {
                foreach ($users as $user) {
                    $fn($user);
                }
            });
    }

    private function invoke(string $method): int {
        $m = new \ReflectionMethod(LicenseService::class, $method);
        $m->setAccessible(true);
        return $m->invoke($this->service);
    }

    /** The point of the whole file: disabling an account does not remove it. */
    public function testDisabledAccountsCountTowardsTheTotal(): void {
        $this->givenAccounts([true, true, true, false, false]);
        $this->assertSame(5, $this->invoke('countAllUsers'));
    }

    public function testDisabledAccountsAreAlsoCountedSeparately(): void {
        $this->givenAccounts([true, true, true, false, false]);
        $this->assertSame(2, $this->invoke('countDisabledUsers'));
    }

    /**
     * The disabled count must never exceed the total. It is reported alongside
     * it so the licence server can subtract if the basis ever changes, and that
     * subtraction has to stay non-negative.
     */
    public function testTheDisabledCountNeverExceedsTheTotal(): void {
        $this->givenAccounts([true, false, true, false, true, true]);
        $this->assertLessThanOrEqual(
            $this->invoke('countAllUsers'),
            $this->invoke('countDisabledUsers'),
        );
    }

    public function testAnInstanceWithNoDisabledAccountsCountsAllOfThem(): void {
        $this->givenAccounts([true, true, true]);
        $this->assertSame(3, $this->invoke('countAllUsers'));
        $this->assertSame(0, $this->invoke('countDisabledUsers'));
    }

    /**
     * A decommissioned instance: every account disabled. Still five accounts,
     * because nothing was deleted and RoomVox still runs.
     */
    public function testAnInstanceWithEveryAccountDisabledStillCountsThem(): void {
        $this->givenAccounts([false, false]);
        $this->assertSame(2, $this->invoke('countAllUsers'));
    }

    public function testAnEmptyInstanceCountsNothing(): void {
        $this->givenAccounts([]);
        $this->assertSame(0, $this->invoke('countAllUsers'));
        $this->assertSame(0, $this->invoke('countDisabledUsers'));
    }
}
