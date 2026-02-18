<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\BackgroundJob\WebhookSyncJob;
use OCA\RoomVox\Service\Exchange\WebhookService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class WebhookController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private WebhookService $webhookService,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Receive webhook notifications from Microsoft Graph.
     * Handles the validation handshake and change notifications.
     *
     * Microsoft requires a response within 3 seconds — so we queue the
     * actual sync as a background job instead of running it inline.
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

            // Queue sync as background job (runs at next cron tick)
            $this->jobList->add(WebhookSyncJob::class, ['roomId' => $room['id']]);
            $this->logger->info("Webhook: Queued sync for room {$room['id']}");
        }

        return new DataResponse(null, Http::STATUS_ACCEPTED);
    }
}
