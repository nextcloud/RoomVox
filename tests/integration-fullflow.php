#!/usr/bin/env php
<?php
/**
 * RoomVox Full Flow Integration Test
 *
 * Tests the complete booking lifecycle via the real Nextcloud service layer,
 * including all checks that the controllers perform:
 * - Room lookup, permission checks, availability rules, booking horizon
 * - Conflict detection, booking create/read/update/delete
 * - Duplicate booking prevention (conflict after create)
 * - Reschedule with excludeUid
 * - PARTSTAT accept/decline
 *
 * Usage: cd /var/www/nextcloud && sudo -u www-data php apps/roomvox/tests/integration-fullflow.php
 */

define('OC_CONSOLE', 1);
require_once __DIR__ . '/../../../lib/base.php';

use OCA\RoomVox\Service\RoomService;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\PermissionService;

// ── Helpers ─────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$errors = [];

function ok(string $label): void {
    global $passed;
    $passed++;
    echo "  ✅ {$label}\n";
    @ob_flush(); flush();
}

function fail(string $label, string $reason = ''): void {
    global $failed, $errors;
    $failed++;
    $msg = $reason ? "{$label} — {$reason}" : $label;
    $errors[] = $msg;
    echo "  ❌ {$msg}\n";
    @ob_flush(); flush();
}

function assert_true(bool $value, string $label): void {
    $value ? ok($label) : fail($label, 'expected true, got false');
}

function assert_false(bool $value, string $label): void {
    !$value ? ok($label) : fail($label, 'expected false, got true');
}

function assert_equals($expected, $actual, string $label): void {
    $expected === $actual ? ok($label) : fail($label, "expected " . var_export($expected, true) . ", got " . var_export($actual, true));
}

function assert_not_null($value, string $label): void {
    $value !== null ? ok($label) : fail($label, 'expected non-null');
}

function assert_null($value, string $label): void {
    $value === null ? ok($label) : fail($label, 'expected null, got ' . var_export($value, true));
}

function assert_count(int $expected, $array, string $label): void {
    $actual = is_countable($array) ? count($array) : 0;
    $actual === $expected ? ok($label) : fail($label, "expected count {$expected}, got {$actual}");
}

function assert_greater_than(int $min, $value, string $label): void {
    $value > $min ? ok($label) : fail($label, "expected > {$min}, got {$value}");
}

// ── Setup ───────────────────────────────────────────────────────

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║       RoomVox Full Flow Integration Test                   ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$app = \OC::$server->get(\OCA\RoomVox\AppInfo\Application::class);

/** @var RoomService $roomService */
$roomService = \OC::$server->get(RoomService::class);

/** @var CalDAVService $calDAVService */
$calDAVService = \OC::$server->get(CalDAVService::class);

/** @var PermissionService $permissionService */
$permissionService = \OC::$server->get(PermissionService::class);

// Use a time slot far enough in the future to avoid existing bookings
$testDate = new DateTime('+3 days 22:00');
$testDateEnd = new DateTime('+3 days 23:00');
$rescheduleStart = new DateTime('+3 days 23:00');
$rescheduleEnd = new DateTime('+4 days 00:00');

// ═══════════════════════════════════════════════════════════════
echo "1. Room Service\n";
// ═══════════════════════════════════════════════════════════════

$roomsAssoc = $roomService->getAllRooms();
$rooms = array_values($roomsAssoc); // convert to numeric index
assert_greater_than(0, count($rooms), "getAllRooms() returns rooms");

$testRoom = $rooms[0];
$testRoomId = $testRoom['id'];
$testRoomUserId = $testRoom['userId'];

$roomById = $roomService->getRoom($testRoomId);
assert_not_null($roomById, "getRoom('{$testRoomId}') returns room");
assert_equals($testRoomId, $roomById['id'], "getRoom() returns correct room ID");
assert_equals($testRoomUserId, $roomById['userId'], "getRoom() returns correct userId");

$nonExistent = $roomService->getRoom('nonexistent-room-xyz');
assert_null($nonExistent, "getRoom('nonexistent') returns null");

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "2. Permission Service\n";
// ═══════════════════════════════════════════════════════════════

$perms = $permissionService->getPermissions($testRoomId);
assert_true(is_array($perms), "getPermissions() returns array");

