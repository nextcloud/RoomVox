#!/bin/bash

# RoomVox Location Fixer
# Updates LOCATION on all existing bookings to match current room data
# Format: "Address (Room X.XX)" — matches SchedulingPlugin::buildRoomLocation()
#
# Usage: ./fix-locations.sh [1dev|3dev]  (default: 3dev)

set -e

REMOTE_USER="rdekker"
SSH_KEY="~/.ssh/sur"

case "${1:-3dev}" in
    1dev|1)
        REMOTE_HOST="145.38.193.235"
        SERVER_NAME="1dev"
        ;;
    3dev|3|"")
        REMOTE_HOST="145.38.188.218"
        SERVER_NAME="3dev"
        ;;
    *)
        echo "Unknown server: $1"
        echo "Usage: ./fix-locations.sh [1dev|3dev]"
        exit 1
        ;;
esac

echo "RoomVox Location Fixer"
echo "======================"
echo "Server: $SERVER_NAME ($REMOTE_HOST)"
echo ""

FIX_PHP=$(cat << 'PHPEOF'
<?php
require '/var/www/nextcloud/lib/base.php';

$container = \OC::$server;
$roomService = $container->get(\OCA\RoomVox\Service\RoomService::class);
$calDavBackend = $container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
$db = $container->get(\OCP\IDBConnection::class);

$allRooms = $roomService->getAllRooms();
if (empty($allRooms)) {
    echo "No rooms found.\n";
    exit(1);
}

// Build a location string from room data (matches RoomService::buildRoomLocation)
// Format: "Street, PostalCode City (Building, Room Nr)" — optimized for iOS/macOS Maps geocoding
function buildLocation(array $room): string {
    $address = trim($room['address'] ?? '');
    $roomNumber = trim($room['roomNumber'] ?? '');

    // Parse address parts: "Building, Street, PostalCode, City"
    $parts = array_map('trim', explode(',', $address));
    $building = '';
    $street = '';
    $postalCode = '';
    $city = '';

    if (count($parts) >= 4) {
        $building = $parts[0];
        $street = $parts[1];
        $postalCode = $parts[2];
        $city = implode(', ', array_slice($parts, 3));
    } elseif (count($parts) === 3) {
        $building = $parts[0];
        $street = $parts[1];
        if (preg_match('/^\d{4}\s*[A-Z]{2}$/i', $parts[2])) {
            $postalCode = $parts[2];
        } else {
            $city = $parts[2];
        }
    } elseif (count($parts) === 2) {
        $building = $parts[0];
        $street = $parts[1];
    } elseif (count($parts) === 1 && $parts[0] !== '') {
        $street = $parts[0];
    }

    // Build geocodable address: "Street, PostalCode City"
    $geocodable = $street;
    $cityPart = trim($postalCode . ' ' . $city);
    if ($geocodable !== '' && $cityPart !== '') {
        $geocodable .= ', ' . $cityPart;
    } elseif ($cityPart !== '') {
        $geocodable = $cityPart;
    }

    // Build detail: "(Building, Room Nr)"
    $detailParts = [];
    if ($building !== '') {
        $detailParts[] = $building;
    }
    if ($roomNumber !== '') {
        $detailParts[] = 'Room ' . $roomNumber;
    }
    $detail = implode(', ', $detailParts);

    // Combine: "Street, PostalCode City (Building, Room Nr)"
    if ($geocodable !== '' && $detail !== '') {
        return $geocodable . ' (' . $detail . ')';
    }
    if ($geocodable !== '') {
        return $geocodable;
    }
    if ($detail !== '') {
        return $room['name'] . ' (' . $detail . ')';
    }

    return $room['name'];
}

// Resolve calendar IDs for room accounts
$qb = $db->getQueryBuilder();
$qb->select('id', 'principaluri', 'uri')
   ->from('calendars')
   ->where($qb->expr()->like('principaluri', $qb->createNamedParameter('%rb_%')));
$result = $qb->executeQuery();
$calendarsByUserId = [];
while ($row = $result->fetch()) {
    $parts = explode('/', $row['principaluri']);
    $userId = end($parts);
    $calendarsByUserId[$userId] = (int)$row['id'];
}
$result->closeCursor();

$updated = 0;
$skipped = 0;
$noCalendar = 0;

foreach ($allRooms as $room) {
    $calendarId = $calendarsByUserId[$room['userId']] ?? null;
    if ($calendarId === null) {
        $noCalendar++;
        continue;
    }

    $newLocation = buildLocation($room);
    echo "── {$room['name']} → \"{$newLocation}\"\n";

    // Get all calendar objects for this room
    $objects = $calDavBackend->getCalendarObjects($calendarId);

    foreach ($objects as $objInfo) {
        $obj = $calDavBackend->getCalendarObject($calendarId, $objInfo['uri']);
        if (!$obj || empty($obj['calendardata'])) continue;

        $icsData = $obj['calendardata'];

        try {
            $vCalendar = \Sabre\VObject\Reader::read($icsData);
        } catch (\Exception $e) {
            continue;
        }

        $vEvent = $vCalendar->VEVENT ?? null;
        if (!$vEvent) continue;

        $currentLocation = (string)($vEvent->LOCATION ?? '');

        // Update if LOCATION is missing or different from what it should be
        if ($currentLocation === $newLocation) {
            $skipped++;
            continue;
        }

        $vEvent->LOCATION = $newLocation;
        $updatedIcs = $vCalendar->serialize();

        try {
            $calDavBackend->updateCalendarObject($calendarId, $objInfo['uri'], $updatedIcs);
            $label = $currentLocation === '' ? '(empty)' : "\"{$currentLocation}\"";
            echo "   OK: {$objInfo['uri']}  {$label} → \"{$newLocation}\"\n";
            $updated++;
        } catch (\Exception $e) {
            echo "   FAIL: {$objInfo['uri']} - {$e->getMessage()}\n";
        }
    }
}

echo "\nDone: {$updated} updated, {$skipped} already correct, {$noCalendar} rooms without calendar.\n";
PHPEOF
)

echo "Uploading fix script..."
echo "$FIX_PHP" | ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "cat > /tmp/roomvox-fix-locations.php"

echo "Running fix script..."
echo ""
ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "sudo -u www-data php /tmp/roomvox-fix-locations.php && rm -f /tmp/roomvox-fix-locations.php"

echo ""
echo "Done! Check events in the calendar to verify LOCATION fields."
