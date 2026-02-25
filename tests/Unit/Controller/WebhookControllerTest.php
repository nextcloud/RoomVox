<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Controller;

use OCA\RoomVox\Controller\WebhookController;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\Exchange\SyncResult;
use OCA\RoomVox\Service\Exchange\WebhookService;
use OCA\RoomVox\Service\RoomService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookControllerTest extends TestCase {
    private WebhookController $controller;
    private WebhookService $webhookService;
    private ExchangeSyncService $syncService;
    private RoomService $roomService;
    private IJobList $jobList;
    private IAppConfig $appConfig;
    private ICache $cache;
    private IRequest $request;

    private array $testRoom = [
        'id' => 'room1',
        'exchangeConfig' => [
            'resourceEmail' => 'room@company.com',
            'syncEnabled' => true,
            'webhookSubscriptionId' => 'sub-123',
            'webhookClientState' => 'secret-state-abc',
        ],
    ];

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->webhookService = $this->createMock(WebhookService::class);
        $this->syncService = $this->createMock(ExchangeSyncService::class);
        $this->roomService = $this->createMock(RoomService::class);
        $this->jobList = $this->createMock(IJobList::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->cache = $this->createMock(ICache::class);

        // Default: max 1 inline per request, max 5 per window
        $this->appConfig->method('getValueString')
            ->willReturnCallback(fn (string $app, string $key, string $default) => match ($key) {
                'exchange_webhook_max_inline_sync' => '1',
                'exchange_webhook_rate_limit' => '5',
                default => $default,
            });

        // Default: cache returns 0 (no previous syncs in window)
        $this->cache->method('get')->willReturn(0);
        $this->cache->method('set')->willReturn(true);

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($this->cache);

        $this->controller = new WebhookController(
            'roomvox',
            $this->request,
            $this->webhookService,
            $this->syncService,
            $this->roomService,
            $this->jobList,
            $this->appConfig,
            $cacheFactory,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function buildController(string $maxPerRequest = '1', string $rateLimit = '5', ?ICache $cache = null): WebhookController {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->willReturnCallback(fn (string $app, string $key, string $default) => match ($key) {
                'exchange_webhook_max_inline_sync' => $maxPerRequest,
                'exchange_webhook_rate_limit' => $rateLimit,
                default => $default,
            });

        $mockCache = $cache ?? $this->cache;
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($mockCache);

        return new WebhookController(
            'roomvox',
            $this->request,
            $this->webhookService,
            $this->syncService,
            $this->roomService,
            $this->jobList,
            $appConfig,
            $cacheFactory,
            $this->createMock(LoggerInterface::class),
        );
    }

    // ── Validation handshake ───────────────────────────────────────

    public function testValidationHandshakeReturnsToken(): void {
        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn('test-validation-token-123');

        $response = $this->controller->receive();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('test-validation-token-123', $response->render());
    }

    public function testValidationHandshakeEmptyToken(): void {
        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn('');

        $response = $this->controller->receive();
        $this->assertSame(400, $response->getStatus());
    }

    // ── Invalid payloads ───────────────────────────────────────────

    public function testInvalidJsonReturnsBadRequest(): void {
        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn(null);

        $response = $this->controller->receive();
        $this->assertSame(400, $response->getStatus());
    }

    // ── Inline sync ────────────────────────────────────────────────

    public function testSingleRoomSyncsInline(): void {
        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn(null);

        $this->webhookService->method('findRoomBySubscriptionId')
            ->with('sub-123')
            ->willReturn($this->testRoom);

        $this->roomService->method('getRoom')
            ->with('room1')
            ->willReturn($this->testRoom);

        $this->syncService->method('isExchangeRoom')
            ->willReturn(true);

        $result = new SyncResult();
        $result->created = 1;
        $this->syncService->expects($this->once())
            ->method('pullExchangeChanges')
            ->with($this->testRoom)
            ->willReturn($result);

        $this->jobList->expects($this->never())
            ->method('add');

        $response = $this->callReceiveWithPayload([
            'value' => [
                [
                    'subscriptionId' => 'sub-123',
                    'clientState' => 'secret-state-abc',
                    'changeType' => 'created',
                ],
            ],
        ]);

        $this->assertSame(202, $response->getStatus());
    }

    // ── Client state mismatch ──────────────────────────────────────

    public function testClientStateMismatchSkipsRoom(): void {
        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn(null);

        $this->webhookService->method('findRoomBySubscriptionId')
            ->with('sub-123')
            ->willReturn($this->testRoom);

        $this->syncService->expects($this->never())
            ->method('pullExchangeChanges');

        $response = $this->callReceiveWithPayload([
            'value' => [
                [
                    'subscriptionId' => 'sub-123',
                    'clientState' => 'wrong-state',
                    'changeType' => 'created',
                ],
            ],
        ]);

        $this->assertSame(202, $response->getStatus());
    }

    // ── Unknown subscription ───────────────────────────────────────

    public function testUnknownSubscriptionSkipped(): void {
        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn(null);

        $this->webhookService->method('findRoomBySubscriptionId')
            ->willReturn(null);

        $this->syncService->expects($this->never())
            ->method('pullExchangeChanges');

        $response = $this->callReceiveWithPayload([
            'value' => [
                [
                    'subscriptionId' => 'unknown-sub',
                    'clientState' => 'whatever',
                    'changeType' => 'created',
                ],
            ],
        ]);

        $this->assertSame(202, $response->getStatus());
    }

    // ── Inline sync failure → fallback to background job ───────────

    public function testInlineSyncFailureFallsBackToBackgroundJob(): void {
        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn(null);

        $this->webhookService->method('findRoomBySubscriptionId')
            ->willReturn($this->testRoom);

        $this->roomService->method('getRoom')
            ->willReturn($this->testRoom);

        $this->syncService->method('isExchangeRoom')
            ->willReturn(true);

        $this->syncService->method('pullExchangeChanges')
            ->willThrowException(new \RuntimeException('API timeout'));

        $this->jobList->expects($this->once())
            ->method('add');

        $response = $this->callReceiveWithPayload([
            'value' => [
                [
                    'subscriptionId' => 'sub-123',
                    'clientState' => 'secret-state-abc',
                    'changeType' => 'updated',
                ],
            ],
        ]);

        $this->assertSame(202, $response->getStatus());
    }

    // ── Per-request throttle: multiple rooms, max 1 inline ─────────

    public function testThrottleQueuesExcessRooms(): void {
        $room2 = [
            'id' => 'room2',
            'exchangeConfig' => [
                'resourceEmail' => 'room2@company.com',
                'syncEnabled' => true,
                'webhookSubscriptionId' => 'sub-456',
                'webhookClientState' => 'secret-state-def',
            ],
        ];

        $room3 = [
            'id' => 'room3',
            'exchangeConfig' => [
                'resourceEmail' => 'room3@company.com',
                'syncEnabled' => true,
                'webhookSubscriptionId' => 'sub-789',
                'webhookClientState' => 'secret-state-ghi',
            ],
        ];

        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn(null);

        $this->webhookService->method('findRoomBySubscriptionId')
            ->willReturnCallback(fn (string $id) => match ($id) {
                'sub-123' => $this->testRoom,
                'sub-456' => $room2,
                'sub-789' => $room3,
                default => null,
            });

        $this->roomService->method('getRoom')
            ->willReturnCallback(fn (string $id) => match ($id) {
                'room1' => $this->testRoom,
                'room2' => $room2,
                'room3' => $room3,
                default => null,
            });

        $this->syncService->method('isExchangeRoom')->willReturn(true);

        $result = new SyncResult();
        $this->syncService->expects($this->once())
            ->method('pullExchangeChanges')
            ->willReturn($result);

        $this->jobList->expects($this->exactly(2))
            ->method('add');

        $response = $this->callReceiveWithPayload([
            'value' => [
                ['subscriptionId' => 'sub-123', 'clientState' => 'secret-state-abc', 'changeType' => 'created'],
                ['subscriptionId' => 'sub-456', 'clientState' => 'secret-state-def', 'changeType' => 'created'],
                ['subscriptionId' => 'sub-789', 'clientState' => 'secret-state-ghi', 'changeType' => 'created'],
            ],
        ]);

        $this->assertSame(202, $response->getStatus());
    }

    // ── Per-request throttle: max 3 → all inline ───────────────────

    public function testThrottleMax3AllInline(): void {
        $room2 = [
            'id' => 'room2',
            'exchangeConfig' => [
                'resourceEmail' => 'room2@company.com',
                'syncEnabled' => true,
                'webhookSubscriptionId' => 'sub-456',
                'webhookClientState' => 'secret-state-def',
            ],
        ];

        $controller = $this->buildController('3');

        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn(null);

        $this->webhookService->method('findRoomBySubscriptionId')
            ->willReturnCallback(fn (string $id) => match ($id) {
                'sub-123' => $this->testRoom,
                'sub-456' => $room2,
                default => null,
            });

        $this->roomService->method('getRoom')
            ->willReturnCallback(fn (string $id) => match ($id) {
                'room1' => $this->testRoom,
                'room2' => $room2,
                default => null,
            });

        $this->syncService->method('isExchangeRoom')->willReturn(true);
        $this->syncService->expects($this->exactly(2))
            ->method('pullExchangeChanges')
            ->willReturn(new SyncResult());

        $this->jobList->expects($this->never())
            ->method('add');

        $response = $this->callReceiveWithPayload([
            'value' => [
                ['subscriptionId' => 'sub-123', 'clientState' => 'secret-state-abc', 'changeType' => 'created'],
                ['subscriptionId' => 'sub-456', 'clientState' => 'secret-state-def', 'changeType' => 'created'],
            ],
        ], $controller);

        $this->assertSame(202, $response->getStatus());
    }

    // ── Per-request throttle: max 0 → all queued ───────────────────

    public function testThrottleMax0AllQueued(): void {
        $controller = $this->buildController('0');

        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn(null);

        $this->webhookService->method('findRoomBySubscriptionId')
            ->willReturn($this->testRoom);

        $this->syncService->expects($this->never())
            ->method('pullExchangeChanges');

        $this->jobList->expects($this->once())
            ->method('add');

        $response = $this->callReceiveWithPayload([
            'value' => [
                ['subscriptionId' => 'sub-123', 'clientState' => 'secret-state-abc', 'changeType' => 'created'],
            ],
        ], $controller);

        $this->assertSame(202, $response->getStatus());
    }

    // ── Deduplication: multiple notifications for same room ─────────

    public function testDuplicateNotificationsDeduplicatedToOneSync(): void {
        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn(null);

        $this->webhookService->method('findRoomBySubscriptionId')
            ->willReturn($this->testRoom);

        $this->roomService->method('getRoom')
            ->willReturn($this->testRoom);

        $this->syncService->method('isExchangeRoom')->willReturn(true);

        $this->syncService->expects($this->once())
            ->method('pullExchangeChanges')
            ->willReturn(new SyncResult());

        $response = $this->callReceiveWithPayload([
            'value' => [
                ['subscriptionId' => 'sub-123', 'clientState' => 'secret-state-abc', 'changeType' => 'created'],
                ['subscriptionId' => 'sub-123', 'clientState' => 'secret-state-abc', 'changeType' => 'updated'],
                ['subscriptionId' => 'sub-123', 'clientState' => 'secret-state-abc', 'changeType' => 'deleted'],
            ],
        ]);

        $this->assertSame(202, $response->getStatus());
    }

    // ── Global rate limit ──────────────────────────────────────────

    public function testGlobalRateLimitExceededQueuesAll(): void {
        // Cache reports 5 syncs already done (limit = 5) → budget exhausted
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn(5);

        $controller = $this->buildController('1', '5', $cache);

        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn(null);

        $this->webhookService->method('findRoomBySubscriptionId')
            ->willReturn($this->testRoom);

        // No inline sync — rate limit hit
        $this->syncService->expects($this->never())
            ->method('pullExchangeChanges');

        // Queued instead
        $this->jobList->expects($this->once())
            ->method('add');

        $response = $this->callReceiveWithPayload([
            'value' => [
                ['subscriptionId' => 'sub-123', 'clientState' => 'secret-state-abc', 'changeType' => 'created'],
            ],
        ], $controller);

        $this->assertSame(202, $response->getStatus());
    }

    public function testGlobalRateLimitZeroQueuesAll(): void {
        // Rate limit set to 0 → all queued regardless
        $controller = $this->buildController('1', '0');

        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn(null);

        $this->webhookService->method('findRoomBySubscriptionId')
            ->willReturn($this->testRoom);

        $this->syncService->expects($this->never())
            ->method('pullExchangeChanges');

        $this->jobList->expects($this->once())
            ->method('add');

        $response = $this->callReceiveWithPayload([
            'value' => [
                ['subscriptionId' => 'sub-123', 'clientState' => 'secret-state-abc', 'changeType' => 'created'],
            ],
        ], $controller);

        $this->assertSame(202, $response->getStatus());
    }

    public function testGlobalRateLimitIncrementsCache(): void {
        // Cache starts at 3, limit is 5 → 2 slots available
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn(3);
        // Expect cache to be set to 4 (incremented)
        $cache->expects($this->once())
            ->method('set')
            ->with('webhook_inline_count', 4, 10);

        $controller = $this->buildController('1', '5', $cache);

        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn(null);

        $this->webhookService->method('findRoomBySubscriptionId')
            ->willReturn($this->testRoom);

        $this->roomService->method('getRoom')
            ->willReturn($this->testRoom);

        $this->syncService->method('isExchangeRoom')->willReturn(true);
        $this->syncService->expects($this->once())
            ->method('pullExchangeChanges')
            ->willReturn(new SyncResult());

        $response = $this->callReceiveWithPayload([
            'value' => [
                ['subscriptionId' => 'sub-123', 'clientState' => 'secret-state-abc', 'changeType' => 'created'],
            ],
        ], $controller);

        $this->assertSame(202, $response->getStatus());
    }

    // ── Performance: large payloads ─────────────────────────────────

    public function testPerformance50RoomsUnder100ms(): void {
        $this->assertBulkWebhookPerformance(50, 100);
    }

    public function testPerformance300RoomsUnder500ms(): void {
        $this->assertBulkWebhookPerformance(300, 500);
    }

    public function testPerformance300RoomsMaxInline5(): void {
        // With max 5 inline, 295 should be queued to background jobs
        $rooms = $this->generateRooms(300);
        $controller = $this->buildBulkController($rooms, '5', '5');

        $payload = ['value' => []];
        foreach ($rooms as $room) {
            $payload['value'][] = [
                'subscriptionId' => $room['exchangeConfig']['webhookSubscriptionId'],
                'clientState' => $room['exchangeConfig']['webhookClientState'],
                'changeType' => 'created',
            ];
        }

        $start = hrtime(true);
        $response = $this->callReceiveWithPayload($payload, $controller);
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertSame(202, $response->getStatus());
        $this->assertLessThan(500, $elapsed, "300 rooms with max 5 inline took {$elapsed}ms (limit: 500ms)");
    }

    private function assertBulkWebhookPerformance(int $roomCount, float $maxMs): void {
        $rooms = $this->generateRooms($roomCount);
        $controller = $this->buildBulkController($rooms, '1', '5');

        $payload = ['value' => []];
        foreach ($rooms as $room) {
            $payload['value'][] = [
                'subscriptionId' => $room['exchangeConfig']['webhookSubscriptionId'],
                'clientState' => $room['exchangeConfig']['webhookClientState'],
                'changeType' => 'created',
            ];
        }

        $start = hrtime(true);
        $response = $this->callReceiveWithPayload($payload, $controller);
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertSame(202, $response->getStatus());
        $this->assertLessThan($maxMs, $elapsed, "{$roomCount} rooms took {$elapsed}ms (limit: {$maxMs}ms)");
    }

    private function generateRooms(int $count): array {
        $rooms = [];
        for ($i = 1; $i <= $count; $i++) {
            $rooms["room{$i}"] = [
                'id' => "room{$i}",
                'exchangeConfig' => [
                    'resourceEmail' => "room{$i}@company.com",
                    'syncEnabled' => true,
                    'webhookSubscriptionId' => "sub-{$i}",
                    'webhookClientState' => "state-{$i}",
                ],
            ];
        }
        return $rooms;
    }

    private function buildBulkController(array $rooms, string $maxPerRequest, string $rateLimit): WebhookController {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->willReturnCallback(fn (string $app, string $key, string $default) => match ($key) {
                'exchange_webhook_max_inline_sync' => $maxPerRequest,
                'exchange_webhook_rate_limit' => $rateLimit,
                default => $default,
            });

        $webhookService = $this->createMock(WebhookService::class);
        $webhookService->method('findRoomBySubscriptionId')
            ->willReturnCallback(function (string $subId) use ($rooms): ?array {
                foreach ($rooms as $room) {
                    if ($room['exchangeConfig']['webhookSubscriptionId'] === $subId) {
                        return $room;
                    }
                }
                return null;
            });

        $roomService = $this->createMock(RoomService::class);
        $roomService->method('getRoom')
            ->willReturnCallback(fn (string $id) => $rooms[$id] ?? null);

        $syncService = $this->createMock(ExchangeSyncService::class);
        $syncService->method('isExchangeRoom')->willReturn(true);
        $syncService->method('pullExchangeChanges')->willReturn(new SyncResult());

        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn(0);
        $cache->method('set')->willReturn(true);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        return new WebhookController(
            'roomvox',
            $this->request,
            $webhookService,
            $syncService,
            $roomService,
            $this->createMock(IJobList::class),
            $appConfig,
            $cacheFactory,
            $this->createMock(LoggerInterface::class),
        );
    }

    // ── Helper ─────────────────────────────────────────────────────

    private function callReceiveWithPayload(array $payload, ?WebhookController $controller = null): \OCP\AppFramework\Http\Response {
        $json = json_encode($payload);

        stream_wrapper_unregister('php');
        stream_wrapper_register('php', PhpInputStreamMock::class);
        PhpInputStreamMock::$data = $json;

        try {
            return ($controller ?? $this->controller)->receive();
        } finally {
            stream_wrapper_restore('php');
        }
    }
}

/**
 * Custom stream wrapper to mock php://input for testing.
 */
class PhpInputStreamMock {
    public static string $data = '';
    private int $position = 0;

    /** @var resource */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
        if ($path !== 'php://input') {
            return false;
        }
        $this->position = 0;
        return true;
    }

    public function stream_read(int $count): string {
        $result = substr(self::$data, $this->position, $count);
        $this->position += strlen($result);
        return $result;
    }

    public function stream_eof(): bool {
        return $this->position >= strlen(self::$data);
    }

    public function stream_stat(): array {
        return ['size' => strlen(self::$data)];
    }
}