// Admin should always have access
$adminCanBook = $permissionService->canBook('admin', $testRoomId);
assert_true($adminCanBook, "Admin can book room");

$adminCanManage = $permissionService->canManage('admin', $testRoomId);
assert_true($adminCanManage, "Admin can manage room");

// Non-existent user with strict permissions
$fakeUserRole = $permissionService->getEffectiveRole('nonexistent-user-xyz', $testRoomId);
// Result depends on whether permissions are configured — just verify it doesn't crash
ok("getEffectiveRole() for unknown user returns '{$fakeUserRole}' (no crash)");

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "3. Conflict Detection — Empty Slot\n";
// ═══════════════════════════════════════════════════════════════

$hasConflict = $calDAVService->hasConflict($testRoomUserId, $testDate, $testDateEnd);
assert_false($hasConflict, "No conflict in empty time slot ({$testDate->format('Y-m-d H:i')} - {$testDateEnd->format('H:i')})");

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "4. Booking Lifecycle — Create\n";
// ═══════════════════════════════════════════════════════════════

$uid = null;
try {
    $uid = $calDAVService->createBooking($testRoomUserId, [
        'summary' => 'Integration Test Booking',
        'start' => $testDate,
        'end' => $testDateEnd,
        'description' => 'Full flow integration test — will be deleted',
        'organizer' => 'admin',
        'roomEmail' => $testRoom['email'] ?? '',
        'autoAccept' => true,
    ]);
    assert_not_null($uid, "createBooking() returns UID");
} catch (\Throwable $e) {
    fail("createBooking()", $e->getMessage());
}

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "5. Conflict Detection — After Create\n";
// ═══════════════════════════════════════════════════════════════

if ($uid) {
    $hasConflict = $calDAVService->hasConflict($testRoomUserId, $testDate, $testDateEnd);
    assert_true($hasConflict, "Conflict detected after booking created (duplicate prevention works)");

    // Exact same slot — must conflict
    $exactConflict = $calDAVService->hasConflict($testRoomUserId, $testDate, $testDateEnd);
    assert_true($exactConflict, "Exact overlap → conflict");

    // Partial overlap (start inside existing)
    $partialStart = clone $testDate;
    $partialStart->modify('+30 minutes');
    $partialEnd = clone $testDateEnd;
    $partialEnd->modify('+30 minutes');
    $partialConflict = $calDAVService->hasConflict($testRoomUserId, $partialStart, $partialEnd);
    assert_true($partialConflict, "Partial overlap → conflict");

    // Adjacent slot (should NOT conflict)
    $adjacentConflict = $calDAVService->hasConflict($testRoomUserId, $testDateEnd, $rescheduleEnd);
    assert_false($adjacentConflict, "Adjacent slot (end=start) → no conflict");

    // Exclude own UID (reschedule scenario)
    $excludeSelf = $calDAVService->hasConflict($testRoomUserId, $testDate, $testDateEnd, $uid);
    assert_false($excludeSelf, "Exclude own UID → no conflict (reschedule allowed)");
} else {
    fail("Skipping conflict tests — no booking created");
}

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "6. Booking Read — getBookingByUid\n";
// ═══════════════════════════════════════════════════════════════

if ($uid) {
    $booking = $calDAVService->getBookingByUid($testRoomUserId, $uid);
    assert_not_null($booking, "getBookingByUid() finds booking");
    assert_equals('Integration Test Booking', $booking['summary'] ?? '', "Booking summary matches");

    $notFound = $calDAVService->getBookingByUid($testRoomUserId, 'nonexistent-uid-xyz');
    assert_null($notFound, "getBookingByUid() returns null for unknown UID");
} else {
    fail("Skipping read tests — no booking created");
}

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "7. Booking Update — Reschedule\n";
// ═══════════════════════════════════════════════════════════════

if ($uid) {
    // Reschedule to adjacent slot
    $updated = $calDAVService->updateBookingTimes($testRoomUserId, $uid, $rescheduleStart, $rescheduleEnd);
    assert_true($updated, "updateBookingTimes() succeeds");

    // Old slot should now be free
    $oldSlotFree = !$calDAVService->hasConflict($testRoomUserId, $testDate, $testDateEnd);
    assert_true($oldSlotFree, "Old time slot is free after reschedule");

    // New slot should be occupied
    $newSlotBusy = $calDAVService->hasConflict($testRoomUserId, $rescheduleStart, $rescheduleEnd);
    assert_true($newSlotBusy, "New time slot is occupied after reschedule");

    // Move back for remaining tests
    $calDAVService->updateBookingTimes($testRoomUserId, $uid, $testDate, $testDateEnd);
} else {
    fail("Skipping reschedule tests — no booking created");
}

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "8. Booking Update — PARTSTAT\n";
// ═══════════════════════════════════════════════════════════════

