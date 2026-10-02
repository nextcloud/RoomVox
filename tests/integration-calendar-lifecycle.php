#!/usr/bin/env php
<?php
/**
 * RoomVox Room Calendar Lifecycle Integration Test
 *
 * A room's bookings live in the calendar Nextcloud keeps for the room
 * resource (principals/calendar-rooms/roomvox-<id>). Checks against a real
 * Nextcloud, with Nextcloud's own room sync, that:
 * - a new room gets that calendar and no calendar of RoomVox' own;
 * - deactivating a room keeps its calendar and bookings (Nextcloud used to
 *   delete them) while bookings are refused, and reactivating brings them back;
 * - bookings left in the calendar RoomVox used to provision are moved into the
 *   room calendar, and moving twice changes nothing. Only the test room is
 *   touched: the repair step that does this for every room on upgrade is not
 *   run here, so the instance's other rooms are left alone;
 * - deleting a room removes its calendar, and the same name starts empty again;
 * - a room without a calendar is reported as unavailable (issue #44).
 *
 * Everything it creates is removed afterwards, also on failure.
 *
 * Usage, with the app checked out or copied into the apps directory:
 *   sudo -u www-data php apps/roomvox/tests/integration-calendar-lifecycle.php
 * In a Docker setup, copy it into the container's custom_apps/roomvox/tests/
 * first and run it there as www-data.
 */

define('OC_CONSOLE', 1);
require_once __DIR__ . '/../../../lib/base.php';
\OC_App::loadApps();

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\ApiTokenService;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\RoomService;
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\IConfig;

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

/**
 * Wait until the web server sees what this script just wrote to app config.
 * Nextcloud caches app config for 3 seconds per side, and a script on the
 * command line cannot clear the web server's copy.
 */
function settle(): void {
    sleep(4);
}

/** Public API call over HTTP, as an integration would make it. */
function api(string $method, string $path, string $bearer, ?array $body = null): array {
    $host = \OC::$server->get(IConfig::class)->getSystemValue('trusted_domains', ['localhost'])[0];
    $ch = curl_init('http://localhost/index.php/apps/roomvox/api/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_filter([
            'Host: ' . $host,
            'Authorization: Bearer ' . $bearer,
            $body !== null ? 'Content-Type: application/json' : null,
        ]),
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode((string)$raw, true)];
}

$rooms = \OC::$server->get(RoomService::class);
$caldav = \OC::$server->get(CalDAVService::class);
$backend = \OC::$server->get(CalDavBackend::class);
$tokens = \OC::$server->get(ApiTokenService::class);
$roomManager = \OC::$server->get(IRoomManager::class);

/** Calendars of the room resource principal, as Nextcloud lists them */
$roomCalendars = fn(array $room) => $backend->getCalendarsForUser('principals/calendar-rooms/roomvox-' . $room['id']);
/** The calendar RoomVox used to provision itself, trashed or not */
$legacyCalendar = fn(array $room) => $backend->getCalendarByUri('principals/users/' . $room['userId'], 'room-' . $room['userId']);

/** Delete a room the way the admin interface does */
$deleteRoom = function (array $room) use ($caldav, $rooms, $roomManager): void {
    $caldav->deleteCalendar($room['userId']);
    $rooms->deleteRoom($room['id']);
    $roomManager->update();
};

$suffix = date('His');
$created = [];
$tokenId = null;

echo "RoomVox room calendar lifecycle integration test\n";

