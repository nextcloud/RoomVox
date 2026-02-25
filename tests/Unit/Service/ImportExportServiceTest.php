<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\ImportExportService;
use OCA\RoomVox\Service\RoomService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportExportServiceTest extends TestCase {
    private ImportExportService $service;

    protected function setUp(): void {
        $roomService = $this->createMock(RoomService::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new ImportExportService($roomService, $logger);
    }

    // ── normalizeFacility (via reflection since it's private) ──

    public function testNormalizeFacilityDirectMatch(): void {
        $this->assertSame('projector', $this->callNormalize('projector'));
        $this->assertSame('whiteboard', $this->callNormalize('Whiteboard'));
        $this->assertSame('video-conference', $this->callNormalize('video-conference'));
    }

    public function testNormalizeFacilityAlias(): void {
        $this->assertSame('projector', $this->callNormalize('beamer'));
        $this->assertSame('display-screen', $this->callNormalize('tv'));
        $this->assertSame('display-screen', $this->callNormalize('monitor'));
        $this->assertSame('audio-system', $this->callNormalize('speakers'));
        $this->assertSame('wheelchair-accessible', $this->callNormalize('wheelchair'));
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

    // ── Private method helpers ──

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
