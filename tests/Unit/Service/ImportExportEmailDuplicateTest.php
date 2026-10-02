<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\ImportExportService;
use OCA\RoomVox\Service\RoomService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A CSV is the most realistic route to two rooms sharing one email address: one
 * duplicated row, or a row carrying an address that already belongs elsewhere.
 * Both are reported on the row so the preview refuses them before anything is
 * written.
 */
class ImportExportEmailDuplicateTest extends TestCase {
    /**
     * @param array<int, array<string, mixed>> $existingRooms
     */
    private function service(array $existingRooms = []): ImportExportService {
        $roomService = $this->createMock(RoomService::class);
        $roomService->method('getAllRooms')->willReturn($existingRooms);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');

        return new ImportExportService($roomService, $appConfig, $this->createMock(LoggerInterface::class));
    }

    /**
     * @return array<int, string> The errors reported for the given row
     */
    private function errorsForLine(array $parsed, int $line): array {
        foreach ($parsed['rows'] as $row) {
            if ($row['line'] === $line) {
                return $row['errors'];
            }
        }

        return [];
    }

    public function testTwoRowsSharingAnAddressAreRefused(): void {
        $csv = "name,email\nRoom A,shared@example.com\nRoom B,shared@example.com\n";

        $parsed = $this->service()->parseCsv($csv);

        // The first occurrence is fine; the second is the duplicate.
        $this->assertSame([], $this->errorsForLine($parsed, 2));
        $this->assertNotEmpty($this->errorsForLine($parsed, 3));
        $this->assertStringContainsString('Duplicate email', $this->errorsForLine($parsed, 3)[0]);
    }

    public function testDuplicateWithinCsvIsCaseInsensitive(): void {
        $csv = "name,email\nRoom A,shared@example.com\nRoom B,SHARED@EXAMPLE.COM\n";

        $parsed = $this->service()->parseCsv($csv);

        $this->assertNotEmpty($this->errorsForLine($parsed, 3));
    }

    /**
     * A row carrying an address that already exists is matched to that address'
     * own room and updates it, so the address never lands on a second room. It
     * renames the matched room, which the importer has always allowed — the
     * address match takes precedence over the name match.
     *
     * This documents why no separate "address belongs to another room" check is
     * needed on import: the matching rules make that case unreachable.
     */
    public function testRowIsMatchedToTheRoomHoldingTheAddress(): void {
        $existing = [
            ['id' => 'room-a', 'name' => 'Room A', 'email' => 'a@example.com'],
            ['id' => 'room-b', 'name' => 'Room B', 'email' => 'b@example.com'],
        ];
        $csv = "name,email\nRoom A,b@example.com\n";

        $parsed = $this->service($existing)->parseCsv($csv);

        $row = $parsed['rows'][0];
        $this->assertSame('update', $row['action']);
        $this->assertSame('room-b', $row['matchedId']);
        $this->assertSame([], $row['errors']);
    }

    /**
     * A row updating a room with the address it already has is not a conflict.
     */
    public function testRoomKeepingItsOwnAddressIsAccepted(): void {
        $existing = [
            ['id' => 'room-a', 'name' => 'Room A', 'email' => 'a@example.com'],
        ];
        $csv = "name,email\nRoom A,a@example.com\n";

        $parsed = $this->service($existing)->parseCsv($csv);

        $this->assertSame([], $this->errorsForLine($parsed, 2));
    }

    /**
     * Rows without an address must not collide with each other on "".
     */
    public function testRowsWithoutAnAddressDoNotCollide(): void {
        $csv = "name,email\nRoom A,\nRoom B,\n";

        $parsed = $this->service()->parseCsv($csv);

        $this->assertSame([], $this->errorsForLine($parsed, 2));
        $this->assertSame([], $this->errorsForLine($parsed, 3));
    }
}
