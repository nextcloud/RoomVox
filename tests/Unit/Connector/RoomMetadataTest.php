<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Connector;

use OCA\RoomVox\Connector\Room\Room;
use OCP\Calendar\Room\IBackend;
use PHPUnit\Framework\TestCase;

class RoomMetadataTest extends TestCase {
    private const ADDRESS = '{http://nextcloud.com/ns}room-building-address';
    private const STORY = '{http://nextcloud.com/ns}room-building-story';
    private const ROOM_NUMBER = '{http://nextcloud.com/ns}room-building-room-number';
    private const CAPACITY = '{http://nextcloud.com/ns}room-seating-capacity';
    private const DESCRIPTION = '{urn:ietf:params:xml:ns:caldav}calendar-description';

    private function makeRoom(
        ?string $address = null,
        ?string $floor = null,
        ?string $roomNumber = null,
        ?int $capacity = null,
    ): Room {
        return new Room(
            $this->createMock(IBackend::class),
            'room-1',
            'Room 1',
            'room1@example.com',
            $capacity,
            $roomNumber,
            $floor,
            $address,
        );
    }

    public function testPublishesFloorAsBuildingStory(): void {
        $room = $this->makeRoom(floor: '2');

        $this->assertSame('2', $room->getMetadataForKey(self::STORY));
        $this->assertContains(self::STORY, $room->getAllAvailableMetadataKeys());
    }

    public function testDoesNotPublishTheNonStandardFloorKey(): void {
        $room = $this->makeRoom(floor: '2');

        $this->assertNull($room->getMetadataForKey('{http://nextcloud.com/ns}room-building-floor'));
        $this->assertNotContains(
            '{http://nextcloud.com/ns}room-building-floor',
            $room->getAllAvailableMetadataKeys(),
        );
    }

    /**
     * @dataProvider addressProvider
     */
    public function testNormalizesTheAddress(?string $stored, ?string $expected): void {
        $room = $this->makeRoom(address: $stored);

        $this->assertSame($expected, $room->getMetadataForKey(self::ADDRESS));
    }

    public static function addressProvider(): array {
        return [
            'complete' => ['Poppodium, Kerkstraat 10, 1098 XG, Amsterdam', 'Poppodium, Kerkstraat 10, 1098 XG, Amsterdam'],
            // Imported without a building name
            'empty building' => [', Science Park 140, 1098 XG, Amsterdam', 'Science Park 140, 1098 XG, Amsterdam'],
            // Imported without a building name and without a street
            'empty building and street' => [', , 1098 XG, Amsterdam', '1098 XG, Amsterdam'],
            'trailing separators' => ['Poppodium, Kerkstraat 10, , ', 'Poppodium, Kerkstraat 10'],
            'only separators' => [', , , ', null],
            'empty' => ['', null],
            'not set' => [null, null],
        ];
    }

    public function testKeepsTheNormalizedAddressOutOfTheDescription(): void {
        $room = $this->makeRoom(address: ', , 1098 XG, Amsterdam', roomNumber: '2.10', capacity: 20);

        $description = $room->getMetadataForKey(self::DESCRIPTION);

        $this->assertStringContainsString('Address: 1098 XG, Amsterdam', $description);
        $this->assertStringNotContainsString('Address: , ,', $description);
    }

    public function testPublishesCapacityAsString(): void {
        $room = $this->makeRoom(capacity: 20);

        $this->assertSame('20', $room->getMetadataForKey(self::CAPACITY));
        $this->assertNull($this->makeRoom()->getMetadataForKey(self::CAPACITY));
    }

    public function testPublishesRoomNumber(): void {
        $room = $this->makeRoom(roomNumber: '2.10');

        $this->assertSame('2.10', $room->getMetadataForKey(self::ROOM_NUMBER));
        $this->assertNull($this->makeRoom(roomNumber: '')->getMetadataForKey(self::ROOM_NUMBER));
    }
}
