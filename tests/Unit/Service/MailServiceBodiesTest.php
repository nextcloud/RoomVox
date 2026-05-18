<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the three new decline-mail body builders introduced for
 * issue #7 — horizon exceeded, availability violation, and sync in
 * progress. These exercise the private build*Body() helpers via
 * reflection (no IMailer needed).
 */
class MailServiceBodiesTest extends TestCase {
    private MailService $service;

    protected function setUp(): void {
        $this->service = new MailService(
            $this->createMock(IMailer::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(ICrypto::class),
            $this->createMock(PermissionService::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function callBody(string $name, ...$args): string {
        $method = new \ReflectionMethod($this->service, $name);
        return $method->invoke($this->service, ...$args);
    }

    private function sampleEvent(): array {
        return [
            'summary' => 'Weekly standup',
            'dtstartFormatted' => '2026-08-15 10:00',
            'dtendFormatted' => '2026-08-15 11:00',
            'organizerName' => 'Sebastian',
            'organizerEmail' => 'sebastian@example.com',
        ];
    }

    public function testHorizonBodyMentionsHorizonDays(): void {
        $room = ['name' => 'Room X', 'maxBookingHorizon' => 60];
        $body = $this->callBody('buildHorizonExceededBody', $room, $this->sampleEvent(), 60);

        $this->assertStringContainsString('60 days', $body);
        $this->assertStringContainsString('Room X', $body);
        $this->assertStringContainsString('Weekly standup', $body);
    }

    public function testHorizonBodyMentionsCutoffDate(): void {
        $room = ['name' => 'Room X', 'maxBookingHorizon' => 60];
        $body = $this->callBody('buildHorizonExceededBody', $room, $this->sampleEvent(), 60);

        $cutoff = (new \DateTimeImmutable('+60 days'))->format('Y-m-d');
        $this->assertStringContainsString($cutoff, $body);
    }

    public function testHorizonBodyHandlesZeroMaxDaysGracefully(): void {
        // Defensive: caller shouldn't pass 0 (horizon check would not have
        // triggered), but the body must not produce a nonsense "0 days" line.
        $room = ['name' => 'Room X', 'maxBookingHorizon' => 0];
        $body = $this->callBody('buildHorizonExceededBody', $room, $this->sampleEvent(), 0);

        $this->assertStringNotContainsString('0 days', $body);
        $this->assertStringContainsString('Room X', $body);
    }

    public function testAvailabilityBodyIncludesRulesSummary(): void {
        $room = [
            'name' => 'Room Y',
            'availabilityRules' => [
                'enabled' => true,
                'rules' => [
                    ['days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'startTime' => '09:00', 'endTime' => '17:00'],
                ],
            ],
        ];
        $body = $this->callBody('buildAvailabilityViolationBody', $room, $this->sampleEvent());

        $this->assertStringContainsString('availability hours', $body);
        $this->assertStringContainsString('Mon', $body);
        $this->assertStringContainsString('09:00', $body);
        $this->assertStringContainsString('17:00', $body);
    }

    public function testAvailabilityBodyWithoutRulesOmitsSummary(): void {
        $room = ['name' => 'Room Y', 'availabilityRules' => ['enabled' => false, 'rules' => []]];
        $body = $this->callBody('buildAvailabilityViolationBody', $room, $this->sampleEvent());

        $this->assertStringContainsString('availability hours', $body);
        $this->assertStringNotContainsString('available during', $body);
    }

    public function testSyncInProgressBodyMentionsTemporary(): void {
        $room = ['name' => 'Room Z'];
        $body = $this->callBody('buildSyncInProgressBody', $room, $this->sampleEvent());

        $this->assertStringContainsString('temporary', $body);
        $this->assertStringContainsString('Room Z', $body);
        $this->assertStringContainsString('try again', $body);
    }
}
