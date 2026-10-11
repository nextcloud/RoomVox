<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\RoomApiController;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\ImportExportService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCA\RoomVox\Tests\Unit\Support\EnglishL10N;
use OCP\BackgroundJob\IJobList;
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for issue #31 — LDAP groups were unfindable in the
 * permission editor.
 *
 * IGroupManager::search() delegates to every registered backend, but a backend
 * decides for itself what the search term is matched against. user_ldap matches
 * the LDAP group display name attribute, not the Nextcloud group ID, so a group
 * whose ID differs from that attribute never appears in search() results while
 * IGroupManager::get() resolves it fine. Nextcloud's own sharee search
 * (OC\Collaboration\Collaborators\GroupPlugin) compensates with an exact
 * group-ID lookup; searchSharees() now does the same.
 */
class RoomApiSearchShareesTest extends TestCase {
    private RoomApiController $controller;
    private IRequest $request;
    private IGroupManager $groupManager;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $userSession = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $userSession->method('getUser')->willReturn($user);

        $this->controller = new RoomApiController(
            'roomvox',
            $this->request,
            $this->createMock(RoomService::class),
            $this->createMock(PermissionService::class),
            $this->createMock(CalDAVService::class),
            $this->createMock(MailService::class),
            $this->createMock(ImportExportService::class),
            $this->createMock(IRoomManager::class),
            $userSession,
            $this->createMock(IUserManager::class),
            $this->groupManager,
            $this->createMock(IJobList::class),
            $this->createMock(IURLGenerator::class),
            $this->logger,
            new EnglishL10N(),
        );
    }

    private function setSearch(string $search): void {
        $this->request->method('getParam')->willReturnCallback(
            fn(string $key, $default = null) => $key === 'search' ? $search : $default,
        );
    }

    private function mockGroup(string $gid, string $displayName): IGroup {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn($gid);
        $group->method('getDisplayName')->willReturn($displayName);
        return $group;
    }

    public function testReturnsGroupsFoundByBackendSearch(): void {
        $this->setSearch('staff');
        $this->groupManager->method('search')->with('staff', 25)->willReturn([
            $this->mockGroup('staff', 'Staff'),
        ]);
        $this->groupManager->method('get')->willReturn(null);

        $this->assertSame([
            ['type' => 'group', 'id' => 'staff', 'label' => 'Staff'],
        ], $this->controller->searchSharees()->getData());
    }

    /**
     * The core of issue #31: search() finds nothing (the LDAP backend matched
     * the term against the display name attribute), but the term is an exact
     * group ID. Without the fallback the group is unreachable in the UI.
     */
    public function testExactGroupIdIsFoundWhenBackendSearchMissesIt(): void {
        $ldapGid = 'cn=Fire Brigade,ou=Groups,dc=example,dc=org';
        $this->setSearch($ldapGid);
        $this->groupManager->method('search')->willReturn([]);
        $this->groupManager->method('get')->with($ldapGid)
            ->willReturn($this->mockGroup($ldapGid, 'Fire Brigade'));

        $this->assertSame([
            ['type' => 'group', 'id' => $ldapGid, 'label' => 'Fire Brigade'],
        ], $this->controller->searchSharees()->getData());
    }

    /**
     * A group that search() already returned must not be listed twice when the
     * search term also happens to be its exact group ID.
     */
    public function testExactMatchIsNotDuplicated(): void {
        $this->setSearch('staff');
        $this->groupManager->method('search')->willReturn([
            $this->mockGroup('staff', 'Staff'),
        ]);
        $this->groupManager->method('get')->willReturn($this->mockGroup('staff', 'Staff'));

        $this->assertSame([
            ['type' => 'group', 'id' => 'staff', 'label' => 'Staff'],
        ], $this->controller->searchSharees()->getData());
    }

    public function testEmptySearchDoesNotTriggerExactLookup(): void {
        $this->setSearch('');
        $this->groupManager->method('search')->willReturn([]);
        $this->groupManager->expects($this->never())->method('get');

        $this->assertSame([], $this->controller->searchSharees()->getData());
    }

    /**
     * An empty result is what an admin sees when a group backend is registered
     * but inactive (user_ldap enables its group backend only when both the
     * group filter and the group-member association attribute are set, and
     * returns an empty list without logging). Leave a diagnosable trace.
     */
    public function testEmptyResultIsLogged(): void {
        $this->setSearch('brigade');
        $this->groupManager->method('search')->willReturn([]);
        $this->groupManager->method('get')->willReturn(null);

        $this->logger->expects($this->once())->method('debug');

        $this->assertSame([], $this->controller->searchSharees()->getData());
    }

    public function testSuccessfulSearchIsNotLogged(): void {
        $this->setSearch('staff');
        $this->groupManager->method('search')->willReturn([
            $this->mockGroup('staff', 'Staff'),
        ]);
        $this->groupManager->method('get')->willReturn(null);

        $this->logger->expects($this->never())->method('debug');

        $this->controller->searchSharees();
    }
}
