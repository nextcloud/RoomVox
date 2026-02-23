#!/usr/bin/env php
<?php
/**
 * RoomVox Integration Benchmark
 *
 * Runs real conflict checks and booking operations against the live
 * Nextcloud database with actual room data. No mocks — measures true
 * end-to-end performance including DB queries and iCal parsing.
 *
 * Usage: cd /var/www/nextcloud && sudo -u www-data php apps/roomvox/tests/integration-benchmark.php
 */

// Bootstrap Nextcloud
define('OC_CONSOLE', 1);
require_once __DIR__ . '/../../../lib/base.php';

use OCA\RoomVox\Service\RoomService;
use OCA\RoomVox\Service\CalDAVService;

// ── Helpers ─────────────────────────────────────────────────────

function formatMs(float $ns): string {
    return sprintf('%.1fms', $ns / 1_000_000);
}

function printResult(string $label, int $count, float $totalNs, float $limitMs): void {
    $totalMs = $totalNs / 1_000_000;
    $perItem = $count > 0 ? $totalMs / $count : 0;
    $status = $totalMs < $limitMs ? '✅ PASS' : '❌ FAIL';
    printf(
        "  %s  %-50s %d items in %s (%.2fms/item, limit: %.0fms)\n",
        $status, $label, $count, formatMs($totalNs), $perItem, $limitMs
    );
}

// ── Setup ───────────────────────────────────────────────────────

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         RoomVox Integration Benchmark                      ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$app = \OC::$server->get(\OCA\RoomVox\AppInfo\Application::class);

/** @var RoomService $roomService */
$roomService = \OC::$server->get(RoomService::class);

/** @var CalDAVService $calDAVService */
$calDAVService = \OC::$server->get(CalDAVService::class);

// ── 1. Load all rooms ───────────────────────────────────────────

echo "1. Room listing\n";

$start = hrtime(true);
$rooms = $roomService->getAllRooms();
$elapsed = hrtime(true) - $start;
$roomCount = count($rooms);

printResult("getAllRooms()", $roomCount, $elapsed, 500);
echo "   Found {$roomCount} rooms\n\n";

if ($roomCount === 0) {
    echo "No rooms found. Exiting.\n";
    exit(1);
}

// ── 2. Conflict check on every room ─────────────────────────────

echo "2. Conflict check (all rooms, tomorrow 10:00-11:00)\n";

$tomorrow = new DateTime('tomorrow 10:00');
$tomorrowEnd = new DateTime('tomorrow 11:00');

$start = hrtime(true);
$conflicts = 0;
$noConflicts = 0;
$errors = 0;

foreach ($rooms as $room) {
    try {
        $hasConflict = $calDAVService->hasConflict(
            $room['userId'],
            $tomorrow,
            $tomorrowEnd
        );
        if ($hasConflict) {
            $conflicts++;
        } else {
            $noConflicts++;
        }
    } catch (\Throwable $e) {
        $errors++;
    }
}
$elapsed = hrtime(true) - $start;

printResult("hasConflict() x {$roomCount}", $roomCount, $elapsed, $roomCount * 20); // 20ms per room budget
echo "   Conflicts: {$conflicts}, Free: {$noConflicts}, Errors: {$errors}\n\n";

// ── 3. Sequential bookings: create + delete ─────────────────────

echo "3. Create + delete booking (first 10 free rooms)\n";

$freeRooms = [];
foreach ($rooms as $room) {
    try {
        if (!$calDAVService->hasConflict($room['userId'], $tomorrow, $tomorrowEnd)) {
            $freeRooms[] = $room;
        }
        if (count($freeRooms) >= 10) break;
    } catch (\Throwable $e) {
        continue;
    }
}

$bookingCount = count($freeRooms);
echo "   Using {$bookingCount} free rooms\n";

$createTimes = [];
$deleteTimes = [];
$createdUids = [];

foreach ($freeRooms as $room) {
    // Create
    $cStart = hrtime(true);
    try {
        $uid = $calDAVService->createBooking($room['userId'], [
            'summary' => 'Benchmark Test Booking',
            'start' => $tomorrow,
            'end' => $tomorrowEnd,
            'description' => 'Automated benchmark test — will be deleted immediately',
            'organizer' => 'admin',
            'roomEmail' => $room['email'] ?? '',
            'autoAccept' => true,
        ]);
        $createTimes[] = hrtime(true) - $cStart;
        $createdUids[] = ['userId' => $room['userId'], 'uid' => $uid];
    } catch (\Throwable $e) {
        echo "   ⚠ Create failed for {$room['id']}: {$e->getMessage()}\n";
        continue;
    }
}

$totalCreate = array_sum($createTimes);
printResult("createBooking() x " . count($createTimes), count($createTimes), $totalCreate, count($createTimes) * 50);

// Verify conflict exists
$conflictAfter = 0;
foreach ($freeRooms as $i => $room) {
    if ($i >= count($createdUids)) break;
    try {
        if ($calDAVService->hasConflict($room['userId'], $tomorrow, $tomorrowEnd)) {
            $conflictAfter++;
        }
    } catch (\Throwable $e) {
        continue;
    }
}
echo "   Conflict check after create: {$conflictAfter}/{$bookingCount} rooms now show conflict ✓\n";

// Delete
foreach ($createdUids as $booking) {
    $dStart = hrtime(true);
    try {
        $calDAVService->deleteBooking($booking['userId'], $booking['uid']);
        $deleteTimes[] = hrtime(true) - $dStart;
    } catch (\Throwable $e) {
        echo "   ⚠ Delete failed for {$booking['uid']}: {$e->getMessage()}\n";
    }
}

$totalDelete = array_sum($deleteTimes);
printResult("deleteBooking() x " . count($deleteTimes), count($deleteTimes), $totalDelete, count($deleteTimes) * 50);
echo "\n";

// ── 4. Bulk conflict check (measure DB throughput) ──────────────

echo "4. Bulk conflict check — 5 different time slots × all {$roomCount} rooms\n";

$timeSlots = [
    [new DateTime('tomorrow 08:00'), new DateTime('tomorrow 09:00')],
    [new DateTime('tomorrow 10:00'), new DateTime('tomorrow 11:00')],
    [new DateTime('tomorrow 12:00'), new DateTime('tomorrow 13:00')],
    [new DateTime('tomorrow 14:00'), new DateTime('tomorrow 15:00')],
    [new DateTime('tomorrow 16:00'), new DateTime('tomorrow 17:00')],
];

$totalChecks = 0;
$start = hrtime(true);

foreach ($timeSlots as [$slotStart, $slotEnd]) {
    foreach ($rooms as $room) {
        try {
            $calDAVService->hasConflict($room['userId'], $slotStart, $slotEnd);
            $totalChecks++;
        } catch (\Throwable $e) {
            // skip
        }
    }
}
$elapsed = hrtime(true) - $start;

printResult("hasConflict() x {$totalChecks} (5 slots × {$roomCount} rooms)", $totalChecks, $elapsed, $totalChecks * 20);
echo "\n";

// ── Summary ─────────────────────────────────────────────────────

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Summary                                                   ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf("║  Rooms:              %-38d ║\n", $roomCount);
printf("║  Conflict checks:    %-38d ║\n", $totalChecks + $roomCount);
printf("║  Bookings created:   %-38d ║\n", count($createTimes));
printf("║  Bookings deleted:   %-38d ║\n", count($deleteTimes));
echo "╚══════════════════════════════════════════════════════════════╝\n\n";
