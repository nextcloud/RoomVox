<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service\Exchange;

use OCA\RoomVox\Service\Exchange\GraphApiClient;
use OCA\RoomVox\Service\Exchange\WebhookService;
use OCA\RoomVox\Service\RoomService;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookServiceTest extends TestCase {
    private WebhookService $service;
    private IURLGenerator $urlGenerator;

    protected function setUp(): void {
        $graphClient = $this->createMock(GraphApiClient::class);
        $roomService = $this->createMock(RoomService::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $secureRandom = $this->createMock(ISecureRandom::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new WebhookService(
            $graphClient,
            $roomService,
            $this->urlGenerator,
            $secureRandom,
            $logger,
        );
    }

    public function testNeedsRenewalExpired(): void {
        $room = [
            'exchangeConfig' => [
                'webhookExpiresAt' => (new \DateTimeImmutable('-1 hour'))->format('c'),
            ],
        ];

        $this->assertTrue($this->service->needsRenewal($room));
    }

    public function testNeedsRenewalExpiringWithin36Hours(): void {
        $room = [
            'exchangeConfig' => [
                'webhookExpiresAt' => (new \DateTimeImmutable('+12 hours'))->format('c'),
            ],
        ];

        $this->assertTrue($this->service->needsRenewal($room));
    }

    public function testNeedsRenewalFresh(): void {
        $room = [
            'exchangeConfig' => [
                'webhookExpiresAt' => (new \DateTimeImmutable('+48 hours'))->format('c'),
            ],
        ];

        $this->assertFalse($this->service->needsRenewal($room));
    }

    public function testNeedsRenewalNoSubscription(): void {
        $room = [
            'exchangeConfig' => [
                'webhookExpiresAt' => null,
            ],
        ];

        $this->assertFalse($this->service->needsRenewal($room));
    }

    public function testGetNotificationUrlHttps(): void {
        $this->urlGenerator->method('getAbsoluteURL')
            ->with('')
            ->willReturn('https://cloud.example.com/');

        $url = $this->service->getNotificationUrl();
        $this->assertSame(
            'https://cloud.example.com/index.php/apps/roomvox/api/webhook/exchange',
            $url,
        );
    }

    public function testGetNotificationUrlRejectsHttp(): void {
        $this->urlGenerator->method('getAbsoluteURL')
            ->with('')
            ->willReturn('http://localhost/');

        $this->assertNull($this->service->getNotificationUrl());
    }
}
