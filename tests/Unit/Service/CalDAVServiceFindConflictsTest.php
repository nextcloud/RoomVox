<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * findConflicts() checks the local calendar per occurrence and Exchange once
 * for the whole span of a recurring booking (issue #46).
 */
class CalDAVServiceFindConflictsTest extends TestCase {
    private CalDavBackend $backend;
    private ExchangeSyncService $exchange;
    private CalDAVService $service;

    private array $room = ['id' => 'r1', 'userId' => 'rb_r1', 'exchangeConfig' => ['resourceEmail' => 'r1@company.com']];

    protected function setUp(): void {
        $this->backend = $this->createMock(CalDavBackend::class);
        $this->backend->method('getCalendarsForUser')->willReturn([['id' => 1, 'uri' => 'calendar']]);
        $this->backend->method('calendarQuery')->willReturn([]);
        $this->exchange = $this->createMock(ExchangeSyncService::class);

        $this->service = new CalDAVService($this->backend, $this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class));
        $this->service->setExchangeSyncService($this->exchange);
    }

    /** @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> */
    private function weekly(int $count): array {
        $occurrences = [];
        for ($i = 0; $i < $count; $i++) {
            $start = new \DateTimeImmutable('2026-10-05T08:00:00Z +' . $i . ' weeks');
            $occurrences[] = [$start, $start->modify('+1 hour')];
        }
        return $occurrences;
    }

    public function testExchangeIsAskedOnceForTheWholeSpan(): void {
        $occurrences = $this->weekly(52);
        $this->exchange->expects($this->once())
            ->method('exchangeBusyIntervals')
            ->with($this->room, $occurrences[0][0], $occurrences[51][1], 'uid-1')
            ->willReturn([[$occurrences[10][0], $occurrences[10][1]]]);

        $conflicts = $this->service->findConflicts('rb_r1', $occurrences, 'uid-1', $this->room);

        $this->assertSame([$occurrences[10]], $conflicts);
    }

    /**
     * Graph allows a calendarView window of five years at most and long
     * windows time out, so a long series is asked a year at a time; a window
     * that fails does not drop the others.
     */
    public function testLongSeriesIsAskedAYearAtATime(): void {
        $occurrences = $this->weekly(156); // three years
        $windows = [];
        $this->exchange->method('exchangeBusyIntervals')->willReturnCallback(
            function (array $room, \DateTimeInterface $from, \DateTimeInterface $to) use (&$windows, $occurrences) {
                $windows[] = [$from, $to];
                if (count($windows) === 1) {
                    return null; // the first year fails
                }
                return [[$occurrences[150][0], $occurrences[150][1]]];
            }
        );

        $conflicts = $this->service->findConflicts('rb_r1', $occurrences, null, $this->room);

        $this->assertGreaterThanOrEqual(3, count($windows));
        foreach ($windows as [$from, $to]) {
            $this->assertLessThanOrEqual(366, $from->diff($to)->days);
        }
        $this->assertEquals($occurrences[0][0], $windows[0][0]);
        $this->assertEquals($occurrences[155][1], end($windows)[1]);
        $this->assertSame([$occurrences[150]], $conflicts);
    }

    public function testUnreachableExchangeFallsBackToLocal(): void {
        $this->exchange->method('exchangeBusyIntervals')->willReturn(null);

        $this->assertSame([], $this->service->findConflicts('rb_r1', $this->weekly(3), null, $this->room));
    }

    public function testNoOccurrencesNoQueries(): void {
        $this->exchange->expects($this->never())->method('exchangeBusyIntervals');

        $this->assertSame([], $this->service->findConflicts('rb_r1', [], null, $this->room));
    }
}
