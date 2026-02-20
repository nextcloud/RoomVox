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
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class WebhookController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private WebhookService $webhookService,
        private ExchangeSyncService $syncService,
        private RoomService $roomService,
        private IJobList $jobList,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
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

        // Throttle: sync up to N rooms inline, queue the rest as background jobs
        $maxInline = (int) $this->appConfig->getValueString(
            Application::APP_ID,
            'exchange_webhook_max_inline_sync',
            '1'
        );
        $allRoomIds = array_keys($roomIds);
        $inlineRoomIds = array_slice($allRoomIds, 0, $maxInline);
        $queuedRoomIds = array_slice($allRoomIds, $maxInline);

        // Sync first batch inline for immediate delivery
        foreach ($inlineRoomIds as $roomId) {
            try {
                $room = $this->roomService->getRoom($roomId);
                if ($room === null || !$this->syncService->isExchangeRoom($room)) {
                    continue;
                }

                $result = $this->syncService->pullExchangeChanges($room);
                $this->logger->info("Webhook: Synced room {$roomId} inline: "
                    . "{$result->created} created, {$result->updated} updated, {$result->deleted} deleted");
            } catch (\Throwable $e) {
                // Inline sync failed — fall back to background job
                $this->logger->warning("Webhook: Inline sync failed for room {$roomId}, queuing background job: " . $e->getMessage());
                $this->jobList->add(WebhookSyncJob::class, ['roomId' => $roomId]);
            }
        }

        // Queue remaining rooms as background jobs
        foreach ($queuedRoomIds as $roomId) {
            $this->jobList->add(WebhookSyncJob::class, ['roomId' => $roomId]);
        }
        if (count($queuedRoomIds) > 0) {
            $this->logger->info("Webhook: Queued " . count($queuedRoomIds) . " rooms for background sync (max inline: {$maxInline})");
        }

        return new DataResponse(null, Http::STATUS_ACCEPTED);
    }
}
