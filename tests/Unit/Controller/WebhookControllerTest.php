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
        $logger = $this->createMock(LoggerInterface::class);

        // Default: max 1 inline sync
        $this->appConfig->method('getValueString')
            ->willReturn('1');

        $this->controller = new WebhookController(
            'roomvox',
            $this->request,
            $this->webhookService,
            $this->syncService,
            $this->roomService,
            $this->jobList,
            $this->appConfig,
            $logger,
        );
    }

    private function buildController(string $maxInline = '1'): WebhookController {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn($maxInline);

        return new WebhookController(
            'roomvox',
            $this->request,
            $this->webhookService,
            $this->syncService,
            $this->roomService,
            $this->jobList,
            $appConfig,
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

        // No body → bad request
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

        // Should NOT queue a background job
        $this->jobList->expects($this->never())
            ->method('add');

        // Simulate php://input via a custom stream — we test the flow via mocks
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

        // Should never attempt sync
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

        // Should queue a background job as fallback
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

    // ── Throttle: multiple rooms, max 1 inline ─────────────────────

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
        // Only 1 inline sync (max=1), should call pullExchangeChanges exactly once
        $this->syncService->expects($this->once())
            ->method('pullExchangeChanges')
            ->willReturn($result);

        // Should queue 2 background jobs for the remaining rooms
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

    // ── Throttle: max 3 → all inline ───────────────────────────────

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

        // No background jobs needed
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

    // ── Throttle: max 0 → all queued ───────────────────────────────

    public function testThrottleMax0AllQueued(): void {
        $controller = $this->buildController('0');

        $this->request->method('getParam')
            ->with('validationToken')
            ->willReturn(null);

        $this->webhookService->method('findRoomBySubscriptionId')
            ->willReturn($this->testRoom);

        // No inline sync at all
        $this->syncService->expects($this->never())
            ->method('pullExchangeChanges');

        // All queued
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

        // Should sync only once despite 3 notifications for same room
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

    // ── Helper ─────────────────────────────────────────────────────

    /**
     * Call receive() with a simulated JSON payload via php://input stream wrapper.
     */
    private function callReceiveWithPayload(array $payload, ?WebhookController $controller = null): \OCP\AppFramework\Http\Response {
        $json = json_encode($payload);

        // Register a custom stream wrapper to simulate php://input
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
        // Only handle php://input
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
