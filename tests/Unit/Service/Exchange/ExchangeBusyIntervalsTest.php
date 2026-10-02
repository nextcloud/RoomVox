<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Service\Exchange;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\Exchange\ExchangeApiException;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\Exchange\GraphApiClient;
use OCA\RoomVox\Service\RoomService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * exchangeBusyIntervals() asks Exchange once for a whole span, so a recurring
 * booking is checked against all of its dates without a Graph call per date
 * (issue #46). It follows paging and sends the span in UTC.
 */
class ExchangeBusyIntervalsTest extends TestCase {
    private GraphApiClient $graphClient;
    private ExchangeSyncService $service;

    private array $room = [
        'id' => 'testex',
        'userId' => 'rb_testex',
        'exchangeConfig' => ['resourceEmail' => 'room@company.com', 'syncEnabled' => true],
    ];

    protected function setUp(): void {
        $this->graphClient = $this->createMock(GraphApiClient::class);
        $this->graphClient->method('isConfigured')->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new ExchangeSyncService(
            $this->graphClient,
            new CalDAVService($this->createMock(CalDavBackend::class), $this->createMock(IUserManager::class), $logger),
            $this->createMock(RoomService::class),
            $logger,
        );
    }

    private function event(string $start, string $end, array $overrides = []): array {
        return array_merge([
            'id' => 'ex-' . $start,
            'start' => ['dateTime' => $start, 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $end, 'timeZone' => 'UTC'],
            'isCancelled' => false,
            'showAs' => 'busy',
            'singleValueExtendedProperties' => [],
        ], $overrides);
    }

    public function testAllPagesAreReadAndFreeOrCancelledEventsSkipped(): void {
        $this->graphClient->expects($this->once())->method('get')->willReturn([
            'value' => [
                $this->event('2026-10-05T08:00:00', '2026-10-05T09:00:00'),
                $this->event('2026-10-06T08:00:00', '2026-10-06T09:00:00', ['showAs' => 'free']),
            ],
            '@odata.nextLink' => 'https://graph.example/next-page',
        ]);
        $this->graphClient->expects($this->once())->method('getUrl')->with('https://graph.example/next-page')->willReturn([
            'value' => [
                $this->event('2026-11-02T08:00:00', '2026-11-02T09:00:00'),
                $this->event('2026-11-03T08:00:00', '2026-11-03T09:00:00', ['isCancelled' => true]),
            ],
        ]);

        $busy = $this->service->exchangeBusyIntervals(
            $this->room,
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
            new \DateTimeImmutable('2026-12-01T00:00:00Z'),
        );

        $this->assertSame(
            ['2026-10-05T08:00:00+00:00', '2026-11-02T08:00:00+00:00'],
            array_map(fn (array $b) => $b[0]->format('c'), $busy),
        );
    }

    /** The span is sent in UTC; a `Z` behind local time would shift it (cf. #45). */
    public function testSpanIsSentInUtc(): void {
        $this->graphClient->expects($this->once())->method('get')
            ->with($this->anything(), $this->callback(fn (array $params) =>
                $params['startDateTime'] === '2026-10-05T07:00:00Z' && $params['endDateTime'] === '2026-10-05T08:00:00Z'))
            ->willReturn(['value' => []]);

        $this->service->exchangeBusyIntervals(
            $this->room,
            new \DateTimeImmutable('2026-10-05T09:00:00+02:00'),
            new \DateTimeImmutable('2026-10-05T10:00:00+02:00'),
        );
    }

    public function testUnreachableExchangeIsReportedAsNull(): void {
        $this->graphClient->method('get')->willThrowException(new ExchangeApiException('timeout'));

        $this->assertNull($this->service->exchangeBusyIntervals(
            $this->room,
            new \DateTimeImmutable('2026-10-05T08:00:00Z'),
            new \DateTimeImmutable('2026-10-05T09:00:00Z'),
        ));
    }

    public function testNonExchangeRoomHasNoBusyIntervals(): void {
        $this->graphClient->expects($this->never())->method('get');

        $this->assertSame([], $this->service->exchangeBusyIntervals(
            ['id' => 'plain', 'userId' => 'rb_plain'],
            new \DateTimeImmutable('2026-10-05T08:00:00Z'),
            new \DateTimeImmutable('2026-10-05T09:00:00Z'),
        ));
    }
}
