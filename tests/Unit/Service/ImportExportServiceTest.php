<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\ImportExportService;
use OCA\RoomVox\Service\RoomService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportExportServiceTest extends TestCase {
    private ImportExportService $service;

    protected function setUp(): void {
        $roomService = $this->createMock(RoomService::class);
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new ImportExportService($roomService, $appConfig, $logger);
    }

    // ── normalizeFacility (via reflection since it's private) ──

    public function testNormalizeFacilityDirectMatch(): void {
        $this->assertSame('projector', $this->callNormalize('projector'));
        $this->assertSame('whiteboard', $this->callNormalize('Whiteboard'));
        $this->assertSame('videoconf', $this->callNormalize('video-conference'));
    }

    public function testNormalizeFacilityAlias(): void {
        $this->assertSame('projector', $this->callNormalize('beamer'));
        $this->assertSame('display', $this->callNormalize('tv'));
        $this->assertSame('display', $this->callNormalize('monitor'));
        $this->assertSame('audio', $this->callNormalize('speakers'));
        $this->assertSame('wheelchair', $this->callNormalize('wheelchair'));
    }

    public function testNormalizeFacilityUnknown(): void {
        // Unknown facilities are returned as-is (lowercase)
        $this->assertSame('coffee-machine', $this->callNormalize('coffee-machine'));
    }

    public function testNormalizeFacilityEmpty(): void {
        $this->assertNull($this->callNormalize(''));
        $this->assertNull($this->callNormalize('   '));
    }

    // ── detectDelimiter ──

    public function testDetectDelimiterComma(): void {
        $this->assertSame(',', $this->callDetectDelimiter('name,email,capacity,roomType'));
    }

    public function testDetectDelimiterSemicolon(): void {
        $this->assertSame(';', $this->callDetectDelimiter('name;email;capacity;roomType'));
    }

    public function testDetectDelimiterTab(): void {
        $this->assertSame("\t", $this->callDetectDelimiter("name\temail\tcapacity\troomType"));
    }

    public function testDetectDelimiterNoDelimiter(): void {
        $this->assertSame(',', $this->callDetectDelimiter('singlecolumn'));
    }

    // ── detectColumnMapping ──

    public function testDetectColumnMappingRoomVox(): void {
        $result = $this->callDetectColumnMapping(['name', 'email', 'capacity']);
        $this->assertSame('roomvox', $result['format']);
        $this->assertSame('name', $result['map']['name']);
    }

    public function testDetectColumnMappingMs365(): void {
        $result = $this->callDetectColumnMapping(['DisplayName', 'PrimarySmtpAddress', 'Capacity']);
        $this->assertSame('ms365', $result['format']);
        $this->assertSame('name', $result['map']['DisplayName']);
        $this->assertSame('email', $result['map']['PrimarySmtpAddress']);
    }

    public function testDetectColumnMappingUnknown(): void {
        $result = $this->callDetectColumnMapping(['foo', 'bar']);
        $this->assertSame('unknown', $result['format']);
    }

    // ── parseAddress ──

    public function testParseAddressFull(): void {
        $result = $this->callParseAddress('Building A, Heidelberglaan 8, 3584 CS, Utrecht');
        $this->assertSame('Building A', $result['building']);
        $this->assertSame('Heidelberglaan 8', $result['street']);
        $this->assertSame('3584 CS', $result['postalCode']);
        $this->assertSame('Utrecht', $result['city']);
    }

    public function testParseAddressThreeParts(): void {
        $result = $this->callParseAddress('Tower B, Stationsplein 1, Amsterdam');
        $this->assertSame('Tower B', $result['building']);
        $this->assertSame('Stationsplein 1', $result['street']);
        $this->assertSame('Amsterdam', $result['city']);
    }

    public function testParseAddressThreePartsWithPostalCode(): void {
        $result = $this->callParseAddress('Building X, Main St, 1234 AB');
        $this->assertSame('Building X', $result['building']);
        $this->assertSame('Main St', $result['street']);
        $this->assertSame('1234 AB', $result['postalCode']);
    }

    public function testParseAddressEmpty(): void {
        $result = $this->callParseAddress('');
        $this->assertSame('', $result['building']);
        $this->assertSame('', $result['street']);
    }

    // ── camelCase CSV headers (issue #29) ──

    /**
     * RoomVox exports camelCase headers, so its own export must import back.
     * detectColumnMapping() used to lowercase every header, which no longer
     * matched the camelCase field keys in mapRow() — silently dropping
     * roomNumber, roomType, postalCode and autoAccept.
     */
    public function testRoomVoxHeadersMapToCanonicalFieldNames(): void {
        $header = [
            'name', 'email', 'capacity', 'roomNumber', 'floor', 'roomType',
            'building', 'street', 'postalCode', 'city',
            'facilities', 'description', 'autoAccept', 'active',
        ];

        $mapping = $this->callDetectColumnMapping($header);

        $this->assertSame('roomvox', $mapping['format']);
        foreach ($header as $col) {
            $this->assertSame(
                $col,
                $mapping['map'][$col],
                "Header {$col} must map to its canonical field name (#29)"
            );
        }
    }

    public function testHeaderMatchingStaysCaseInsensitive(): void {
        $mapping = $this->callDetectColumnMapping(['name', 'ROOMNUMBER', 'PostalCode']);

        $this->assertSame('roomNumber', $mapping['map']['ROOMNUMBER']);
        $this->assertSame('postalCode', $mapping['map']['PostalCode']);
    }

    /**
     * The exact CSV from issue #29 — every column filled, autoAccept true.
     * Walks the real chain: detect mapping → mapRow → buildRoomData.
     */
    public function testIssue29CsvImportsEveryField(): void {
        $header = [
            'name', 'email', 'capacity', 'roomNumber', 'floor', 'roomType',
            'building', 'street', 'postalCode', 'city',
            'facilities', 'description', 'autoAccept', 'active',
        ];
        $fields = [
            'Test Room', 'test-room@example.org', '120', '101', '13', 'parking-space',
            'Test Building', '1 Test Street', 'T1010', 'Testville',
            'audio,whiteboard', 'The very worst room there is.', 'true', 'true',
        ];

        $mapping = $this->callDetectColumnMapping($header);
        $row = $this->callMapRow($fields, $header, $mapping['map']);
        $roomData = $this->callBuildRoomData($row);

        // The four fields reported as lost
        $this->assertSame('101', $roomData['roomNumber']);
        $this->assertSame('parking-space', $roomData['roomType']);
        $this->assertTrue($roomData['autoAccept']);
        $this->assertStringContainsString('T1010', $roomData['address']);

        // …and the rest of the row must still arrive
        $this->assertSame('Test Room', $roomData['name']);
        $this->assertSame('test-room@example.org', $roomData['email']);
        $this->assertSame(120, $roomData['capacity']);
        $this->assertSame('13', $roomData['floor']);
        $this->assertSame('The very worst room there is.', $roomData['description']);
        $this->assertTrue($roomData['active']);
        $this->assertContains('audio', $roomData['facilities']);
        $this->assertContains('whiteboard', $roomData['facilities']);
    }

    /**
     * autoAccept=false must survive too — a dropped column previously left the
     * room on its default rather than the value in the CSV.
     */
    public function testAutoAcceptFalseIsImported(): void {
        $header = ['name', 'email', 'autoAccept'];
        $fields = ['Test Room', 'test-room@example.org', 'false'];

        $mapping = $this->callDetectColumnMapping($header);
        $row = $this->callMapRow($fields, $header, $mapping['map']);
        $roomData = $this->callBuildRoomData($row);

        $this->assertFalse($roomData['autoAccept']);
    }

    /** MS365 headers must keep taking precedence over the RoomVox mapping. */
    public function testMs365MappingStillWins(): void {
        $mapping = $this->callDetectColumnMapping(['DisplayName', 'PostalCode', 'Tags']);

        $this->assertSame('ms365', $mapping['format']);
        $this->assertSame('name', $mapping['map']['DisplayName']);
        $this->assertSame('postalCode', $mapping['map']['PostalCode']);
        $this->assertSame('facilities', $mapping['map']['Tags']);
    }

    // ── Private method helpers ──

    private function callMapRow(array $fields, array $header, array $columnMap): array {
        $method = new \ReflectionMethod($this->service, 'mapRow');
        return $method->invoke($this->service, $fields, $header, $columnMap);
    }

    private function callBuildRoomData(array $data): array {
        $method = new \ReflectionMethod($this->service, 'buildRoomData');
        return $method->invoke($this->service, $data);
    }

    private function callNormalize(string $facility): ?string {
        $method = new \ReflectionMethod($this->service, 'normalizeFacility');
        return $method->invoke($this->service, $facility);
    }

    private function callDetectDelimiter(string $headerLine): string {
        $method = new \ReflectionMethod($this->service, 'detectDelimiter');
        return $method->invoke($this->service, $headerLine);
    }

    private function callDetectColumnMapping(array $header): array {
        $method = new \ReflectionMethod($this->service, 'detectColumnMapping');
        return $method->invoke($this->service, $header);
    }

    private function callParseAddress(string $address): array {
        $method = new \ReflectionMethod($this->service, 'parseAddress');
        return $method->invoke($this->service, $address);
    }
}