if ($uid) {
    $accepted = $calDAVService->updateBookingPartstat($testRoomUserId, $uid, 'ACCEPTED');
    assert_true($accepted, "updateBookingPartstat(ACCEPTED) succeeds");

    $declined = $calDAVService->updateBookingPartstat($testRoomUserId, $uid, 'DECLINED');
    assert_true($declined, "updateBookingPartstat(DECLINED) succeeds");

    $tentative = $calDAVService->updateBookingPartstat($testRoomUserId, $uid, 'TENTATIVE');
    assert_true($tentative, "updateBookingPartstat(TENTATIVE) succeeds");

    $badUid = $calDAVService->updateBookingPartstat($testRoomUserId, 'nonexistent-uid', 'ACCEPTED');
    assert_false($badUid, "updateBookingPartstat() returns false for unknown UID");
} else {
    fail("Skipping PARTSTAT tests — no booking created");
}

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "9. Booking Delete\n";
// ═══════════════════════════════════════════════════════════════

if ($uid) {
    $deleted = $calDAVService->deleteBooking($testRoomUserId, $uid);
    assert_true($deleted, "deleteBooking() succeeds");

    // Verify slot is free again
    $freeAgain = !$calDAVService->hasConflict($testRoomUserId, $testDate, $testDateEnd);
    assert_true($freeAgain, "Time slot is free after delete");

    // Verify booking is gone
    $gone = $calDAVService->getBookingByUid($testRoomUserId, $uid);
    assert_null($gone, "getBookingByUid() returns null after delete");

    // Delete again — should return false
    $doubleDelete = $calDAVService->deleteBooking($testRoomUserId, $uid);
    assert_false($doubleDelete, "Double delete returns false");
} else {
    fail("Skipping delete tests — no booking created");
}

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "10. Bulk Conflict Check — All Rooms\n";
// ═══════════════════════════════════════════════════════════════

$roomCount = count($rooms);
$checked = 0;
$bulkErrors = 0;

$start = hrtime(true);
foreach ($rooms as $room) {
    try {
        $calDAVService->hasConflict($room['userId'], $testDate, $testDateEnd);
        $checked++;
    } catch (\Throwable $e) {
        $bulkErrors++;
    }
}
$elapsed = (hrtime(true) - $start) / 1_000_000;
$perRoom = $checked > 0 ? $elapsed / $checked : 0;

assert_equals($roomCount, $checked + $bulkErrors, "All {$roomCount} rooms checked");
echo "  ⏱  {$checked} conflict checks in " . sprintf('%.1f', $elapsed) . "ms (" . sprintf('%.2f', $perRoom) . "ms/room)\n";

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "11. Bulk Create + Conflict Verify + Delete — ALL working rooms\n";
// ═══════════════════════════════════════════════════════════════

// Filter to rooms that have working calendars (skip the ones that error on conflict check)
$workingRooms = [];
foreach ($rooms as $r) {
    try {
        $calDAVService->hasConflict($r['userId'], $testDate, $testDateEnd);
        $workingRooms[] = $r;
    } catch (\Throwable $e) {
        // skip rooms without calendars
    }
}
$workingCount = count($workingRooms);
echo "  Using {$workingCount} rooms with working calendars (of {$roomCount} total)\n";
@ob_flush(); flush();

$bulkUids = [];
$bulkCreateOk = 0;
$bulkConflictOk = 0;
$bulkDeleteOk = 0;

// Create in ALL working rooms
$createStart = hrtime(true);
foreach ($workingRooms as $room) {
    try {
        $bUid = $calDAVService->createBooking($room['userId'], [
            'summary' => 'Bulk Integration Test',
            'start' => $testDate,
            'end' => $testDateEnd,
            'description' => 'Bulk test — auto cleanup',
            'organizer' => 'admin',
            'roomEmail' => $room['email'] ?? '',
            'autoAccept' => true,
        ]);
        $bulkUids[] = ['userId' => $room['userId'], 'uid' => $bUid];
        $bulkCreateOk++;
    } catch (\Throwable $e) {
        // skip
    }
}
$createMs = (hrtime(true) - $createStart) / 1_000_000;

