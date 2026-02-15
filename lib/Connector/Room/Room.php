<?php

declare(strict_types=1);

namespace OCA\RoomVox\Connector\Room;

use OCP\Calendar\Room\IRoom;
use OCP\Calendar\IMetadataProvider;
use OCP\Calendar\Room\IBackend;

class Room implements IRoom, IMetadataProvider {
    private const METADATA_KEYS = [
        '{urn:ietf:params:xml:ns:caldav}calendar-description',
        '{http://nextcloud.com/ns}room-type',
        '{http://nextcloud.com/ns}room-seating-capacity',
        '{http://nextcloud.com/ns}room-building-address',
        '{http://nextcloud.com/ns}room-building-room-number',
        '{http://nextcloud.com/ns}room-features',
    ];

    public function __construct(
        private IBackend $backend,
        private string $id,
        private string $displayName,
        private string $email,
        private ?int $capacity = null,
        private ?string $roomNumber = null,
        private ?string $address = null,
        private ?string $roomType = 'meeting-room',
        private ?string $description = null,
        private array $facilities = [],
        private array $groupRestrictions = [],
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string {
        return $this->displayName;
    }

    /**
     * @inheritDoc
     */
    public function getGroupRestrictions(): array {
        return $this->groupRestrictions;
    }

    /**
     * @inheritDoc
     */
    public function getEMail(): string {
        return $this->email;
    }

    /**
     * @inheritDoc
     */
    public function getBackend(): IBackend {
        return $this->backend;
    }

    /**
     * @inheritDoc
     */
    public function getAllAvailableMetadataKeys(): array {
        return self::METADATA_KEYS;
    }

    /**
     * @inheritDoc
     */
    public function hasMetadataForKey(string $key): bool {
        return $this->getMetadataForKey($key) !== null;
    }

    /**
     * @inheritDoc
     */
    public function getMetadataForKey(string $key): ?string {
        return match ($key) {
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => $this->buildDescription(),
            '{http://nextcloud.com/ns}room-type' => $this->roomType,
            '{http://nextcloud.com/ns}room-seating-capacity' => $this->capacity !== null ? (string)$this->capacity : null,
            // Address includes building name as prefix: "Poppodium, Kerkstraat 10"
            // The frontend extracts the building name (before first comma) for grouping
            '{http://nextcloud.com/ns}room-building-address' => ($this->address !== null && $this->address !== '') ? $this->address : null,
            // Room number in floor.room format: "2.17" (2nd floor, room 17)
            '{http://nextcloud.com/ns}room-building-room-number' => ($this->roomNumber !== null && $this->roomNumber !== '') ? $this->roomNumber : null,
            '{http://nextcloud.com/ns}room-features' => $this->getFeaturesString(),
            default => null,
        };
    }

    private function buildDescription(): string {
        $parts = [$this->displayName];

        if ($this->address !== null && $this->address !== '') {
            $parts[] = "Address: {$this->address}";
        }

        if ($this->roomNumber !== null && $this->roomNumber !== '') {
            $parts[] = "Room: {$this->roomNumber}";
        }

        if ($this->capacity !== null && $this->capacity > 0) {
            $parts[] = "Capacity: {$this->capacity} persons";
        }

        if (!empty($this->facilities)) {
            $parts[] = "Facilities: " . implode(', ', $this->facilities);
        }

        if ($this->description !== null && $this->description !== '') {
            $parts[] = $this->description;
        }

        return implode(' | ', $parts);
    }

    private function getFeaturesString(): ?string {
        if (empty($this->facilities)) {
            return null;
        }

        return implode(',', $this->facilities);
    }
}
