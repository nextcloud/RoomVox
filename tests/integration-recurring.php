#!/usr/bin/env php
<?php
/**
 * RoomVox Recurring Booking Integration Test — issue #46
 *
 * Sends real iCalendar requests, parsed by Sabre, through the scheduling
 * plugin as Nextcloud builds it, so Sabre's own recurrence expansion is used
 * rather than a test stub. A room gets one existing booking; recurring
 * requests that collide with it on a later date must be declined, and the
 * same series with that date excluded (EXDATE) or moved (RECURRENCE-ID) must
 * be accepted. Also covers a series that began in the past and one without an
 * end.
 *
 * Everything it creates is removed afterwards, also on failure. Decline mails
 * go to an address under the reserved .invalid domain.
 *
 * Usage, with the app checked out or copied into the apps directory:
 *   sudo -u www-data php apps/roomvox/tests/integration-recurring.php
 * In a Docker setup, copy it into the container's custom_apps/roomvox/tests/
 * first and run it there as www-data.
 */

define('OC_CONSOLE', 1);
require_once __DIR__ . '/../../../lib/base.php';
\OC_App::loadApps();

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Dav\SchedulingPlugin;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\RoomService;
use Sabre\VObject\ITip\Message;
use Sabre\VObject\Reader;

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

$rooms = \OC::$server->get(RoomService::class);
$caldav = \OC::$server->get(CalDAVService::class);
$backend = \OC::$server->get(CalDavBackend::class);
$plugin = \OC::$server->get(SchedulingPlugin::class);

/** Monday of next week plus $weeks weeks, at $time UTC, as an iCalendar UTC value */
$monday = fn(int $weeks, string $time = '080000') =>
    (new \DateTimeImmutable('monday next week', new \DateTimeZone('UTC')))->modify(($weeks >= 0 ? '+' : '') . $weeks . ' weeks')->format('Ymd') . 'T' . $time . 'Z';

/**
 * Send one REQUEST for a series to the room and report the schedule status
 * and the room's PARTSTAT in the answer.
 */
$request = function (array $room, string $uid, string $start, string $end, string $extra) use ($plugin): array {
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//RoomVox integration test//EN\r\nMETHOD:REQUEST\r\n"
        . "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:20261001T120000Z\r\nDTSTART:{$start}\r\nDTEND:{$end}\r\n{$extra}"
        . "SUMMARY:{$uid}\r\nORGANIZER;CN=Test:mailto:zz-organizer@example.invalid\r\n"
        . "ATTENDEE;CUTYPE=ROOM;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:{$room['email']}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    $message = new Message();
    $message->method = 'REQUEST';
    $message->component = 'VEVENT';
    $message->uid = $uid;
    $message->sender = 'mailto:zz-organizer@example.invalid';
    $message->recipient = 'mailto:' . $room['email'];
    $message->message = Reader::read($ics);

    $plugin->handleScheduleRequest($message);

    $partstat = null;
    foreach ($message->message->VEVENT->select('ATTENDEE') as $attendee) {
        if (strcasecmp((string)$attendee, 'mailto:' . $room['email']) === 0) {
            $partstat = isset($attendee['PARTSTAT']) ? (string)$attendee['PARTSTAT'] : null;
        }
    }

    return [(string)$message->scheduleStatus, $partstat];
};

$room = null;
echo "RoomVox recurring booking integration test (#46)\n";

try {
    $room = $rooms->createRoomWithCalendar(['name' => 'zz-recurring-' . date('His'), 'autoAccept' => true], $caldav);
    $calId = $caldav->getRoomCalendarId($room['userId']);
    $objects = fn() => count($backend->getCalendarObjects($calId));

    // The existing booking: the third Monday, 08:00-09:00 UTC.
    $caldav->createBooking($room['userId'], [
        'summary' => 'Existing',
        'start' => new \DateTime($monday(2)),
        'end' => new \DateTime($monday(2, '090000')),
        'autoAccept' => true,
    ]);
    echo "Room {$room['id']}, existing booking on the third Monday 08:00-09:00 UTC\n\n";

    [$status, $partstat] = $request($room, 'zz-rec-plain', $monday(0), $monday(0, '090000'), "RRULE:FREQ=WEEKLY;COUNT=4\r\n");
    assert_same(['3.0', 'DECLINED'], [$status, $partstat], 'a weekly series colliding on its third date is declined (was accepted)');
    assert_same(1, $objects(), 'and nothing was stored');

    [$status, $partstat] = $request($room, 'zz-rec-exdate', $monday(0), $monday(0, '090000'), "RRULE:FREQ=WEEKLY;COUNT=4\r\nEXDATE:{$monday(2)}\r\n");
    assert_same(['1.2', 'ACCEPTED'], [$status, $partstat], 'the same series with that date excluded (EXDATE) is accepted');

    $override = "END:VEVENT\r\nBEGIN:VEVENT\r\nUID:zz-rec-override\r\nDTSTAMP:20261001T120000Z\r\nRECURRENCE-ID:{$monday(2)}\r\n"
        . "DTSTART:{$monday(2, '140000')}\r\nDTEND:{$monday(2, '150000')}\r\nSUMMARY:moved\r\n";
    [$status] = $request($room, 'zz-rec-override', $monday(0, '080000'), $monday(0, '090000'), "RRULE:FREQ=WEEKLY;COUNT=4\r\nEXDATE:{$monday(0)}\r\nEXDATE:{$monday(1)}\r\nEXDATE:{$monday(3)}\r\n{$override}");
    // The override moves the only remaining date away from the existing
    // booking; the EXDATEs keep this request clear of the EXDATE series above.
    assert_same('1.2', $status, 'a series whose colliding date is moved (RECURRENCE-ID) is accepted');

    [$status] = $request($room, 'zz-rec-past', $monday(-60, '160000'), $monday(-60, '170000'), "RRULE:FREQ=WEEKLY;COUNT=70\r\n");
    assert_same('1.2', $status, 'a series that began 60 weeks ago is checked from today and accepted');

    [$status] = $request($room, 'zz-rec-past-clash', $monday(-60), $monday(-60, '090000'), "RRULE:FREQ=WEEKLY;COUNT=70\r\n");
    assert_same('3.0', $status, 'the same old series is still declined for a clash ahead');

    [$status] = $request($room, 'zz-rec-open', $monday(0, '120000'), $monday(0, '130000'), "RRULE:FREQ=WEEKLY\r\n");
    assert_same('1.2', $status, 'a series without an end and without clashes is accepted');

    [$status] = $request($room, 'zz-rec-open-clash', $monday(0), $monday(0, '090000'), "RRULE:FREQ=WEEKLY\r\n");
    assert_same('3.0', $status, 'a series without an end that clashes is declined');
} catch (\Throwable $e) {
    fail('unexpected ' . get_class($e), $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    echo "\n── Cleanup\n";
    if ($room !== null) {
        $calId = $caldav->getRoomCalendarId($room['userId']);
        if ($calId !== null) {
            foreach ($backend->getCalendarObjects($calId) as $object) {
                $backend->deleteCalendarObject($calId, $object['uri'], CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
            }
        }
        $caldav->deleteCalendar($room['userId']);
        $rooms->deleteRoom($room['id']);
        // Nextcloud's room sync removes the room calendar, as after a delete
        // in the admin interface.
        \OC::$server->get(\OCP\Calendar\Room\IManager::class)->update();
        echo "  removed room {$room['id']}, its bookings and calendar\n";
    }
}

echo "\n" . str_repeat('═', 60) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
foreach ($errors as $error) {
    echo "  ❌ {$error}\n";
}
exit($failed > 0 ? 1 : 0);