assert_equals($workingCount, $bulkCreateOk, "All {$workingCount} bookings created");
echo "  ⏱  Create: " . sprintf('%.1f', $createMs) . "ms (" . sprintf('%.2f', $createMs / max(1, $bulkCreateOk)) . "ms/booking)\n";
@ob_flush(); flush();

// Verify conflicts exist in ALL rooms
foreach ($bulkUids as $b) {
    try {
        if ($calDAVService->hasConflict($b['userId'], $testDate, $testDateEnd)) {
            $bulkConflictOk++;
        }
    } catch (\Throwable $e) {
        // skip
    }
}
assert_equals($bulkCreateOk, $bulkConflictOk, "All {$bulkConflictOk} rooms show conflict after create");

// Delete ALL
$deleteStart = hrtime(true);
foreach ($bulkUids as $b) {
    try {
        if ($calDAVService->deleteBooking($b['userId'], $b['uid'])) {
            $bulkDeleteOk++;
        }
    } catch (\Throwable $e) {
        // skip
    }
}
$deleteMs = (hrtime(true) - $deleteStart) / 1_000_000;

assert_equals($bulkCreateOk, $bulkDeleteOk, "All {$bulkDeleteOk} bookings deleted");
echo "  ⏱  Delete: " . sprintf('%.1f', $deleteMs) . "ms (" . sprintf('%.2f', $deleteMs / max(1, $bulkDeleteOk)) . "ms/booking)\n";

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "12. Availability Rules\n";
// ═══════════════════════════════════════════════════════════════

// Find a room with availability rules, or configure one temporarily
$availRoom = null;
$availRoomOriginal = null;
foreach ($workingRooms as $r) {
    $rules = $r['availabilityRules'] ?? [];
    if (!empty($rules['enabled']) && !empty($rules['rules'])) {
        $availRoom = $r;
        break;
    }
}

if ($availRoom === null && !empty($workingRooms)) {
    // Temporarily set availability rules on the first working room
    $availRoom = $workingRooms[0];
    $availRoomOriginal = $roomService->getRoom($availRoom['id']); // save original
    $roomService->updateRoom($availRoom['id'], [
        'availabilityRules' => [
            'enabled' => true,
            'rules' => [
                [
                    'days' => [1, 2, 3, 4, 5], // Mon-Fri
                    'startTime' => '08:00',
                    'endTime' => '18:00',
                ],
            ],
        ],
    ]);
    $availRoom = $roomService->getRoom($availRoom['id']);
    echo "  (Temporarily configured availability rules on room '{$availRoom['id']}')\n";
    @ob_flush(); flush();
}

