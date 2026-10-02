<?php

declare(strict_types=1);

namespace OCA\RoomVox\Migration;

use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\RoomService;
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Move bookings out of the calendars RoomVox used to provision itself.
 *
 * A room's bookings belong in the calendar Nextcloud keeps for the room
 * resource. RoomVox also provisioned a calendar of its own under the room's
 * user principal and wrote there until Nextcloud's calendar existed; from then
 * on those bookings were invisible, so their slots could be booked again.
 * This step lets Nextcloud create any room calendar that is missing, then
 * moves the leftover bookings across and removes the old calendars.
 * It is safe to run more than once.
 */
class MoveBookingsToRoomCalendars implements IRepairStep {
    public function __construct(
        private RoomService $roomService,
        private CalDAVService $calDAVService,
        private IRoomManager $roomManager,
    ) {
    }

    public function getName(): string {
        return 'Move RoomVox bookings into the room calendars';
    }

    public function run(IOutput $output): void {
        $this->roomManager->update();

        $moved = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($this->roomService->getAllRooms() as $room) {
            // One room's trouble must not leave the others unmoved: nothing
            // reads the old calendars any more, and the step only runs again
            // with the next upgrade.
            try {
                if ($this->calDAVService->getRoomCalendarId($room['userId']) === null) {
                    $output->warning("Room {$room['id']} has no room calendar; its bookings were left where they are");
                    continue;
                }

                $result = $this->calDAVService->moveLegacyBookings($room['userId']);
                $moved += $result['moved'];
                $skipped += $result['skipped'];
                if ($result['failed'] > 0) {
                    $failed += $result['failed'];
                    $output->warning("Room {$room['id']}: {$result['failed']} bookings could not be moved and stay in the old calendar");
                }
            } catch (\Throwable $e) {
                $output->warning("Room {$room['id']}: bookings not moved: " . $e->getMessage());
            }
        }

        $output->info("Moved {$moved} bookings into room calendars ({$skipped} were there already, {$failed} failed)");
    }
}
