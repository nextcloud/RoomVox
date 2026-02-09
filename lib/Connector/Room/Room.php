<?php

declare(strict_types=1);

namespace OCA\ResaVox\Connector\Room;

use OCP\Calendar\Room\IRoom;
use OCP\Calendar\IMetadataProvider;
use OCP\Calendar\Room\IBackend;

class Room implements IRoom, IMetadataProvider {
    private const METADATA_KEYS = [
        '{urn:ietf:params:xml:ns:caldav}calendar-description',
        '{http://nextcloud.com/ns}room-type',
        '{http://nextcloud.com/ns}room-seating-capacity',
        '{http://nextcloud.com/ns}room-building-name',
        '{http://nextcloud.com/ns}room-features',
    ];

    public function __construct(
        private IBackend $backend,
        private string $id,
        private string $displayName,
        private string $email,
        private ?int $capacity = null,
        private ?string $location = null,
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
        if ($this->location !== null && $this->location !== '') {
            return $this->displayName . ' — ' . $this->location;
        }
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
            '{http://nextcloud.com/ns}room-type' => 'meeting-room',
            '{http://nextcloud.com/ns}room-seating-capacity' => $this->capacity !== null ? (string)$this->capacity : null,
            '{http://nextcloud.com/ns}room-building-name' => null,
            '{http://nextcloud.com/ns}room-features' => $this->getFeaturesString(),
            default => null,
        };
    }

    private function buildDescription(): string {
        $parts = [$this->displayName];

        if ($this->location !== null && $this->location !== '') {
            $parts[] = "Location: {$this->location}";
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
