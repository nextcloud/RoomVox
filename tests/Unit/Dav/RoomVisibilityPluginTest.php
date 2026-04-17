<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Dav;

use OCA\RoomVox\Dav\RoomVisibilityPlugin;
use OCA\RoomVox\Service\PermissionService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;

class RoomVisibilityPluginTest extends TestCase {
    private PermissionService $permissionService;
    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makePlugin(): RoomVisibilityPlugin {
        return new RoomVisibilityPlugin(
            $this->permissionService,
            $this->userSession,
            $this->groupManager,
            $this->logger,
        );
    }

    private function makeUser(string $uid): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    private function makeNode(): INode {
        return $this->createMock(INode::class);
    }

    public function testNonRoomPathPassesThrough(): void {
        $plugin = $this->makePlugin();
        $propFind = new PropFind('principals/users/alice');

        // Should not even try to look up user/groups
        $this->userSession->expects($this->never())->method('getUser');

        $this->assertTrue($plugin->filterRoomPrincipal($propFind, $this->makeNode()));
    }

    public function testCollectionRootPassesThrough(): void {
        $plugin = $this->makePlugin();
        $propFind = new PropFind('principals/calendar-rooms/');

        $this->assertTrue($plugin->filterRoomPrincipal($propFind, $this->makeNode()));
    }

    public function testNonRoomvoxPrincipalPassesThrough(): void {
        $plugin = $this->makePlugin();
        // Talk and other backends share this collection — leave them alone
        $propFind = new PropFind('principals/calendar-rooms/talk-room-xyz');

        $this->assertTrue($plugin->filterRoomPrincipal($propFind, $this->makeNode()));
    }

    public function testAdminSeesRestrictedRoom(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('admin'));
        $this->groupManager->method('isAdmin')->willReturn(true);

        // Admin path should not bulk-load permissions
        $this->permissionService->expects($this->never())->method('getAllEffectivePermissions');

        $plugin = $this->makePlugin();
        $propFind = new PropFind('principals/calendar-rooms/roomvox-vergaderzaal-rotterdam');

        $this->assertTrue($plugin->filterRoomPrincipal($propFind, $this->makeNode()));
    }

    public function testRoomWithoutPermissionsIsVisibleToEveryone(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('test1'));
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('getUserGroups')->willReturn([]);

        // Room not in the permissions map = no entries configured
        $this->permissionService->method('getAllEffectivePermissions')->willReturn([]);

        $plugin = $this->makePlugin();
        $propFind = new PropFind('principals/calendar-rooms/roomvox-aula');

        $this->assertTrue($plugin->filterRoomPrincipal($propFind, $this->makeNode()));
    }

    public function testRoomWithGroupRestrictionVisibleToMember(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('test1'));
        $this->groupManager->method('isAdmin')->willReturn(false);

        $testGroup = $this->createMock(IGroup::class);
        $this->groupManager->method('getUserGroups')->willReturn([
            'TestGroup1' => $testGroup,
        ]);

        $this->permissionService->method('getAllEffectivePermissions')->willReturn([
            'overleg-tulp' => [
                'viewers' => [],
                'bookers' => [['type' => 'group', 'id' => 'TestGroup1']],
                'managers' => [],
            ],
        ]);

        $plugin = $this->makePlugin();
        $propFind = new PropFind('principals/calendar-rooms/roomvox-overleg-tulp');

        $this->assertTrue($plugin->filterRoomPrincipal($propFind, $this->makeNode()));
    }

    public function testRoomWithGroupRestrictionHiddenFromNonMember(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('outsider'));
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('getUserGroups')->willReturn([]);

        $this->permissionService->method('getAllEffectivePermissions')->willReturn([
            'overleg-tulp' => [
                'viewers' => [],
                'bookers' => [['type' => 'group', 'id' => 'TestGroup1']],
                'managers' => [],
            ],
        ]);

        $plugin = $this->makePlugin();
        $propFind = new PropFind('principals/calendar-rooms/roomvox-overleg-tulp');

        $this->assertFalse($plugin->filterRoomPrincipal($propFind, $this->makeNode()));
    }

    public function testRoomWithUserEntryVisibleToThatUser(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('alice'));
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('getUserGroups')->willReturn([]);

        $this->permissionService->method('getAllEffectivePermissions')->willReturn([
            'directiekamer' => [
                'viewers' => [['type' => 'user', 'id' => 'alice']],
                'bookers' => [],
                'managers' => [],
            ],
        ]);

        $plugin = $this->makePlugin();
        $propFind = new PropFind('principals/calendar-rooms/roomvox-directiekamer');

        $this->assertTrue($plugin->filterRoomPrincipal($propFind, $this->makeNode()));
    }

    public function testFailsOpenOnPermissionServiceError(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('test1'));
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->permissionService->method('getAllEffectivePermissions')
            ->willThrowException(new \RuntimeException('boom'));

        $plugin = $this->makePlugin();
        $propFind = new PropFind('principals/calendar-rooms/roomvox-aula');

        // Visibility filter must fail-open — scheduler still rejects unauthorized bookings
        $this->assertTrue($plugin->filterRoomPrincipal($propFind, $this->makeNode()));
    }

    public function testNoSessionUserPassesThrough(): void {
        // Background jobs / cron run without a user session
        $this->userSession->method('getUser')->willReturn(null);

        $plugin = $this->makePlugin();
        $propFind = new PropFind('principals/calendar-rooms/roomvox-aula');

        $this->assertTrue($plugin->filterRoomPrincipal($propFind, $this->makeNode()));
    }

    public function testCachesPermissionsAcrossNodes(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('test1'));
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('getUserGroups')->willReturn([]);

        // Should be called only once even when filter runs for multiple rooms
        $this->permissionService->expects($this->once())
            ->method('getAllEffectivePermissions')
            ->willReturn([]);

        $plugin = $this->makePlugin();
        $node = $this->makeNode();

        $plugin->filterRoomPrincipal(new PropFind('principals/calendar-rooms/roomvox-aula'), $node);
        $plugin->filterRoomPrincipal(new PropFind('principals/calendar-rooms/roomvox-foyer'), $node);
        $plugin->filterRoomPrincipal(new PropFind('principals/calendar-rooms/roomvox-overleg-tulp'), $node);
    }
}
