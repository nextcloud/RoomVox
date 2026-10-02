#!/usr/bin/env php
<?php
/**
 * RoomVox CalDAV End-to-End Integration Test
 *
 * Books rooms the way a calendar app does: a PUT over HTTP to the user's own
 * calendar with the room as ATTENDEE, through Nextcloud's Sabre server and
 * RoomVox' scheduling plugin. Checks auto-accept, approval (TENTATIVE), a
 * conflict, a room whose permissions exclude the user, and that deleting the
 * event releases the room.
 *
 * Creates three temporary rooms, an app password for the user, and — when the
 * user has no email address, which Sabre needs to schedule — a temporary one.
 * Removes all of it afterwards, also on failure.
 *
 * Usage, with the app checked out or copied into the apps directory, as a
 * user that is not an administrator (administrators may book every room):
 *   sudo -u www-data php apps/roomvox/tests/integration-caldav-e2e.php <user>
 * In a Docker setup, copy it into custom_apps/roomvox/tests/ and run it there.
 */
define('OC_CONSOLE', 1);
require_once __DIR__ . '/../../../lib/base.php';
\OC_App::loadApps();

// Nextcloud's CLI bootstrap swallows fatals with exit 0; say what happened.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "FATAL: {$e['message']} @ " . basename($e['file']) . ":{$e['line']}\n";
        exit(2);
    }
});

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\Calendar\Room\IManager as IRoomManager;

$pass = 0; $fail = 0;
$check = function (bool $ok, string $label) use (&$pass, &$fail) {
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✅ ' : '  ❌ '), $label, "\n";
};

$user = $argv[1] ?? '';
if ($user === '' || !\OC::$server->get(\OCP\IUserManager::class)->userExists($user)) {
    fwrite(STDERR, "Usage: php integration-caldav-e2e.php <existing non-admin user>\n");
    exit(1);
}
$rooms = \OC::$server->get(RoomService::class);
$perm = \OC::$server->get(PermissionService::class);
$caldav = \OC::$server->get(CalDAVService::class);
$backend = \OC::$server->get(CalDavBackend::class);
$roomManager = \OC::$server->get(IRoomManager::class);
$host = \OC::$server->get(\OCP\IConfig::class)->getSystemValue('trusted_domains', ['localhost'])[0];

$suffix = date('His');
$created = []; $tokenId = null; $origEmail = null; $calCreated = false; $uris = [];

// A temporary app password for the user, never printed
$tokenString = bin2hex(random_bytes(24));
$provider = \OC::$server->get(\OC\Authentication\Token\IProvider::class);

$put = function (string $uri, string $ics) use (&$tokenString, $user, $host) {
    $ch = curl_init("http://localhost/remote.php/dav/calendars/$user/personal/$uri");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => $ics,
        CURLOPT_USERPWD => "$user:$tokenString",
        CURLOPT_HTTPHEADER => ["Host: $host", 'Content-Type: text/calendar; charset=utf-8'],
    ]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code;
};
$del = function (string $uri) use (&$tokenString, $user, $host) {
    $ch = curl_init("http://localhost/remote.php/dav/calendars/$user/personal/$uri");
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => "$user:$tokenString", CURLOPT_HTTPHEADER => ["Host: $host"]]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code;
};
$ics = function (string $uid, string $start, string $end, array $roomEmails) use ($user) {
    $email = \OC::$server->get(\OCP\IUserManager::class)->get($user)->getEMailAddress() ?: "$user@example.invalid";
    $att = '';
    foreach ($roomEmails as $e) {
        $att .= "ATTENDEE;CUTYPE=ROOM;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:$e\r\n";
    }
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//RoomVox e2e//EN\r\nBEGIN:VEVENT\r\nUID:$uid\r\n"
        . "DTSTAMP:20261002T120000Z\r\nDTSTART:$start\r\nDTEND:$end\r\nSUMMARY:e2e $uid\r\n"
        . "ORGANIZER;CN=$user:mailto:$email\r\n$att"
        . "END:VEVENT\r\nEND:VCALENDAR\r\n";
};
$partstat = function (array $room, string $uid) use ($caldav) {
    foreach ($caldav->getBookings($room['userId']) as $b) {
        if (($b['uid'] ?? '') === $uid) {
            return $b['partstat'] ?? '?';
        }
    }
    return 'absent';
};
$settle = fn () => sleep(4);