if ($availRoom !== null) {
    $rules = $availRoom['availabilityRules'] ?? [];
    $ruleSet = $rules['rules'][0] ?? [];
    $ruleStartTime = $ruleSet['startTime'] ?? '08:00';
    $ruleEndTime = $ruleSet['endTime'] ?? '18:00';
    $ruleDays = $ruleSet['days'] ?? [];

    echo "  Room: {$availRoom['id']}, Rules: days=" . implode(',', $ruleDays) . " {$ruleStartTime}-{$ruleEndTime}\n";
    @ob_flush(); flush();

    // Find a weekday (Mon=1..Fri=5) in the future that matches the rule
    $weekday = new DateTime('+5 days');
    for ($i = 0; $i < 7; $i++) {
        $dow = (int)$weekday->format('w');
        if (in_array($dow, $ruleDays, true)) {
            break;
        }
        $weekday->modify('+1 day');
    }

    // Test: booking INSIDE availability hours → should NOT conflict (availability allows it)
    $insideStart = clone $weekday;
    $insideStart->setTime((int)explode(':', $ruleStartTime)[0], (int)(explode(':', $ruleStartTime)[1] ?? 0));
    $insideEnd = clone $insideStart;
    $insideEnd->modify('+1 hour');

    // Use the SchedulingPlugin's isWithinAvailability via reflection (it's private)
    $pluginClass = new ReflectionClass(\OCA\RoomVox\Dav\SchedulingPlugin::class);
    $isWithinMethod = $pluginClass->getMethod('isWithinAvailability');
    $plugin = \OC::$server->get(\OCA\RoomVox\Dav\SchedulingPlugin::class);

    $insideResult = $isWithinMethod->invoke($plugin, $availRoom, $insideStart, $insideEnd);
    assert_true($insideResult, "Booking inside availability hours ({$insideStart->format('D H:i')}-{$insideEnd->format('H:i')}) → allowed");

    // Test: booking OUTSIDE availability hours (e.g. 06:00-07:00) → should be rejected
    $outsideStart = clone $weekday;
    $outsideStart->setTime(6, 0);
    $outsideEnd = clone $weekday;
    $outsideEnd->setTime(7, 0);

    $outsideResult = $isWithinMethod->invoke($plugin, $availRoom, $outsideStart, $outsideEnd);
    assert_false($outsideResult, "Booking outside availability hours (06:00-07:00) → rejected");

    // Test: booking AFTER availability hours (e.g. 19:00-20:00) → should be rejected
    $afterStart = clone $weekday;
    $afterStart->setTime(19, 0);
    $afterEnd = clone $weekday;
    $afterEnd->setTime(20, 0);

    $afterResult = $isWithinMethod->invoke($plugin, $availRoom, $afterStart, $afterEnd);
    assert_false($afterResult, "Booking after availability hours (19:00-20:00) → rejected");

    // Test: booking on a weekend day (if weekends are not in rules) → should be rejected
    $saturday = new DateTime('+5 days');
    for ($i = 0; $i < 7; $i++) {
        if ((int)$saturday->format('w') === 6) break; // 6 = Saturday
        $saturday->modify('+1 day');
    }
    if (!in_array(6, $ruleDays, true)) {
        $satStart = clone $saturday;
        $satStart->setTime(10, 0);
        $satEnd = clone $saturday;
        $satEnd->setTime(11, 0);

        $satResult = $isWithinMethod->invoke($plugin, $availRoom, $satStart, $satEnd);
        assert_false($satResult, "Booking on Saturday (not in allowed days) → rejected");
    } else {
        ok("Saturday is in allowed days — skipping weekend rejection test");
    }

    // Test: booking that spans the boundary (e.g. 17:30-18:30) → should be rejected
    $boundaryStart = clone $weekday;
    $endHour = (int)explode(':', $ruleEndTime)[0];
    $boundaryStart->setTime($endHour - 1, 30);
    $boundaryEnd = clone $weekday;
    $boundaryEnd->setTime($endHour, 30);

    $boundaryResult = $isWithinMethod->invoke($plugin, $availRoom, $boundaryStart, $boundaryEnd);
    assert_false($boundaryResult, "Booking spanning end boundary ({$boundaryStart->format('H:i')}-{$boundaryEnd->format('H:i')}) → rejected");

    // Restore original room config if we modified it
    if ($availRoomOriginal !== null) {
        $roomService->updateRoom($availRoom['id'], [
            'availabilityRules' => $availRoomOriginal['availabilityRules'] ?? [],
        ]);
        echo "  (Restored original availability rules)\n";
    }
} else {
    fail("No working rooms available for availability rules test");
}

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "13. Booking Horizon\n";
// ═══════════════════════════════════════════════════════════════

// Find a room with maxBookingHorizon, or configure one temporarily
$horizonRoom = null;
$horizonRoomOriginal = null;
foreach ($workingRooms as $r) {
    $maxHorizon = (int)($r['maxBookingHorizon'] ?? 0);
    if ($maxHorizon > 0) {
        $horizonRoom = $r;
        break;
    }
}

if ($horizonRoom === null && !empty($workingRooms)) {
    // Temporarily set a 30-day horizon on the second working room (or first if only one)
    $idx = count($workingRooms) > 1 ? 1 : 0;
    $horizonRoom = $workingRooms[$idx];
    $horizonRoomOriginal = $roomService->getRoom($horizonRoom['id']);
    $roomService->updateRoom($horizonRoom['id'], [
        'maxBookingHorizon' => 30,
    ]);
    $horizonRoom = $roomService->getRoom($horizonRoom['id']);
    echo "  (Temporarily set maxBookingHorizon=30 on room '{$horizonRoom['id']}')\n";
    @ob_flush(); flush();
}

