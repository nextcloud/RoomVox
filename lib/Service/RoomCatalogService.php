<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The room types and facilities an administrator can choose from.
 *
 * These are content, like a room's name: the administrator edits them, and
 * they read the same for everyone whatever language they use. Until the
 * administrator saves a list, RoomVox offers the built-in set below. It is
 * seeded content in the fail-over language, English, deliberately not passed
 * through IL10N, so a label does not change when someone switches language.
 * This is the only copy; the frontend no longer carries its own.
 */
class RoomCatalogService {
    private const DEFAULT_ROOM_TYPES = [
        ['id' => 'meeting-room', 'label' => 'Meeting room'],
        ['id' => 'rehearsal-room', 'label' => 'Rehearsal room'],
        ['id' => 'studio', 'label' => 'Studio'],
        ['id' => 'lecture-hall', 'label' => 'Lecture hall'],
        ['id' => 'outdoor-area', 'label' => 'Outdoor area'],
        ['id' => 'other', 'label' => 'Other'],
    ];

    private const DEFAULT_FACILITIES = [
        ['id' => 'projector', 'label' => 'Projector'],
        ['id' => 'whiteboard', 'label' => 'Whiteboard'],
        ['id' => 'videoconf', 'label' => 'Video conference'],
        ['id' => 'audio', 'label' => 'Audio system'],
        ['id' => 'display', 'label' => 'Display screen'],
        ['id' => 'wheelchair', 'label' => 'Wheelchair accessible'],
    ];

    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function getRoomTypes(): array {
        return $this->getList('room_types') ?? self::DEFAULT_ROOM_TYPES;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function getFacilities(): array {
        return $this->getList('facilities') ?? self::DEFAULT_FACILITIES;
    }

    /**
     * @return list<string>
     */
    public function getFacilityIds(): array {
        return array_values(array_map(fn (array $f) => (string)$f['id'], $this->getFacilities()));
    }

    /**
     * A room type's label, or the ID itself for a type no longer in the list.
     */
    public function getRoomTypeLabel(string $id): string {
        return $this->labelFor($id, $this->getRoomTypes());
    }

    /**
     * Labels for a room's facility IDs, in the room's order. An ID that is no
     * longer in the list is shown as stored.
     *
     * @param list<string> $ids
     * @return list<string>
     */
    public function getFacilityLabels(array $ids): array {
        $facilities = $this->getFacilities();
        return array_values(array_map(fn ($id) => $this->labelFor((string)$id, $facilities), $ids));
    }

    /**
     * @param list<array{id: string, label: string}> $list
     */
    private function labelFor(string $id, array $list): string {
        foreach ($list as $item) {
            if (($item['id'] ?? null) === $id) {
                return (string)($item['label'] ?? $id);
            }
        }
        return $id;
    }

    /**
     * The saved list, or null while the administrator has not saved one.
     */
    private function getList(string $key): ?array {
        $json = $this->appConfig->getValueString(Application::APP_ID, $key, '');
        if ($json === '') {
            return null;
        }
        $list = json_decode($json, true);
        return is_array($list) ? $list : null;
    }
}
