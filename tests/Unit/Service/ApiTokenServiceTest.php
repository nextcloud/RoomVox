<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\ApiTokenService;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiTokenServiceTest extends TestCase {
    private ApiTokenService $service;

    protected function setUp(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $secureRandom = $this->createMock(ISecureRandom::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new ApiTokenService($appConfig, $secureRandom, $logger);
    }

    public function testHasScopeReadCanRead(): void {
        $token = ['scope' => 'read'];
        $this->assertTrue($this->service->hasScope($token, 'read'));
    }

    public function testHasScopeReadCannotBook(): void {
        $token = ['scope' => 'read'];
        $this->assertFalse($this->service->hasScope($token, 'book'));
        $this->assertFalse($this->service->hasScope($token, 'admin'));
    }

    public function testHasScopeBookCanReadAndBook(): void {
        $token = ['scope' => 'book'];
        $this->assertTrue($this->service->hasScope($token, 'read'));
        $this->assertTrue($this->service->hasScope($token, 'book'));
        $this->assertFalse($this->service->hasScope($token, 'admin'));
    }

    public function testHasScopeAdminCanDoAll(): void {
        $token = ['scope' => 'admin'];
        $this->assertTrue($this->service->hasScope($token, 'read'));
        $this->assertTrue($this->service->hasScope($token, 'book'));
        $this->assertTrue($this->service->hasScope($token, 'admin'));
    }

    public function testHasScopeUnknownScope(): void {
        $token = ['scope' => 'unknown'];
        $this->assertFalse($this->service->hasScope($token, 'read'));
    }

    public function testHasRoomAccessAllRooms(): void {
        $token = ['roomIds' => []];
        $this->assertTrue($this->service->hasRoomAccess($token, 'any-room'));
    }

    public function testHasRoomAccessSpecificRoomAllowed(): void {
        $token = ['roomIds' => ['room-a', 'room-b']];
        $this->assertTrue($this->service->hasRoomAccess($token, 'room-a'));
        $this->assertTrue($this->service->hasRoomAccess($token, 'room-b'));
    }

    public function testHasRoomAccessSpecificRoomDenied(): void {
        $token = ['roomIds' => ['room-a']];
        $this->assertFalse($this->service->hasRoomAccess($token, 'room-c'));
    }
}
