<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\RoomService;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RoomServiceTest extends TestCase {
    private RoomService $service;
    private IAppConfig $appConfig;
    private ICrypto $crypto;
    private ISecureRandom $secureRandom;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->crypto = $this->createMock(ICrypto::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new RoomService(
            $this->appConfig,
            $this->crypto,
            $this->secureRandom,
            $this->logger,
        );
    }

    public function testStripMailto(): void {
        $this->assertSame('room@example.com', RoomService::stripMailto('mailto:room@example.com'));
        $this->assertSame('room@example.com', RoomService::stripMailto('MAILTO:room@example.com'));
    }

    public function testStripMailtoWithoutPrefix(): void {
        $this->assertSame('room@example.com', RoomService::stripMailto('room@example.com'));
        $this->assertSame('', RoomService::stripMailto(''));
    }

    public function testIsRoomAccountValid(): void {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                if ($key === 'room/testroom') {
                    return json_encode(['id' => 'testroom', 'name' => 'Test Room', 'email' => 'test@example.com']);
                }
                return $default;
            });

        $this->assertTrue($this->service->isRoomAccount('rb_testroom'));
    }

    public function testIsRoomAccountInvalidPrefix(): void {
        $this->assertFalse($this->service->isRoomAccount('normaluser'));
        $this->assertFalse($this->service->isRoomAccount(''));
    }

    public function testIsRoomAccountNonExistent(): void {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $this->assertFalse($this->service->isRoomAccount('rb_nonexistent'));
    }

    public function testExtractUserIdFromPrincipal(): void {
        $this->assertSame('rb_room1', $this->service->extractUserIdFromPrincipal('principals/users/rb_room1'));
        $this->assertSame('admin', $this->service->extractUserIdFromPrincipal('principals/users/admin'));
    }

    public function testExtractUserIdFromPrincipalUnknown(): void {
        $this->assertNull($this->service->extractUserIdFromPrincipal('unknown/format'));
    }

    public function testExtractUserIdFromMailto(): void {
        // Mock getAllRooms via getValueString
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                if ($key === 'rooms_index') {
                    return json_encode(['room1']);
                }
                if ($key === 'room/room1') {
                    return json_encode([
                        'id' => 'room1',
                        'userId' => 'rb_room1',
                        'name' => 'Room 1',
                        'email' => 'room1@company.com',
                        'smtpConfig' => null,
                    ]);
                }
                return $default;
            });

        $this->assertSame('rb_room1', $this->service->extractUserIdFromPrincipal('mailto:room1@company.com'));
        $this->assertNull($this->service->extractUserIdFromPrincipal('mailto:unknown@company.com'));
    }

    public function testBuildRoomLocationFullAddress(): void {
        $room = [
            'name' => 'Conference Room',
            'address' => 'Building A, Heidelberglaan 8, 3584 CS, Utrecht',
            'roomNumber' => '2.17',
        ];

        $result = $this->service->buildRoomLocation($room);
        $this->assertSame('Heidelberglaan 8, 3584 CS Utrecht (Building A, Room 2.17)', $result);
    }

    public function testBuildRoomLocationGermanPostalCodeNoComma(): void {
        // Issue #17: postal code and city must be space-joined, not "01324, Dresden".
        $room = [
            'name' => 'Besprechungsraum',
            'address' => 'Hauptgebäude, Luboldtstr. 11, 01324, Dresden',
            'roomNumber' => '',
        ];

        $result = $this->service->buildRoomLocation($room);
        $this->assertSame('Luboldtstr. 11, 01324 Dresden (Hauptgebäude)', $result);
    }

    public function testBuildRoomLocationGermanPostalCodeThreeParts(): void {
        // 3-part address with a 5-digit German postal code (no city) — must be
        // detected as a postal code, not a city.
        $room = [
            'name' => 'Raum',
            'address' => 'Gebäude, Luboldtstr. 11, 01324',
            'roomNumber' => '',
        ];

        $result = $this->service->buildRoomLocation($room);
        $this->assertSame('Luboldtstr. 11, 01324 (Gebäude)', $result);
    }

    public function testBuildRoomLocationNoAddress(): void {
        $room = [
            'name' => 'Quick Room',
            'address' => '',
            'roomNumber' => '',
        ];

        $result = $this->service->buildRoomLocation($room);
        $this->assertSame('Quick Room', $result);
    }

    public function testBuildRoomLocationOnlyBuilding(): void {
        $room = [
            'name' => 'Room X',
            'address' => 'Tower B',
            'roomNumber' => '3.01',
        ];

        $result = $this->service->buildRoomLocation($room);
        // Single address part is treated as street, not building
        $this->assertSame('Tower B (Room 3.01)', $result);
    }

    public function testBuildRoomLocationFourPartsWithEmptyBuilding(): void {
        // Issue #6: 4-part address with empty building (leading comma) must
        // not produce a stray "(, Room X)" detail block.
        $room = [
            'name' => 'Quiet Room',
            'address' => ', Hoofdstraat 1, 1234 AB, Amsterdam',
            'roomNumber' => '',
        ];

        $result = $this->service->buildRoomLocation($room);
        $this->assertSame('Hoofdstraat 1, 1234 AB Amsterdam', $result);
    }

    public function testBuildRoomLocationFourPartsWithEmptyCity(): void {
        // Issue #6: 4-part address with empty city should not emit a trailing
        // space or comma in the geocodable section.
        $room = [
            'name' => 'Side Room',
            'address' => 'Tower A, Hoofdstraat 1, 1234 AB, ',
            'roomNumber' => '4.10',
        ];

        $result = $this->service->buildRoomLocation($room);
        $this->assertSame('Hoofdstraat 1, 1234 AB (Tower A, Room 4.10)', $result);
    }

    public function testBuildRoomLocationFourPartsWithOnlyPostalAndCity(): void {
        // Issue #6: edge case where only postal code + city were entered.
        // 4-part storage keeps them in their correct slots.
        $room = [
            'name' => 'Project Room',
            'address' => ', , 1234 AB, Amsterdam',
            'roomNumber' => '',
        ];

        $result = $this->service->buildRoomLocation($room);
        // No street → geocodable falls back to "PostalCode City"
        $this->assertSame('1234 AB Amsterdam', $result);
    }

    public function testGetRoomByUserId(): void {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                if ($key === 'room/myroom') {
                    return json_encode(['id' => 'myroom', 'userId' => 'rb_myroom', 'name' => 'My Room']);
                }
                return $default;
            });

        $room = $this->service->getRoomByUserId('rb_myroom');
        $this->assertNotNull($room);
        $this->assertSame('myroom', $room['id']);
    }

    public function testGetRoomByUserIdInvalidPrefix(): void {
        $this->assertNull($this->service->getRoomByUserId('normaluser'));
    }

    // ── responsibleContact field (issue #11) ────────────────────────

    public function testCreateRoomPersistsResponsibleContact(): void {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                if ($key === 'rooms_index') {
                    return '[]';
                }
                return $default;
            });

        $captured = null;
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) use (&$captured) {
                if (str_starts_with($key, 'room/')) {
                    $captured = json_decode($value, true);
                }
                return true;
            });

        $this->service->createRoom([
            'name' => 'Test Room',
            'responsibleContact' => 'anne@voxcloud.nl',
        ]);

        $this->assertNotNull($captured);
        $this->assertSame('anne@voxcloud.nl', $captured['responsibleContact']);
    }

    public function testCreateRoomClampsResponsibleContactTo255Chars(): void {
        $this->appConfig->method('getValueString')->willReturn('[]');

        $captured = null;
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) use (&$captured) {
                if (str_starts_with($key, 'room/')) {
                    $captured = json_decode($value, true);
                }
                return true;
            });

        $longValue = str_repeat('a', 300);
        $this->service->createRoom([
            'name' => 'Test Room',
            'responsibleContact' => $longValue,
        ]);

        $this->assertSame(255, mb_strlen($captured['responsibleContact']));
    }

    public function testCreateRoomDefaultsResponsibleContactToEmptyString(): void {
        $this->appConfig->method('getValueString')->willReturn('[]');

        $captured = null;
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) use (&$captured) {
                if (str_starts_with($key, 'room/')) {
                    $captured = json_decode($value, true);
                }
                return true;
            });

        $this->service->createRoom(['name' => 'Test Room']);

        $this->assertSame('', $captured['responsibleContact']);
    }

    public function testUpdateRoomPersistsResponsibleContact(): void {
        $existing = ['id' => 'room1', 'name' => 'Room 1', 'capacity' => 0, 'autoAccept' => false, 'active' => true];

        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) use ($existing) {
                if ($key === 'room/room1') {
                    return json_encode($existing);
                }
                return $default;
            });

        $captured = null;
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) use (&$captured) {
                if ($key === 'room/room1') {
                    $captured = json_decode($value, true);
                }
                return true;
            });

        $this->service->updateRoom('room1', [
            'responsibleContact' => 'Frontdesk: 020-1234567',
        ]);

        $this->assertSame('Frontdesk: 020-1234567', $captured['responsibleContact']);
    }

    // ── Slug generation (issue #18) ──────────────────────────────────────

    public function testGenerateSlugTransliteratesGermanCharacters(): void {
        $this->assertSame('kueche', RoomService::generateSlug('Küche'));
        $this->assertSame('aussenbereich', RoomService::generateSlug('Außenbereich'));
        $this->assertSame('buero', RoomService::generateSlug('Büro'));
        $this->assertSame('ae-oe-ue', RoomService::generateSlug('Ä Ö Ü'));
    }

    public function testGenerateSlugBasics(): void {
        $this->assertSame('test-room', RoomService::generateSlug('Test Room'));
        $this->assertSame('meeting-room-1', RoomService::generateSlug('Meeting Room 1'));
        // Non-transliterable input falls back
        $this->assertSame('room', RoomService::generateSlug('日本語'));
        $this->assertSame('group', RoomService::generateSlug('', 'group'));
    }

    // ── External feed secret (issue #16) ─────────────────────────────────

    /** Mock a single room stored under room/{id} plus the rooms_index. */
    private function mockSingleRoom(array $room): void {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) use ($room) {
                if ($key === 'rooms_index') {
                    return json_encode([$room['id']]);
                }
                if ($key === 'room/' . $room['id']) {
                    return json_encode($room);
                }
                return $default;
            });
    }

    public function testRotateFeedSecretGeneratesPrefixedSecretAndEnables(): void {
        $this->mockSingleRoom(['id' => 'room1', 'name' => 'Room 1', 'feedEnabled' => false, 'feedSecret' => null]);
        $this->secureRandom->method('generate')->willReturn('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');

        $captured = null;
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) use (&$captured) {
                if ($key === 'room/room1') {
                    $captured = json_decode($value, true);
                }
                return true;
            });

        $secret = $this->service->rotateFeedSecret('room1');

        $this->assertStringStartsWith('rvxf_', $secret);
        $this->assertSame($secret, $captured['feedSecret']);
        $this->assertTrue($captured['feedEnabled']);
    }

    public function testRotateFeedSecretReturnsNullForMissingRoom(): void {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->assertNull($this->service->rotateFeedSecret('nope'));
    }

    public function testDisableFeedClearsSecret(): void {
        $this->mockSingleRoom(['id' => 'room1', 'name' => 'Room 1', 'feedEnabled' => true, 'feedSecret' => 'rvxf_x']);

        $captured = null;
        $this->appConfig->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) use (&$captured) {
                if ($key === 'room/room1') {
                    $captured = json_decode($value, true);
                }
                return true;
            });

        $this->assertTrue($this->service->disableFeed('room1'));
        $this->assertFalse($captured['feedEnabled']);
        $this->assertNull($captured['feedSecret']);
    }

    public function testFindRoomByFeedSecretMatchesEnabledRoom(): void {
        $this->mockSingleRoom(['id' => 'room1', 'name' => 'Room 1', 'feedEnabled' => true, 'feedSecret' => 'rvxf_match']);

        $room = $this->service->findRoomByFeedSecret('rvxf_match');
        $this->assertNotNull($room);
        $this->assertSame('room1', $room['id']);
    }

    public function testFindRoomByFeedSecretRejectsWrongSecret(): void {
        $this->mockSingleRoom(['id' => 'room1', 'name' => 'Room 1', 'feedEnabled' => true, 'feedSecret' => 'rvxf_match']);
        $this->assertNull($this->service->findRoomByFeedSecret('rvxf_nope'));
    }

    public function testFindRoomByFeedSecretIgnoresDisabledRoom(): void {
        $this->mockSingleRoom(['id' => 'room1', 'name' => 'Room 1', 'feedEnabled' => false, 'feedSecret' => 'rvxf_match']);
        $this->assertNull($this->service->findRoomByFeedSecret('rvxf_match'));
    }

    public function testFindRoomByFeedSecretRejectsWrongPrefix(): void {
        // Wrong prefix short-circuits before any room lookup.
        $this->assertNull($this->service->findRoomByFeedSecret('rvx_notafeed'));
    }
}
