<?php

declare(strict_types=1);

namespace OCA\RoomVox\Dav;

use OCA\RoomVox\Service\PermissionService;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Filters room principals out of PROPFIND responses for users who don't have
 * view access. Closes a gap in Nextcloud's AbstractPrincipalBackend, which
 * applies group_restrictions in searchPrincipals/findByUri but not in
 * getPrincipalsByPrefix (the call that drives PROPFIND on
 * principals/calendar-rooms/).
 *
 * Returning false from a propFind handler causes Sabre's getPropertiesByNode
 * to skip the node entirely, so it never appears in the multistatus response.
 *
 * Performance: bulk-loads all room permissions and the current user's groups
 * once per request, then filters in-memory. O(N) PHP work, 2 IAppConfig reads
 * total regardless of room count.
 */
class RoomVisibilityPlugin extends ServerPlugin {
    private const PRINCIPAL_PREFIX = 'principals/calendar-rooms/';
    private const ROOM_USER_PREFIX = 'rb_';

    /** @var array<string, array{viewers: array, bookers: array, managers: array}>|null */
    private ?array $cachedPermissions = null;

    /** @var string[]|null */
    private ?array $cachedUserGroups = null;

    private ?bool $cachedIsAdmin = null;

    private ?string $cachedUserId = null;

    public function __construct(
        private PermissionService $permissionService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }

    public function initialize(Server $server): void {
        // Priority 50: after DAVACL (20), before CorePlugin (default 100).
        // Returning false here removes the node from the multistatus response.
        $server->on('propFind', [$this, 'filterRoomPrincipal'], 50);
    }

    public function getPluginName(): string {
        return 'roomvox-room-visibility';
    }

    public function getPluginInfo(): array {
        return [
            'name' => $this->getPluginName(),
            'description' => 'Hides room principals from users without view permission',
        ];
    }

    /**
     * Return false to hide the node from the PROPFIND response, true otherwise.
     */
    public function filterRoomPrincipal(PropFind $propFind, INode $node): bool {
        try {
            $path = $propFind->getPath();
            if (!str_starts_with($path, self::PRINCIPAL_PREFIX)) {
                return true;
            }

            $principalName = substr($path, \strlen(self::PRINCIPAL_PREFIX));
            if ($principalName === '' || str_contains($principalName, '/')) {
                return true; // Collection root or nested path, not a single principal
            }

            $roomId = $this->extractRoomId($principalName);
            if ($roomId === null) {
                return true; // Not a RoomVox principal (other backends share this collection)
            }

            $user = $this->userSession->getUser();
            if ($user === null) {
                return true; // No session context (e.g. cron); don't filter
            }

            $this->ensureCacheFor($user->getUID());

            if ($this->cachedIsAdmin === true) {
                return true;
            }

            return $this->canView($roomId);
        } catch (\Throwable $e) {
            // Fail-open on visibility: showing a room is recoverable
            // (SchedulingPlugin still rejects unauthorized bookings),
            // hiding all rooms because of a bug is not.
            $this->logger->warning('RoomVox: visibility filter error, defaulting to visible: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Extract the RoomVox room id from a principal name.
     *
     * NC's RoomBackend exposes principals like `roomvox-{roomId}`. We also
     * encounter raw `rb_{userId}` paths in some flows. Anything else belongs
     * to another backend (Talk, etc.) and we leave it alone.
     */
    private function extractRoomId(string $principalName): ?string {
        if (str_starts_with($principalName, 'roomvox-')) {
            return substr($principalName, \strlen('roomvox-'));
        }

        if (str_starts_with($principalName, self::ROOM_USER_PREFIX)) {
            // Look up by userId via the cached permissions map keys won't help;
            // need a userId → roomId resolution. RoomService caches this, but
            // pulling it in here adds a dependency for an edge case.
            // Return null → don't filter rb_ paths (they go through scheduler anyway).
            return null;
        }

        return null;
    }

    private function ensureCacheFor(string $userId): void {
        if ($this->cachedUserId === $userId && $this->cachedPermissions !== null) {
            return;
        }

        $this->cachedUserId = $userId;
        $this->cachedIsAdmin = $this->groupManager->isAdmin($userId);

        if ($this->cachedIsAdmin) {
            // Admin sees everything; skip the bulk loads
            $this->cachedPermissions = [];
            $this->cachedUserGroups = [];
            return;
        }

        $this->cachedPermissions = $this->permissionService->getAllEffectivePermissions();

        $userGroupObjs = $this->groupManager->getUserGroups($this->userSession->getUser());
        $this->cachedUserGroups = array_keys($userGroupObjs);
    }

    private function canView(string $roomId): bool {
        $perms = $this->cachedPermissions[$roomId] ?? null;
        if ($perms === null) {
            // Room not in permissions map = no entries configured.
            // Treat as no restriction (matches IRoom::getGroupRestrictions=[] semantics
            // that NC interprets as "visible to everyone").
            return true;
        }

        $allEntries = array_merge(
            $perms['viewers'] ?? [],
            $perms['bookers'] ?? [],
            $perms['managers'] ?? [],
        );

        if ($allEntries === []) {
            return true;
        }

        $userGroups = $this->cachedUserGroups ?? [];

        foreach ($allEntries as $entry) {
            $type = $entry['type'] ?? '';
            $id = $entry['id'] ?? '';

            if ($type === 'user' && $id === $this->cachedUserId) {
                return true;
            }

            if ($type === 'group' && \in_array($id, $userGroups, true)) {
                return true;
            }
        }

        return false;
    }
}
