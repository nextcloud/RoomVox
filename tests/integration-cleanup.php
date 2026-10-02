#!/usr/bin/env php
<?php
/**
 * RoomVox Permission Cleanup Integration Test
 *
 * Deletes real Nextcloud accounts and groups and checks that RoomVox removes
 * their permission entries: the listeners must be registered, Nextcloud must
 * dispatch UserDeletedEvent / GroupDeletedEvent to them, and the sweep must
 * cover room and room-group permissions while keeping a user and a group that
 * share an id apart. Also checks the "no managers left" warning in the log.
 *
 * Everything it creates (accounts, groups, a room, permission keys) is removed
 * afterwards, also on failure.
 *
 * Usage, with the app checked out or copied into the apps directory:
 *   sudo -u www-data php apps/roomvox/tests/integration-cleanup.php
 * In a Docker setup, copy it into the container's custom_apps/roomvox/tests/
 * first and run it there as www-data.
 */

define('OC_CONSOLE', 1);
require_once __DIR__ . '/../../../lib/base.php';
// Without the apps loaded, RoomVox' listeners are not registered.
\OC_App::loadApps();

use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

$passed = 0;
$failed = 0;
$errors = [];

function ok(string $label): void {
    global $passed;
    $passed++;
    echo "  ✅ {$label}\n";
}

function fail(string $label, string $reason = ''): void {
    global $failed, $errors;
    $failed++;
    $msg = $reason ? "{$label} — {$reason}" : $label;
    $errors[] = $msg;
    echo "  ❌ {$msg}\n";
}

function assert_same(mixed $expected, mixed $actual, string $label): void {
    $expected === $actual
        ? ok($label)
        : fail($label, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

function section(string $title): void {
    echo "\n── {$title}\n";
}

/** "type:id" for every entry in one role, for compact comparisons. */
function ids(array $permissions, string $role): array {
    return array_map(fn(array $e) => $e['type'] . ':' . $e['id'], $permissions[$role]);
}

/** Log lines written since $offset that mention $needle. */
function logLinesSince(int $offset, string $needle): array {
    $config = \OC::$server->get(IConfig::class);
    $file = $config->getSystemValue('logfile', $config->getSystemValue('datadirectory', '') . '/nextcloud.log');
    $handle = @fopen($file, 'r');
    if ($handle === false) {
        return [];
    }
    fseek($handle, $offset);
    $lines = [];
    while (($line = fgets($handle)) !== false) {
        if (str_contains($line, $needle)) {
            $lines[] = $line;
        }
    }
    fclose($handle);
    return $lines;
}

function logSize(): int {
    $config = \OC::$server->get(IConfig::class);
    $file = $config->getSystemValue('logfile', $config->getSystemValue('datadirectory', '') . '/nextcloud.log');
    clearstatcache();
    return is_file($file) ? (int)filesize($file) : 0;
}

$users = \OC::$server->get(IUserManager::class);
$groups = \OC::$server->get(IGroupManager::class);
$rooms = \OC::$server->get(RoomService::class);
$permissions = \OC::$server->get(PermissionService::class);
$random = \OC::$server->get(ISecureRandom::class);

$suffix = date('His');
$doomed = "zz-cleanup-user-$suffix";       // account that gets deleted
$keeper = "zz-cleanup-keep-$suffix";       // account that must stay
$team = "zz-cleanup-group-$suffix";        // group that gets deleted
$roomGroup = "zz-cleanup-rg-$suffix";      // room group the rooms belong to
$room = null;
$orphan = null;

echo "RoomVox permission cleanup integration test\n";

try {
    foreach ([$doomed, $keeper] as $uid) {
        $users->createUser($uid, $random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC) . '!Aa1');
    }
    $groups->createGroup($team);
    // A group with the same id as the account that gets deleted: deleting the
    // account must not touch the group's entries.
    $groups->createGroup($doomed);

    $room = $rooms->createRoom(['name' => "zz-cleanup-room-$suffix", 'groupId' => $roomGroup]);
    $orphan = $rooms->createRoom(['name' => "zz-cleanup-orphan-$suffix", 'groupId' => $roomGroup]);

    $permissions->setPermissions($room['id'], [
        'viewers' => [['type' => 'user', 'id' => $doomed], ['type' => 'group', 'id' => $doomed]],
        'bookers' => [['type' => 'user', 'id' => $keeper], ['type' => 'group', 'id' => $team]],
        'managers' => [['type' => 'user', 'id' => $doomed]],
    ]);
    $permissions->setGroupPermissions($roomGroup, [
        'viewers' => [],
        'bookers' => [['type' => 'user', 'id' => $doomed]],
        'managers' => [['type' => 'group', 'id' => $team]],
    ]);
    echo "Rooms {$room['id']} and {$orphan['id']} in room group $roomGroup, accounts $doomed and $keeper, groups $team and $doomed\n";

    // ── 1. Deleting an account ──────────────────────────────────
    section('1. Deleting an account');

    $offset = logSize();
    $users->get($doomed)->delete();

    $p = $permissions->getPermissions($room['id']);
    assert_same(["group:$doomed"], ids($p, 'viewers'), 'account removed from viewers, same-id group kept');
    assert_same(["user:$keeper", "group:$team"], ids($p, 'bookers'), 'other bookers untouched');
    assert_same([], ids($p, 'managers'), 'account removed from managers');
    assert_same([], ids($permissions->getGroupPermissions($roomGroup), 'bookers'), 'account removed from room-group permissions');
    assert_same(
        [],
        logLinesSince($offset, 'has no managers left'),
        'no warning: the room group still has a manager',
    );

    // ── 2. Deleting a group ─────────────────────────────────────
    section('2. Deleting a group');

    $offset = logSize();
    $groups->get($team)->delete();

    assert_same(["user:$keeper"], ids($permissions->getPermissions($room['id']), 'bookers'), 'group removed from room bookers');
    assert_same([], ids($permissions->getGroupPermissions($roomGroup), 'managers'), 'group removed from room-group managers');
    $warned = logLinesSince($offset, 'has no managers left');
    assert_same(2, count($warned), 'both rooms in the room group are reported, each once');
    assert_same(
        true,
        count(array_filter($warned, fn($l) => str_contains($l, $room['id']))) === 1
            && count(array_filter($warned, fn($l) => str_contains($l, $orphan['id']))) === 1,
        'the warnings name the rooms',
    );
} catch (\Throwable $e) {
    fail('unexpected ' . get_class($e), $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    section('Cleanup');
    foreach ([$room, $orphan] as $r) {
        if ($r !== null) {
            $permissions->deletePermissions($r['id']);
            $rooms->deleteRoom($r['id']);
        }
    }
    $permissions->deleteGroupPermissions($roomGroup);
    foreach ([$doomed, $keeper] as $uid) {
        $users->get($uid)?->delete();
    }
    foreach ([$team, $doomed] as $gid) {
        $groups->get($gid)?->delete();
    }
    echo "  removed rooms, permissions, accounts and groups\n";
}

echo "\n" . str_repeat('═', 60) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
foreach ($errors as $error) {
    echo "  ❌ {$error}\n";
}
exit($failed > 0 ? 1 : 0);
