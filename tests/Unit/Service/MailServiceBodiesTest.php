<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\RoomVox\Service\MailService;
use OCA\RoomVox\Service\PermissionService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
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
            $this->createMock(IUserManager::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * Body builders take an IL10N first (issue #24). No IFactory is injected
     * here, so getL10n() hands back the pass-through translator and the
     * assertions below still read the English source strings.
     */
    private function callBody(string $name, ...$args): string {
        $l10n = new \ReflectionMethod($this->service, 'getL10n');
        $l = $l10n->invoke($this->service, null);

        $method = new \ReflectionMethod($this->service, $name);
        return $method->invoke($this->service, $l, ...$args);
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

    public function testRespondCancelledBodySeriesVariant(): void {
        $room = ['name' => 'Room A'];
        $body = $this->callBody('buildRespondCancelledBody', $room, $this->sampleEvent(), null);

        $this->assertStringContainsString('Your booking has been canceled', $body);
        $this->assertStringContainsString('Room A', $body);
        $this->assertStringContainsString('Weekly standup', $body);
        $this->assertStringNotContainsString('single occurrence', $body);
        $this->assertStringNotContainsString('recurring series continues', $body);
    }

    public function testRespondCancelledBodyOccurrenceVariant(): void {
        $room = ['name' => 'Room A'];
        $body = $this->callBody(
            'buildRespondCancelledBody',
            $room,
            $this->sampleEvent(),
            'Monday, June 10, 2026 09:00',
        );

        $this->assertStringContainsString('single occurrence', $body);
        $this->assertStringContainsString('Monday, June 10, 2026 09:00', $body);
        $this->assertStringContainsString('series continues', $body);
        $this->assertStringContainsString('Room A', $body);
    }

    // ── Recurring bookings (issue #46) ─────────────────────────────

    public function testSeriesConflictListsTheConflictingDates(): void {
        $body = $this->callBody('buildConflictBody', ['name' => 'Room 1'], $this->sampleEvent(), [
            new \DateTimeImmutable('2026-10-19T08:00:00Z'),
            new \DateTimeImmutable('2026-11-02T09:00:00Z'),
        ]);

        $this->assertStringContainsString("Conflicting dates:\n- Monday, October 19, 2026 08:00 (UTC)\n- Monday, November 2, 2026 09:00 (UTC)\n", $body);
        $this->assertStringContainsString('the whole series was declined', $body);
    }

    public function testLongListsAreCutOffWithACount(): void {
        $dates = [];
        for ($week = 0; $week < 13; $week++) {
            $dates[] = new \DateTimeImmutable('2026-10-05T08:00:00Z +' . $week . ' weeks');
        }

        $body = $this->callBody('buildConflictBody', ['name' => 'Room 1'], $this->sampleEvent(), $dates);

        $this->assertSame(10, substr_count($body, "\n- "));
        $this->assertStringContainsString('and 3 more dates', $body);
    }

    /** An all-day series is listed as dates, not shifted to 02:00 or the day before. */
    public function testAllDaySeriesDatesAreListedAsDates(): void {
        $event = $this->sampleEvent() + ['allDay' => true, 'wallClock' => true];

        $body = $this->callBody('buildConflictBody', ['name' => 'Room 1'], $event, [
            new \DateTimeImmutable('2026-10-19T00:00:00Z'),
        ]);

        $this->assertStringContainsString("- Monday, October 19, 2026\n", $body);
    }

    public function testSingleConflictKeepsTheOriginalWording(): void {
        $body = $this->callBody('buildConflictBody', ['name' => 'Room 1'], $this->sampleEvent());

        $this->assertStringContainsString('The room is already booked for this time slot.', $body);
        $this->assertStringNotContainsString('Conflicting dates', $body);
    }

    public function testAvailabilityViolationListsTheDatesOutsideTheHours(): void {
        $body = $this->callBody('buildAvailabilityViolationBody', ['name' => 'Room 1'], $this->sampleEvent(), [
            new \DateTimeImmutable('2026-10-17T08:00:00Z'),
        ]);

        $this->assertStringContainsString("Dates outside the availability hours:\n- Saturday, October 17, 2026 08:00 (UTC)\n", $body);
    }
}
