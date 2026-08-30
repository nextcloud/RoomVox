<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\LicenseService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins down what counts as a "named user".
 *
 * Nextcloud's definition, confirmed by Fabrice Mous in August 2026: the
 * accounts with access to the environment — "alle gebruikersaccounts die kunnen
 * inloggen", from any backend. A disabled account still exists and still owns
 * its files, but cannot log in, so it is not a named user.
 *
 * This is worth pinning because the intuitive reading goes the other way: a
 * disabled account occupies a seat, so counting it feels defensible. It is
 * also the figure Nextcloud invoices on, and an app that reports a different
 * number puts a discrepancy on a customer's invoice.
 */
class NamedUserCountTest extends TestCase {
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

    public function testDisabledAccountsAreNotNamedUsers(): void {
        $this->givenAccounts([true, true, true, false, false]);
        $this->assertSame(3, $this->invoke('countNamedUsers'));
    }

    /** The raw total still counts everything — it is what makes the split checkable. */
    public function testTheAccountTotalStillCountsDisabledAccounts(): void {
        $this->givenAccounts([true, true, true, false, false]);
        $this->assertSame(5, $this->invoke('countAllUsers'));
    }

    public function testDisabledAccountsAreCountedSeparately(): void {
        $this->givenAccounts([true, true, true, false, false]);
        $this->assertSame(2, $this->invoke('countDisabledUsers'));
    }

    /** named + disabled must reconcile to the total, or the invoice cannot be explained. */
    public function testTheThreeFiguresReconcile(): void {
        $this->givenAccounts([true, false, true, false, true, true]);
        $this->assertSame(
            $this->invoke('countAllUsers'),
            $this->invoke('countNamedUsers') + $this->invoke('countDisabledUsers'),
        );
    }

    public function testAnInstanceWithNoDisabledAccountsCountsAllOfThem(): void {
        $this->givenAccounts([true, true, true]);
        $this->assertSame(3, $this->invoke('countNamedUsers'));
    }

    /** Every account disabled is a real state (a decommissioned instance), not an error. */
    public function testAnInstanceWithEveryAccountDisabledHasNoNamedUsers(): void {
        $this->givenAccounts([false, false]);
        $this->assertSame(0, $this->invoke('countNamedUsers'));
    }

    public function testAnEmptyInstanceHasNoNamedUsers(): void {
        $this->givenAccounts([]);
        $this->assertSame(0, $this->invoke('countNamedUsers'));
    }
}