try {
    // ── 1. A new room ───────────────────────────────────────────
    section('1. A new room');

    $room = $rooms->createRoomWithCalendar(['name' => "zz-lifecycle-$suffix", 'autoAccept' => true], $caldav);
    $created[$room['id']] = $room;
    $calendars = $roomCalendars($room);
    assert_same(1, count($calendars), 'Nextcloud created the room calendar right away');
    assert_same((int)($calendars[0]['id'] ?? 0), $caldav->getRoomCalendarId($room['userId']), 'and RoomVox uses it');
    assert_same(null, $legacyCalendar($room), 'RoomVox provisioned no calendar of its own');

    $token = $tokens->createToken("zz-lifecycle-$suffix", 'book', [$room['id']]);
    $tokenId = $token['id'];
    settle();
    [$status] = api('POST', "/rooms/{$room['id']}/bookings", $token['token'], ['title' => 'Kept', 'start' => '2026-12-07T09:00:00Z', 'end' => '2026-12-07T10:00:00Z']);
    assert_same(201, $status, 'a booking is accepted');

    // ── 2. Deactivating ─────────────────────────────────────────
    section('2. Deactivating and reactivating');

    $rooms->updateRoom($room['id'], ['active' => false]);
    $roomManager->update(); // what saving in the admin interface does
    settle();
    assert_same(1, count($roomCalendars($room)), 'the room calendar survives deactivation (Nextcloud used to delete it)');
    assert_same(1, count($caldav->getBookings($room['userId'])), 'with its booking');

    [$status, $body] = api('GET', "/rooms/{$room['id']}/status", $token['token']);
    assert_same(['unavailable', 'inactive'], [$body['status'] ?? null, $body['reason'] ?? null], '/status: unavailable, reason inactive');
    [$status] = api('POST', "/rooms/{$room['id']}/bookings", $token['token'], ['title' => 'x', 'start' => '2026-12-08T09:00:00Z', 'end' => '2026-12-08T10:00:00Z']);
    assert_same(422, $status, 'booking an inactive room is refused');

    $rooms->updateRoom($room['id'], ['active' => true]);
    $roomManager->update();
    assert_same(['Kept'], array_column($caldav->getBookings($room['userId']), 'summary'), 'reactivated, the booking is still there');

    // ── 3. Bookings in the old calendar ─────────────────────────
    section('3. Moving bookings out of the calendar RoomVox used to provision');

    $backend->createCalendar('principals/users/' . $room['userId'], 'room-' . $room['userId'], []);
    $legacyId = (int)$legacyCalendar($room)['id'];
    $backend->createCalendarObject($legacyId, 'hidden.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//RoomVox integration test//EN\r\nBEGIN:VEVENT\r\nUID:zz-hidden-$suffix\r\nDTSTAMP:20261001T120000Z\r\nDTSTART:20261209T090000Z\r\nDTEND:20261209T100000Z\r\nSUMMARY:Hidden\r\nATTENDEE;CUTYPE=ROOM;PARTSTAT=ACCEPTED:mailto:{$room['email']}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
    assert_same(['Kept'], array_column($caldav->getBookings($room['userId']), 'summary'), 'a booking in the old calendar is not seen');

    assert_same(['moved' => 1, 'skipped' => 0, 'failed' => 0], $caldav->moveLegacyBookings($room['userId']), 'moving finds it');
    assert_same(['Kept', 'Hidden'], array_column($caldav->getBookings($room['userId']), 'summary'), 'and it is in the room calendar now');
    assert_same(null, $legacyCalendar($room), 'the old calendar is gone');
    assert_same(['moved' => 0, 'skipped' => 0, 'failed' => 0], $caldav->moveLegacyBookings($room['userId']), 'moving again changes nothing');

    // ── 4. Deleting and recreating ──────────────────────────────
    section('4. Deleting a room and creating it again');

    $deleteRoom($room);
    unset($created[$room['id']]);
    assert_same([], $roomCalendars($room), 'deleting the room removes its calendar');

    $again = $rooms->createRoomWithCalendar(['name' => "zz-lifecycle-$suffix"], $caldav);
    $created[$again['id']] = $again;
    assert_same($room['id'], $again['id'], 'the same name gives the same id again');
    assert_same([], $caldav->getBookings($again['userId']), 'with an empty calendar');

    // ── 5. A room without a calendar (issue #44) ────────────────
    section('5. A room without a calendar');

    // Created without Nextcloud's sync, the room has no calendar yet.
    $bare = $rooms->createRoom(['name' => "zz-lifecycle-bare-$suffix"]);
    $created[$bare['id']] = $bare;
    $bareToken = $tokens->createToken("zz-lifecycle-bare-$suffix", 'book', [$bare['id']]);
    settle();
    [$status, $body] = api('GET', "/rooms/{$bare['id']}/status", $bareToken['token']);
    $tokens->deleteToken($bareToken['id']);
    assert_same(['unavailable', 'no_calendar'], [$body['status'] ?? null, $body['reason'] ?? null], '/status: unavailable, reason no_calendar');
} catch (\Throwable $e) {
    fail('unexpected ' . get_class($e), $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    section('Cleanup');
    if ($tokenId !== null) {
        $tokens->deleteToken($tokenId);
    }
    foreach ($created as $r) {
        $caldav->deleteCalendar($r['userId']);
        $rooms->deleteRoom($r['id']);
    }
    $roomManager->update();
    $left = array_filter($rooms->getAllRooms(), fn($r) => str_starts_with($r['id'], 'zz-lifecycle'));
    echo '  removed ' . count($created) . ' rooms, their calendars and the token; ' . count($left) . " left\n";
}

echo "\n" . str_repeat('═', 60) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
foreach ($errors as $error) {
    echo "  ❌ {$error}\n";
}
exit($failed > 0 ? 1 : 0);