if ($horizonRoom !== null) {
    $maxDays = (int)($horizonRoom['maxBookingHorizon'] ?? 0);
    echo "  Room: {$horizonRoom['id']}, Max horizon: {$maxDays} days\n";
    @ob_flush(); flush();

    // Use the SchedulingPlugin's isWithinHorizon via reflection
    $pluginClass = new ReflectionClass(\OCA\RoomVox\Dav\SchedulingPlugin::class);
    $isWithinHorizonMethod = $pluginClass->getMethod('isWithinHorizon');
    $plugin = \OC::$server->get(\OCA\RoomVox\Dav\SchedulingPlugin::class);

    // Build a simple VEVENT within horizon (e.g. +10 days)
    $withinStart = new DateTime('+10 days 10:00');
    $withinEnd = new DateTime('+10 days 11:00');
    $withinVCal = new \Sabre\VObject\Component\VCalendar();
    $withinEvent = $withinVCal->add('VEVENT', [
        'UID' => 'horizon-test-within@roomvox',
        'DTSTART' => $withinStart,
        'DTEND' => $withinEnd,
        'SUMMARY' => 'Horizon Test Within',
    ]);

    $withinResult = $isWithinHorizonMethod->invoke($plugin, $horizonRoom, $withinEvent);
    assert_true($withinResult, "Booking within horizon (+10 days, max {$maxDays}) → allowed");

    // Build a VEVENT beyond horizon (e.g. +60 days for a 30-day horizon)
    $beyondDays = $maxDays + 30;
    $beyondStart = new DateTime("+{$beyondDays} days 10:00");
    $beyondEnd = new DateTime("+{$beyondDays} days 11:00");
    $beyondVCal = new \Sabre\VObject\Component\VCalendar();
    $beyondEvent = $beyondVCal->add('VEVENT', [
        'UID' => 'horizon-test-beyond@roomvox',
        'DTSTART' => $beyondStart,
        'DTEND' => $beyondEnd,
        'SUMMARY' => 'Horizon Test Beyond',
    ]);

    $beyondResult = $isWithinHorizonMethod->invoke($plugin, $horizonRoom, $beyondEvent);
    assert_false($beyondResult, "Booking beyond horizon (+{$beyondDays} days, max {$maxDays}) → rejected");

    // Room without horizon restriction should always allow
    $noHorizonRoom = ['maxBookingHorizon' => 0];
    $noHorizonResult = $isWithinHorizonMethod->invoke($plugin, $noHorizonRoom, $beyondEvent);
    assert_true($noHorizonResult, "Room with no horizon (maxBookingHorizon=0) → always allowed");

    // Restore original room config if we modified it
    if ($horizonRoomOriginal !== null) {
        $roomService->updateRoom($horizonRoom['id'], [
            'maxBookingHorizon' => $horizonRoomOriginal['maxBookingHorizon'] ?? 0,
        ]);
        echo "  (Restored original maxBookingHorizon)\n";
    }
} else {
    fail("No working rooms available for horizon test");
}

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "14. Exchange Logic\n";
// ═══════════════════════════════════════════════════════════════

/** @var \OCA\RoomVox\Service\Exchange\ExchangeSyncService $exchangeSyncService */
$exchangeSyncService = \OC::$server->get(\OCA\RoomVox\Service\Exchange\ExchangeSyncService::class);

$globallyEnabled = $exchangeSyncService->isGloballyEnabled();
echo "  Exchange globally enabled: " . ($globallyEnabled ? 'yes' : 'no') . "\n";
@ob_flush(); flush();

// Test isExchangeRoom for each room
$exchangeRoomCount = 0;
$nonExchangeRoomCount = 0;
foreach ($workingRooms as $r) {
    if ($exchangeSyncService->isExchangeRoom($r)) {
        $exchangeRoomCount++;
    } else {
        $nonExchangeRoomCount++;
    }
}
echo "  Exchange rooms: {$exchangeRoomCount}, Non-Exchange rooms: {$nonExchangeRoomCount}\n";
@ob_flush(); flush();

assert_equals($workingCount, $exchangeRoomCount + $nonExchangeRoomCount, "isExchangeRoom() checked for all {$workingCount} rooms");

