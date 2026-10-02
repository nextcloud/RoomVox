#!/usr/bin/env php
<?php
/**
 * RoomVox Room Email Uniqueness Integration Test
 *
 * A room's email address is its scheduling identity, so two rooms must not
 * share one. Checks against a real Nextcloud that a taken address is refused
 * on create (any case, nothing left behind), that rooms which already shared
 * an address before the check existed stay editable, that moving onto another
 * room's address is refused, that a generated {id}@roomvox.local address skips
 * one that is taken, and that a CSV with two rows on one address is flagged.
 *
 * Everything it creates is removed afterwards, also on failure.
 *
 * Usage, with the app checked out or copied into the apps directory:
 *   sudo -u www-data php apps/roomvox/tests/integration-email-uniqueness.php
 * In a Docker setup, copy it into the container's custom_apps/roomvox/tests/
 * first and run it there as www-data.
 */

define('OC_CONSOLE', 1);
require_once __DIR__ . '/../../../lib/base.php';
\OC_App::loadApps();

use OCA\RoomVox\AppInfo\Application;
use OCA\RoomVox\Exception\EmailAlreadyUsedException;
use OCA\RoomVox\Service\ImportExportService;
use OCA\RoomVox\Service\RoomService;
use OCP\IAppConfig;

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

$rooms = \OC::$server->get(RoomService::class);
$appConfig = \OC::$server->get(IAppConfig::class);
$import = \OC::$server->get(ImportExportService::class);

$suffix = date('His');
$created = [];
$shared = "zz-uniq-shared-$suffix@example.com";

echo "RoomVox room email uniqueness integration test\n";

try {
    // ── 1. Create ───────────────────────────────────────────────
    section('1. Creating a room');

    $a = $rooms->createRoom(['name' => "zz-uniq-a-$suffix", 'email' => $shared]);
    $created[] = $a['id'];
    ok("room {$a['id']} created with $shared");

    $before = count($rooms->getAllRooms());
    try {
        $b = $rooms->createRoom(['name' => "zz-uniq-b-$suffix", 'email' => 'MAILTO:' . strtoupper($shared)]);
        $created[] = $b['id'];
        fail('same address in other case with mailto: is refused', 'room was created');
    } catch (EmailAlreadyUsedException $e) {
        ok('same address in other case with mailto: is refused');
        assert_same($a['id'], $e->getConflictingRoomId(), 'the refusal names the room that holds it');
    }
    assert_same($before, count($rooms->getAllRooms()), 'a refused create leaves no room behind');

    // ── 2. Rooms that already shared an address ─────────────────
    section('2. Rooms that already shared an address');

    // Written directly, as data from before the check existed would be.
    $legacyId = "zz-uniq-legacy-$suffix";
    $appConfig->setValueString(Application::APP_ID, 'room/' . $legacyId, json_encode([
        'id' => $legacyId, 'userId' => 'rb_' . $legacyId, 'name' => $legacyId,
        'email' => $shared, 'capacity' => 4, 'autoAccept' => false, 'active' => true, 'smtpConfig' => null,
    ]));
    $index = json_decode($appConfig->getValueString(Application::APP_ID, 'rooms_index', '[]'), true);
    $index[] = $legacyId;
    $appConfig->setValueString(Application::APP_ID, 'rooms_index', json_encode($index));
    $created[] = $legacyId;

    try {
        $updated = $rooms->updateRoom($legacyId, ['email' => $shared, 'capacity' => 9]);
        assert_same(9, $updated['capacity'] ?? null, 'editing it with its unchanged address works (the editor sends the whole form)');
    } catch (EmailAlreadyUsedException $e) {
        fail('editing it with its unchanged address works (the editor sends the whole form)', 'refused with 409');
    }

    $c = $rooms->createRoom(['name' => "zz-uniq-c-$suffix", 'email' => "zz-uniq-c-$suffix@example.com"]);
    $created[] = $c['id'];
    try {
        $rooms->updateRoom($legacyId, ['email' => $c['email']]);
        fail("moving onto another room's address is refused", 'it was accepted');
    } catch (EmailAlreadyUsedException $e) {
        ok("moving onto another room's address is refused");
    }

    // ── 3. Generated addresses ──────────────────────────────────
    section('3. Generated addresses');

    $base = "zz-uniq-meet-$suffix";
    $first = $rooms->createRoom(['name' => $base]);
    $created[] = $first['id'];
    $holder = $rooms->createRoom(['name' => "zz-uniq-holder-$suffix", 'email' => "$base-1@roomvox.local"]);
    $created[] = $holder['id'];
    $third = $rooms->createRoom(['name' => $base]);
    $created[] = $third['id'];
    assert_same("$base-2", $third['id'], 'the id skips the one whose generated address is taken');
    assert_same("$base-2@roomvox.local", $third['email'], 'so the generated address is unique');

    // ── 4. CSV import ───────────────────────────────────────────
    section('4. CSV import');

    $csv = "name,email\nzz-uniq-csv1-$suffix,zz-uniq-csv-$suffix@example.com\nzz-uniq-csv2-$suffix,ZZ-UNIQ-CSV-$suffix@example.com\n";
    $rows = $import->parseCsv($csv)['rows'];
    assert_same([], $rows[0]['errors'] ?? null, 'first row with the address is fine');
    assert_same(['Duplicate email in CSV (line 2)'], $rows[1]['errors'] ?? null, 'second row on the same address is flagged');

    $existing = $import->parseCsv("name,email\nzz-uniq-csv3-$suffix,{$c['email']}\n")['rows'][0];
    assert_same(['update', $c['id']], [$existing['action'], $existing['matchedId']], "a row with an existing room's address updates that room, it does not move the address");
} catch (\Throwable $e) {
    fail('unexpected ' . get_class($e), $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    section('Cleanup');
    foreach ($created as $id) {
        $rooms->deleteRoom($id);
    }
    $left = array_filter($rooms->getAllRooms(), fn($r) => str_starts_with($r['id'], 'zz-uniq-'));
    echo '  removed ' . count($created) . ' rooms, ' . count($left) . " left behind\n";
}

echo "\n" . str_repeat('═', 60) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
foreach ($errors as $error) {
    echo "  ❌ {$error}\n";
}
exit($failed > 0 ? 1 : 0);
