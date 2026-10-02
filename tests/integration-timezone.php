#!/usr/bin/env php
<?php
/**
 * RoomVox Timezone Integration Test — issues #45 and #49
 *
 * Runs against a real Nextcloud: the container-built services, the real Sabre
 * parser and the Public API over HTTP. It creates a temporary room and API
 * token, writes bookings through the API and straight into the room calendar
 * (all-day, floating, TZID, recurring), checks what is stored, what
 * /availability reports and what the mails say, and removes everything again.
 *
 * The instance's default_timezone is set to Europe/Amsterdam (and briefly to
 * America/New_York) for the run and restored afterwards, also on failure.
 *
 * Usage, with the app checked out or copied into the apps directory:
 *   sudo -u www-data php apps/roomvox/tests/integration-timezone.php
 * In a Docker setup, copy it into the container's custom_apps/roomvox/tests/
 * first and run it there as www-data.
 */

define('OC_CONSOLE', 1);
require_once __DIR__ . '/../../../lib/base.php';
// Without the apps loaded, RoomVox' user backend is not registered and room
// calendars cannot be resolved ("Principal not found").
\OC_App::loadApps();

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\ApiTokenService;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\RoomService;
use OCP\IConfig;

// ── Helpers ─────────────────────────────────────────────────────

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
        : fail($label, 'expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
}

function section(string $title): void {
    echo "\n── {$title}\n";
}

// ── Services ────────────────────────────────────────────────────

$rooms = \OC::$server->get(RoomService::class);
$caldav = \OC::$server->get(CalDAVService::class);
$tokens = \OC::$server->get(ApiTokenService::class);
$backend = \OC::$server->get(CalDavBackend::class);
$config = \OC::$server->get(IConfig::class);
$mail = \OC::$server->get(MailService::class);

$call = fn(string $method, ...$args) => (new \ReflectionMethod($mail, $method))->invoke($mail, ...$args);

$originalTimezone = $config->getSystemValue('default_timezone', null);
$room = null;
$tokenId = null;
$calId = null;

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
    $config = \OC::$server->get(IConfig::class);
    $host = $config->getSystemValue('trusted_domains', ['localhost'])[0];
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

function ics(string $uid, string $times, string $roomEmail, string $extra = ''): string {
    $vtimezone = str_contains($times, 'TZID=Europe/London')
        ? "BEGIN:VTIMEZONE\r\nTZID:Europe/London\r\nBEGIN:DAYLIGHT\r\nTZOFFSETFROM:+0000\r\nTZOFFSETTO:+0100\r\nDTSTART:19700329T010000\r\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\nEND:DAYLIGHT\r\nBEGIN:STANDARD\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0000\r\nDTSTART:19701025T020000\r\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n"
        : (str_contains($times, 'TZID=Europe/Amsterdam')
            ? "BEGIN:VTIMEZONE\r\nTZID:Europe/Amsterdam\r\nBEGIN:DAYLIGHT\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\nDTSTART:19700329T020000\r\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\nEND:DAYLIGHT\r\nBEGIN:STANDARD\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nDTSTART:19701025T030000\r\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n"
            : '');
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//RoomVox integration test//EN\r\n{$vtimezone}BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:20261001T120000Z\r\n{$times}\r\n{$extra}SUMMARY:{$uid}\r\nORGANIZER:mailto:test@example.com\r\nATTENDEE;CUTYPE=ROOM;PARTSTAT=ACCEPTED:mailto:{$roomEmail}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
}

function slots(?array $availability): array {
    return array_map(
        fn(array $s) => $s['start'] . '–' . $s['end'] . ' ' . $s['status'] . (isset($s['title']) ? " ({$s['title']})" : ''),
        $availability['slots'] ?? [],
    );
}

function storedTimes(int $calId, string $uri): string {
    $data = \OC::$server->get(CalDavBackend::class)->getCalendarObject($calId, $uri)['calendardata'] ?? '';
    preg_match('/^DTSTART[^\r\n]*/m', $data, $start);
    preg_match('/^DTEND[^\r\n]*/m', $data, $end);
    return ($start[0] ?? '?') . ' ' . ($end[0] ?? '?');
}

echo "RoomVox timezone integration test (#45, #49)\n";
echo "Nextcloud " . \OCP\Server::get(\OCP\ServerVersion::class)->getVersionString() . ", default_timezone was " . json_encode($originalTimezone) . "\n";