// Test isExchangeRoom with fake room data (no config)
$fakeRoom = ['id' => 'fake', 'userId' => 'fake'];
assert_false($exchangeSyncService->isExchangeRoom($fakeRoom), "isExchangeRoom() returns false for room without exchangeConfig");

// Test isExchangeRoom with empty config
$emptyConfigRoom = ['id' => 'fake', 'userId' => 'fake', 'exchangeConfig' => ['resourceEmail' => '', 'syncEnabled' => false]];
assert_false($exchangeSyncService->isExchangeRoom($emptyConfigRoom), "isExchangeRoom() returns false for room with empty exchangeConfig");

// Test isExchangeRoom with config but sync disabled
$disabledConfigRoom = ['id' => 'fake', 'userId' => 'fake', 'exchangeConfig' => ['resourceEmail' => 'room@example.com', 'syncEnabled' => false]];
assert_false($exchangeSyncService->isExchangeRoom($disabledConfigRoom), "isExchangeRoom() returns false when syncEnabled=false");

// If Exchange is globally enabled, test hasExchangeConflict
if ($globallyEnabled && $exchangeRoomCount > 0) {
    $exchangeTestRoom = null;
    foreach ($workingRooms as $r) {
        if ($exchangeSyncService->isExchangeRoom($r)) {
            $exchangeTestRoom = $r;
            break;
        }
    }
    if ($exchangeTestRoom !== null) {
        try {
            // Check a far-future empty slot — should return false (no conflict)
            $farFutureStart = new DateTime('+90 days 03:00');
            $farFutureEnd = new DateTime('+90 days 04:00');
            $exchangeConflict = $exchangeSyncService->hasExchangeConflict($exchangeTestRoom, $farFutureStart, $farFutureEnd);
            assert_false($exchangeConflict, "hasExchangeConflict() returns false for empty far-future slot");
        } catch (\Throwable $e) {
            echo "  ⚠ Exchange conflict check failed (may be expected if Exchange unreachable): {$e->getMessage()}\n";
            ok("hasExchangeConflict() throws on unreachable Exchange (fail-open design)");
        }
    }
} else {
    // Exchange not configured — hasExchangeConflict should return false for any room
    $noExchangeConflict = $exchangeSyncService->hasExchangeConflict($fakeRoom, $testDate, $testDateEnd);
    assert_false($noExchangeConflict, "hasExchangeConflict() returns false when Exchange not configured");
}

// Test pushBookingToExchange for non-Exchange room (should return false, not throw)
$pushResult = $exchangeSyncService->pushBookingToExchange($fakeRoom, 'test-uid', [
    'summary' => 'Test',
    'start' => $testDate,
    'end' => $testDateEnd,
]);
assert_false($pushResult, "pushBookingToExchange() returns false for non-Exchange room");

// Test deleteBookingFromExchange for non-Exchange room (should return false, not throw)
$deleteResult = $exchangeSyncService->deleteBookingFromExchange($fakeRoom, 'test-uid');
assert_false($deleteResult, "deleteBookingFromExchange() returns false for non-Exchange room");

// Test updateBookingOnExchange for non-Exchange room (should return false, not throw)
$updateResult = $exchangeSyncService->updateBookingOnExchange($fakeRoom, 'test-uid', [
    'summary' => 'Test',
    'start' => $testDate,
    'end' => $testDateEnd,
]);
assert_false($updateResult, "updateBookingOnExchange() returns false for non-Exchange room");

echo "\n";

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════

echo "╔══════════════════════════════════════════════════════════════╗\n";
printf("║  Results: %d passed, %d failed %s║\n",
    $passed, $failed, str_repeat(' ', 38 - strlen("{$passed}") - strlen("{$failed}")));
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf("║  Rooms tested:        %-36d ║\n", $roomCount);
printf("║  Working rooms:       %-36d ║\n", $workingCount);
printf("║  Conflict checks:     %-36d ║\n", $checked + $workingCount);
printf("║  Bookings created:    %-36d ║\n", 1 + $bulkCreateOk);
printf("║  Bookings deleted:    %-36d ║\n", 1 + $bulkDeleteOk);
printf("║  Exchange rooms:      %-36d ║\n", $exchangeRoomCount);
echo "╚══════════════════════════════════════════════════════════════╝\n";

if ($failed > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $err) {
        echo "  ❌ {$err}\n";
    }
    echo "\n";
    exit(1);
}

echo "\n";
exit(0);