echo "RoomVox CalDAV end-to-end integration test (over HTTP)\n";
try {
    // Sabre schedules only when the organizer is one of the user's own
    // addresses. A test user often has none, so give it a unique one for the
    // run and put the original back afterwards.
    $u = \OC::$server->get(\OCP\IUserManager::class)->get($user);
    $origEmail = $u->getSystemEMailAddress();
    $u->setSystemEMailAddress("roomvox-e2e-$suffix@example.com");
    $hasEmail = (bool)$u->getEMailAddress();
    $tok = $provider->generateToken($tokenString, $user, $user, null, 'roomvox-e2e', \OCP\Authentication\Token\IToken::PERMANENT_TOKEN);
    $tokenId = $tok->getId();

    $principal = "principals/users/$user";
    if (!$backend->getCalendarByUri($principal, 'personal')) {
        $backend->createCalendar($principal, 'personal', []);
        $calCreated = true;
    }

    $auto = $rooms->createRoomWithCalendar(['name' => "zz-e2e-auto-$suffix", 'autoAccept' => true], $caldav);
    $appr = $rooms->createRoomWithCalendar(['name' => "zz-e2e-approval-$suffix", 'autoAccept' => false], $caldav);
    $none = $rooms->createRoomWithCalendar(['name' => "zz-e2e-noaccess-$suffix", 'autoAccept' => true], $caldav);
    foreach ([$auto, $appr, $none] as $r) { $created[$r['id']] = $r; }
    $perm->setPermissions($auto['id'], ['bookers' => [['type' => 'user', 'id' => $user]]]);
    $perm->setPermissions($appr['id'], ['bookers' => [['type' => 'user', 'id' => $user]], 'managers' => [['type' => 'user', 'id' => 'admin']]]);
    // A room with permissions, none of them for this user. (A room with no
    // permissions at all is open to every user, by design.)
    $perm->setPermissions($none['id'], ['bookers' => [['type' => 'user', 'id' => 'admin']]]);
    $roomManager->update();
    $settle();
    echo "  (organizer has an email address: ", $hasEmail ? 'yes' : 'no', ")\n";

    $d = '20261117';
    // 1. Auto-accept room
    $u1 = "zz-e2e-auto-$suffix"; $uris[] = "$u1.ics";
    $code = $put("$u1.ics", $ics($u1, "{$d}T090000Z", "{$d}T100000Z", [$auto['email']]));
    $check(in_array($code, [201, 204], true), "PUT with an auto-accept room answers $code");
    $check($partstat($auto, $u1) === 'ACCEPTED', 'auto-accept room: booking ACCEPTED (got ' . $partstat($auto, $u1) . ')');

    // 2. Approval room
    $u2 = "zz-e2e-appr-$suffix"; $uris[] = "$u2.ics";
    $put("$u2.ics", $ics($u2, "{$d}T110000Z", "{$d}T120000Z", [$appr['email']]));
    $check($partstat($appr, $u2) === 'TENTATIVE', 'approval room: booking TENTATIVE, waiting for a manager (got ' . $partstat($appr, $u2) . ')');

    // 3. Conflict on the auto-accept room
    $u3 = "zz-e2e-conflict-$suffix"; $uris[] = "$u3.ics";
    $put("$u3.ics", $ics($u3, "{$d}T093000Z", "{$d}T103000Z", [$auto['email']]));
    $st = $partstat($auto, $u3);
    $check($st === 'absent' || $st === 'DECLINED', "overlapping booking is refused (got $st)");

    // 4. No booking permission
    $u4 = "zz-e2e-noaccess-$suffix"; $uris[] = "$u4.ics";
    $put("$u4.ics", $ics($u4, "{$d}T130000Z", "{$d}T140000Z", [$none['email']]));
    $st = $partstat($none, $u4);
    $check($st === 'absent' || $st === 'DECLINED', "room whose permissions exclude this user refuses (got $st)");

    // 5. Cancelling frees the room
    $code = $del("$u1.ics");
    $check(in_array($code, [200, 204], true), "DELETE answers $code");
    $st = $partstat($auto, $u1);
    $check($st === 'absent' || $st === 'DECLINED', "canceling removes or releases the booking (got $st)");
} catch (\Throwable $e) {
    $check(false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage());
} finally {
    echo "── Cleanup\n";
    foreach ($uris as $uri) { @$del($uri); }
    foreach ($created as $r) { $caldav->deleteCalendar($r['userId']); $rooms->deleteRoom($r['id']); }
    $roomManager->update();
    if ($tokenId !== null) { $provider->invalidateTokenById($user, $tokenId); }
    if (isset($u)) { $u->setSystemEMailAddress($origEmail ?? ''); echo '  email restored to: ', var_export($u->getSystemEMailAddress(), true), "\n"; }
    if ($calCreated) {
        $cal = $backend->getCalendarByUri("principals/users/$user", 'personal');
        if ($cal) { $backend->deleteCalendar($cal['id'], true); }
    }
    $left = array_filter($rooms->getAllRooms(), fn ($r) => str_starts_with($r['id'], 'zz-e2e'));
    echo '  rooms left: ', count($left), ', token removed: ', $tokenId !== null ? 'yes' : 'n/a', "\n";
}
echo "\n  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
