<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\RoomCatalogService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Room types and facilities are content: the built-in set is seeded in
 * English, a list the administrator saved is returned as entered.
 */
class RoomCatalogServiceTest extends TestCase {
    private function catalog(array $saved = []): RoomCatalogService {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $saved[$key] ?? $default
        );
        return new RoomCatalogService($appConfig);
    }

    public function testDefaultsAreEnglishContent(): void {
        $this->assertSame(['id' => 'meeting-room', 'label' => 'Meeting room'], $this->catalog()->getRoomTypes()[0]);
        $this->assertContains(['id' => 'videoconf', 'label' => 'Video conference'], $this->catalog()->getFacilities());
    }

    public function testSavedListsAreReturnedAsEntered(): void {
        $catalog = $this->catalog([
            'room_types' => json_encode([['id' => 'boardroom', 'label' => 'Directiekamer']]),
            'facilities' => json_encode([['id' => 'coffee', 'label' => 'Koffie']]),
        ]);

        $this->assertSame([['id' => 'boardroom', 'label' => 'Directiekamer']], $catalog->getRoomTypes());
        $this->assertSame(['coffee'], $catalog->getFacilityIds());
    }

    public function testInvalidSavedListFallsBackToTheDefaults(): void {
        $this->assertSame('Studio', $this->catalog(['room_types' => 'not json'])->getRoomTypeLabel('studio'));
    }

    public function testLabelsForIdsKeepUnknownIds(): void {
        $this->assertSame('removed-type', $this->catalog()->getRoomTypeLabel('removed-type'));
        $this->assertSame(
            ['Projector', 'gone', 'Wheelchair accessible'],
            $this->catalog()->getFacilityLabels(['projector', 'gone', 'wheelchair']),
        );
    }
}
