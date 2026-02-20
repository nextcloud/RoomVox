<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\AppInfo\Application;
use OCA\RoomVox\BackgroundJob\WebhookSyncJob;
use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\Exchange\WebhookService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class WebhookController extends Controller {
    private const RATE_LIMIT_CACHE_KEY = 'webhook_inline_count';
    private const RATE_LIMIT_WINDOW_SECONDS = 10;

    private ICache $cache;

    public function __construct(
        string $appName,
        IRequest $request,
        private WebhookService $webhookService,
        private ExchangeSyncService $syncService,
        private RoomService $roomService,
        private IJobList $jobList,
        private IAppConfig $appConfig,
        ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
        $this->cache = $cacheFactory->createDistributed('roomvox_webhook');
    }

    /**
     * Receive webhook notifications from Microsoft Graph.
     * Handles the validation handshake and change notifications.
     *
     * Runs the delta sync inline for immediate delivery. Falls back to
     * a queued background job if the inline sync fails.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function receive(): Response {
        // Handle validation handshake: Microsoft sends validationToken as query param
        $validationToken = $this->request->getParam('validationToken');
        if ($validationToken !== null && $validationToken !== '') {
            return new TextPlainResponse($validationToken);
        }

        // Handle change notification
        $body = file_get_contents('php://input');
        $payload = json_decode($body, true);

        if (!is_array($payload) || !isset($payload['value'])) {
            return new DataResponse(null, Http::STATUS_BAD_REQUEST);
        }

        // Collect unique room IDs from notifications
        $roomIds = [];
        foreach ($payload['value'] as $notification) {
            $clientState = $notification['clientState'] ?? '';
            $subscriptionId = $notification['subscriptionId'] ?? '';

            $room = $this->webhookService->findRoomBySubscriptionId($subscriptionId);
            if ($room === null) {
                $this->logger->warning("Webhook: Unknown subscription {$subscriptionId}");
                continue;
            }

            $expectedClientState = $room['exchangeConfig']['webhookClientState'] ?? '';
            if (!hash_equals($expectedClientState, $clientState)) {
                $this->logger->warning("Webhook: clientState mismatch for room {$room['id']}");
                continue;
            }

            $roomIds[$room['id']] = true;
        }

        // Per-request throttle: max rooms to sync inline in this request
        $maxPerRequest = (int) $this->appConfig->getValueString(
            Application::APP_ID,
            'exchange_webhook_max_inline_sync',
            '1'
        );
        // Global rate limit: max inline syncs across all requests in a time window
        $maxPerWindow = (int) $this->appConfig->getValueString(
            Application::APP_ID,
            'exchange_webhook_rate_limit',
            '5'
        );

        $allRoomIds = array_keys($roomIds);
        $inlineCount = 0;
        $queuedCount = 0;

        foreach ($allRoomIds as $roomId) {
            // Check both limits: per-request and global rate limit
            if ($inlineCount < $maxPerRequest && $this->acquireInlineSlot($maxPerWindow)) {
                try {
                    $room = $this->roomService->getRoom($roomId);
                    if ($room === null || !$this->syncService->isExchangeRoom($room)) {
                        continue;
                    }

                    $result = $this->syncService->pullExchangeChanges($room);
                    $this->logger->info("Webhook: Synced room {$roomId} inline: "
                        . "{$result->created} created, {$result->updated} updated, {$result->deleted} deleted");
                    $inlineCount++;
                } catch (\Throwable $e) {
                    $this->logger->warning("Webhook: Inline sync failed for room {$roomId}, queuing background job: " . $e->getMessage());
                    $this->jobList->add(WebhookSyncJob::class, ['roomId' => $roomId]);
                    $queuedCount++;
                }
            } else {
                $this->jobList->add(WebhookSyncJob::class, ['roomId' => $roomId]);
                $queuedCount++;
            }
        }

        if ($queuedCount > 0) {
            $this->logger->info("Webhook: Queued {$queuedCount} rooms for background sync (inline: {$inlineCount}, max/request: {$maxPerRequest}, max/window: {$maxPerWindow})");
        }

        return new DataResponse(null, Http::STATUS_ACCEPTED);
    }

    /**
     * Try to acquire an inline sync slot from the global rate limiter.
     * Returns true if a slot is available, false if the limit is reached.
     */
    private function acquireInlineSlot(int $maxPerWindow): bool {
        if ($maxPerWindow <= 0) {
            return false;
        }

        $current = (int) ($this->cache->get(self::RATE_LIMIT_CACHE_KEY) ?? 0);
        if ($current >= $maxPerWindow) {
            return false;
        }

        $this->cache->set(self::RATE_LIMIT_CACHE_KEY, $current + 1, self::RATE_LIMIT_WINDOW_SECONDS);
        return true;
    }
}
