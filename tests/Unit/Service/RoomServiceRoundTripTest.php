<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\RoomService;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Issue #44 — create → delete → create for the same room id.
 *
 * RoomService has no deleteRoom() coverage at all, so the round trip that the
 * issue reports has never been exercised. These tests use a real in-memory
 * IAppConfig so createRoom()/deleteRoom() actually persist, rather than
 * asserting against a stubbed getter.
 *
 * Root cause measured on a Nextcloud 35 test instance (RoomVox 1.5.0,
 * oc_calendars): Nextcloud's CalDavBackend::deleteCalendar($id, $force = false)
 * SOFT-deletes — the row stays with deleted_at set, so UNIQUE(principaluri, uri)
 * (index `calendars_index`) remains occupied. Recreating the same slug therefore
 * collides even though the delete "succeeded". The signature is identical on
 * stable32 and stable35, so passing true is safe across the supported range.
 */
class RoomServiceRoundTripTest extends TestCase {
    private RoomService $service;
    private IAppConfig $appConfig;

    /** @var array<string, string> In-memory appconfig store */
    private array $store = [];

    protected function setUp(): void {
        $this->store = [];
        $this->appConfig = $this->createMock(IAppConfig::class);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(
                fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default
            );
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) {
                $this->store[$key] = $value;
                return true;
            });
        $this->appConfig->method('deleteKey')
            ->willReturnCallback(function (string $app, string $key) {
                unset($this->store[$key]);
            });

        $this->service = new RoomService(
            $this->appConfig,
            $this->createMock(ICrypto::class),
            $this->createMock(ISecureRandom::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * Baseline: deleteRoom() must actually remove the room and its index entry.
     * This currently passes and guards the rest.
     */
    public function testDeleteRoomRemovesRoomAndIndexEntry(): void {
        $room = $this->service->createRoom(['name' => 'Office 103']);
        $this->assertNotNull($this->service->getRoom($room['id']));

        $this->assertTrue($this->service->deleteRoom($room['id']));

        $this->assertNull($this->service->getRoom($room['id']));
        $this->assertArrayNotHasKey('room/' . $room['id'], $this->store);
        $this->assertSame('[]', $this->store['rooms_index'] ?? '[]');
    }

    /**
     * After a delete the slug is free again, so the recreated room gets the
     * identical id and userId — and therefore the identical calendar uri that
     * provisionCalendar() will try to claim. This documents why the collision
     * in the issue happens: RoomVox's own uniqueness loop only consults its
     * rooms_index, which deleteRoom() has just cleared.
     */
    public function testRecreatedRoomReusesTheSameIdAndCalendarUri(): void {
        $first = $this->service->createRoom(['name' => 'Office 103']);
        $this->service->deleteRoom($first['id']);
        $second = $this->service->createRoom(['name' => 'Office 103']);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['userId'], $second['userId']);

        // provisionCalendar() derives the uri from userId; identical userId
        // means an identical UNIQUE(principaluri, uri) target.
        $this->assertSame('room-' . $first['userId'], 'room-' . $second['userId']);
    }

    /**
     * The room must not survive a failed calendar provisioning.
     *
     * With no transaction spanning IAppConfig and CalDAV, the rollback has to
     * be explicit. Both the editor and the CSV import create rooms through
     * createRoomWithCalendar(), which removes the room again.
     */
    public function testFailedProvisioningLeavesNoOrphanRoom(): void {
        $calDAVService = $this->createMock(CalDAVService::class);
        $calDAVService->method('provisionCalendar')
            ->willThrowException(new \Exception('UNIQUE constraint failed: calendars_index'));

        try {
            $this->service->createRoomWithCalendar(['name' => 'Office 103'], $calDAVService);
            $this->fail('Expected the provisioning error to reach the caller');
        } catch (\Exception $e) {
            $this->assertStringContainsString('calendars_index', $e->getMessage());
        }

        $this->assertNull(
            $this->service->getRoom('office-103'),
            'A room whose calendar could not be provisioned must not be left behind — '
            . 'it is permanently unbookable and the public API reported it as free.',
        );
        $this->assertSame('[]', $this->store['rooms_index'] ?? '[]');
    }

    public function testSuccessfulProvisioningRecordsTheCalendar(): void {
        $calDAVService = $this->createMock(CalDAVService::class);
        $calDAVService->method('provisionCalendar')->willReturn('room-rb_office-103');

        $room = $this->service->createRoomWithCalendar(['name' => 'Office 103'], $calDAVService);

        $this->assertSame('room-rb_office-103', $room['calendarUri']);
        $this->assertSame('room-rb_office-103', $this->service->getRoom('office-103')['calendarUri']);
    }
}