try {
    $config->setSystemValue('default_timezone', 'Europe/Amsterdam');

    $room = $rooms->createRoom([
        'name' => 'zz-integration-timezone-' . date('His'),
        'autoAccept' => true,
        'availabilityRules' => ['enabled' => true, 'rules' => [['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00']]],
    ]);
    $rooms->setCalendarUri($room['id'], $caldav->provisionCalendar($room['userId']));
    $calId = $caldav->getRoomCalendarId($room['userId']);
    $token = $tokens->createToken('zz-integration-timezone', 'book', [$room['id']]);
    $tokenId = $token['id'];
    $bearer = $token['token'];
    settle();
    $path = '/rooms/' . $room['id'];
    echo "Temporary room {$room['id']}, booking hours Mon–Fri 08:00–18:00, instance Europe/Amsterdam\n";

    // ── 1. Storage and booking hours (#45) ──────────────────────
    section('1. Public API: storage and booking hours (#45)');

    [$status, $body] = api('POST', "$path/bookings", $bearer, ['title' => 'A', 'start' => '2026-10-05T09:00:00+02:00', 'end' => '2026-10-05T10:00:00+02:00']);
    assert_same(201, $status, 'Mon 09:00+02:00 is accepted');
    $uidA = $body['uid'] ?? '';
    assert_same('2026-10-05T09:00:00+02:00', $body['start'] ?? null, 'response echoes the requested start');
    assert_same('DTSTART:20261005T070000Z DTEND:20261005T080000Z', storedTimes($calId, "$uidA.ics"), 'stored as the same instant in UTC (was 090000Z)');

    [$status] = api('POST', "$path/bookings", $bearer, ['title' => 'B', 'start' => '2026-10-05T07:00:00Z', 'end' => '2026-10-05T08:00:00Z']);
    assert_same(409, $status, 'the same instant sent as Z conflicts with it');

    [$status] = api('POST', "$path/bookings", $bearer, ['title' => 'C', 'start' => '2026-10-05T06:30:00Z', 'end' => '2026-10-05T07:00:00Z']);
    assert_same(201, $status, '08:30 local sent as 06:30Z is inside booking hours (was 422)');

    [$status] = api('POST', "$path/bookings", $bearer, ['title' => 'D', 'start' => '2026-10-05T17:00:00Z', 'end' => '2026-10-05T17:30:00Z']);
    assert_same(422, $status, '19:00 local sent as 17:00Z is outside booking hours (was let through)');

    [$status] = api('POST', "$path/bookings", $bearer, ['title' => 'E', 'start' => '2026-10-04T10:00:00+02:00', 'end' => '2026-10-04T11:00:00+02:00']);
    assert_same(422, $status, 'a Sunday is outside booking hours');

    // ── 2. /availability in local time ──────────────────────────
    section('2. /availability in local time');

    $backend->createCalendarObject($calId, 'tzid-1100.ics', ics('tzid-1100', "DTSTART;TZID=Europe/Amsterdam:20261005T110000\r\nDTEND;TZID=Europe/Amsterdam:20261005T114500", $room['email']));
    $backend->createCalendarObject($calId, 'floating-1200.ics', ics('floating-1200', "DTSTART:20261005T120000\r\nDTEND:20261005T130000", $room['email']));

    [$status, $body] = api('GET', "$path/availability?date=2026-10-05", $bearer);
    assert_same(200, $status, 'availability answers');
    assert_same([
        '08:00–08:30 free',
        '08:30–09:00 busy (C)',
        '09:00–10:00 busy (A)',
        '10:00–11:00 free',
        '11:00–11:45 busy (tzid-1100)',
        '11:45–12:00 free',
        '12:00–13:00 busy (floating-1200)',
        '13:00–18:00 free',
    ], slots($body), 'API, TZID and floating bookings all at their local time');

    [$status] = api('POST', "$path/bookings", $bearer, ['title' => 'F', 'start' => '2026-10-05T14:00:00Z', 'end' => '2026-10-05T15:00:00Z']);
    assert_same(201, $status, 'booking 14:00Z inside an advertised free slot is accepted');

    // Floating 07:30 reads as 07:30Z and a CalDAV 08:30+02:00 as 06:30Z, so
    // an instant sort puts them the wrong way round (review round 3).
    $backend->createCalendarObject($calId, 'floating-0730.ics', ics('floating-0730', "DTSTART:20261006T073000\r\nDTEND:20261006T083000", $room['email']));
    $backend->createCalendarObject($calId, 'tzid-0830.ics', ics('tzid-0830', "DTSTART;TZID=Europe/Amsterdam:20261006T083000\r\nDTEND;TZID=Europe/Amsterdam:20261006T093000", $room['email']));
    [, $body] = api('GET', "$path/availability?date=2026-10-06", $bearer);
    assert_same([
        '08:00–08:30 busy (floating-0730)',
        '08:30–09:30 busy (tzid-0830)',
        '09:30–18:00 free',
    ], slots($body), 'slots follow local order when floating and TZID bookings mix');

    $order = array_column($caldav->getBookings($room['userId'], '2026-10-05T00:00:00Z', '2026-10-06T00:00:00Z'), 'summary');
    assert_same(['C', 'A', 'tzid-1100', 'floating-1200', 'F'], $order, 'getBookings() is in time order despite mixed offsets');

    // ── 3. Mail times (#49) ─────────────────────────────────────
    section('3. Mail times (#49)');

    $backend->createCalendarObject($calId, 'allday-1.ics', ics('allday-1', "DTSTART;VALUE=DATE:20261007\r\nDTEND;VALUE=DATE:20261008", $room['email']));
    $backend->createCalendarObject($calId, 'allday-3.ics', ics('allday-3', "DTSTART;VALUE=DATE:20261012\r\nDTEND;VALUE=DATE:20261015", $room['email']));
    $backend->createCalendarObject($calId, 'tzid-london.ics', ics('tzid-london', "DTSTART;TZID=Europe/London:20261008T090000\r\nDTEND;TZID=Europe/London:20261008T100000", $room['email']));

    $expected = [
        'Europe/Amsterdam' => [
            $uidA => 'Date: Monday, October 5, 2026 09:00 – 10:00 (Europe/Amsterdam)',
            'floating-1200' => 'Date: Monday, October 5, 2026 12:00 – 13:00',
            'tzid-london' => 'Date: Thursday, October 8, 2026 10:00 – 11:00 (Europe/Amsterdam)',
            'allday-1' => 'Date: Wednesday, October 7, 2026',
            'allday-3' => 'Date: Monday, October 12, 2026 – Wednesday, October 14, 2026',
        ],
        'America/New_York' => [
            $uidA => 'Date: Monday, October 5, 2026 03:00 – 04:00 (America/New_York)',
            'floating-1200' => 'Date: Monday, October 5, 2026 12:00 – 13:00',
            'tzid-london' => 'Date: Thursday, October 8, 2026 04:00 – 05:00 (America/New_York)',
            'allday-1' => 'Date: Wednesday, October 7, 2026',
            'allday-3' => 'Date: Monday, October 12, 2026 – Wednesday, October 14, 2026',
        ],
    ];

    $l = $call('getL10n', 'en');
    foreach ($expected as $tz => $cases) {
        $config->setSystemValue('default_timezone', $tz);
        foreach ($cases as $uid => $line) {
            $label = $uid === $uidA ? 'API booking 09:00+02:00' : $uid;

            // Respond flow: approve/decline/cancel from the RoomVox UI.
            $info = $call('bookingDataToEventInfo', $caldav->getBookingByUid($room['userId'], $uid));
            assert_same($line, explode("\n", $call('buildEventBlock', $l, $room, $info))[2], "[$tz] respond mail, $label");

            // iTIP flow: the same event as Sabre parses it from CalDAV.
            $message = new \Sabre\VObject\ITip\Message();
            $message->message = \Sabre\VObject\Reader::read($backend->getCalendarObject($calId, "$uid.ics")['calendardata']);
            $info = $call('extractEventInfo', $message);
            assert_same($line, explode("\n", $call('buildEventBlock', $l, $room, $info))[2], "[$tz] iTIP mail, $label");
        }
    }

    // ── 4. Cancelled occurrence of an all-day series ────────────
    section('4. Cancelled occurrence of an all-day series');

    $backend->createCalendarObject($calId, 'series-allday.ics', ics('series-allday', "DTSTART;VALUE=DATE:20261019\r\nDTEND;VALUE=DATE:20261020", $room['email'], "RRULE:FREQ=WEEKLY;COUNT=2\r\n"));
    $series = $caldav->getBookingByUid($room['userId'], 'series-allday');
    $occurrences = array_values(array_filter(
        $caldav->getBookings($room['userId'], '2026-10-15T00:00:00Z', '2026-11-15T00:00:00Z'),
        fn(array $b) => $b['uid'] === 'series-allday' && $b['recurrenceId'] !== null,
    ));
    assert_same(['2026-10-19T00:00:00+00:00', '2026-10-26T00:00:00+00:00'], array_column($occurrences, 'recurrenceId'), 'recurrence ids carry +00:00 (why the string cannot be trusted)');
    foreach (['Europe/Amsterdam', 'America/New_York'] as $tz) {
        $config->setSystemValue('default_timezone', $tz);
        assert_same(
            ['Monday, October 19, 2026', 'Monday, October 26, 2026'],
            array_map(fn(array $b) => $call('formatOccurrence', $b['recurrenceId'], $series), $occurrences),
            "[$tz] cancelled occurrences keep their date",
        );
    }
} catch (\Throwable $e) {
    fail('unexpected ' . get_class($e), $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    section('Cleanup');
    try {
        if ($calId !== null) {
            foreach ($backend->getCalendarObjects($calId) as $object) {
                $backend->deleteCalendarObject($calId, $object['uri']);
            }
        }
        if ($tokenId !== null) {
            $tokens->deleteToken($tokenId);
        }
        if ($room !== null) {
            // As the admin interface deletes a room: calendar first, then
            // Nextcloud's room sync, which removes the room calendar.
            $caldav->deleteCalendar($room['userId']);
            $rooms->deleteRoom($room['id']);
            \OC::$server->get(\OCP\Calendar\Room\IManager::class)->update();
        }
        echo "  removed room, token and bookings\n";
    } finally {
        $originalTimezone === null
            ? $config->deleteSystemValue('default_timezone')
            : $config->setSystemValue('default_timezone', $originalTimezone);
        echo '  default_timezone restored to ' . json_encode($originalTimezone) . "\n";
    }
}

echo "\n" . str_repeat('═', 60) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
foreach ($errors as $error) {
    echo "  ❌ {$error}\n";
}
exit($failed > 0 ? 1 : 0);
