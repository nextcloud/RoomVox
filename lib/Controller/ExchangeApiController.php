<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\Service\Exchange\ExchangeSyncService;
use OCA\RoomVox\Service\Exchange\GraphApiClient;
use OCA\RoomVox\Service\Exchange\WebhookService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ExchangeApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private GraphApiClient $graphClient,
        private ExchangeSyncService $syncService,
        private WebhookService $webhookService,
        private RoomService $roomService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Test the Exchange connection using the configured credentials.
     */
    public function testConnection(): JSONResponse {
        if (!$this->requireAdmin()) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        if (!$this->graphClient->isEnabled()) {
            return new JSONResponse(['error' => 'Exchange integration is not enabled'], 400);
        }

        $result = $this->graphClient->testConnection();
        return new JSONResponse($result);
    }

    /**
     * Validate that an email corresponds to a valid Exchange room resource.
     */
    public function validateResource(): JSONResponse {
        if (!$this->requireAdmin()) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $email = $this->request->getParam('email', '');
        if (empty($email)) {
            return new JSONResponse(['error' => 'Email is required'], 400);
        }

        $result = $this->syncService->validateResourceEmail($email);
        return new JSONResponse($result);
    }

    /**
     * Trigger a manual sync for a specific room.
     */
    public function syncRoom(string $id): JSONResponse {
        if (!$this->requireAdmin()) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $room = $this->roomService->getRoom($id);
        if ($room === null) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

        if (!$this->syncService->isExchangeRoom($room)) {
            return new JSONResponse(['error' => 'Room does not have Exchange sync configured'], 400);
        }

        $fullSync = $this->request->getParam('full', false);

        try {
            $result = $fullSync
                ? $this->syncService->fullSync($room)
                : $this->syncService->pullExchangeChanges($room);

            return new JSONResponse([
                'status' => 'ok',
                'result' => $result->toArray(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("Exchange manual sync failed for room {$id}: " . $e->getMessage());
            return new JSONResponse(['error' => 'Sync failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get Exchange sync status for all rooms.
     */
    public function syncStatus(): JSONResponse {
        if (!$this->requireAdmin()) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $rooms = $this->roomService->getAllRooms();
        $status = [];

        foreach ($rooms as $room) {
            $config = $room['exchangeConfig'] ?? null;
            if ($config === null || empty($config['resourceEmail'])) {
                continue;
            }

            $status[] = [
                'roomId' => $room['id'],
                'roomName' => $room['name'],
                'resourceEmail' => $config['resourceEmail'],
                'syncEnabled' => $config['syncEnabled'] ?? false,
                'lastSyncAt' => $config['lastSyncAt'] ?? null,
                'lastError' => $config['lastError'] ?? null,
            ];
        }

        return new JSONResponse([
            'globalEnabled' => $this->graphClient->isEnabled(),
            'configured' => $this->graphClient->isConfigured(),
            'rooms' => $status,
        ]);
    }

    /**
     * Get webhook subscription status for all Exchange rooms.
     */
    public function webhookStatus(): JSONResponse {
        if (!$this->requireAdmin()) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $rooms = $this->roomService->getAllRooms();
        $status = [];

        foreach ($rooms as $room) {
            $config = $room['exchangeConfig'] ?? null;
            if ($config === null || !($config['syncEnabled'] ?? false)) {
                continue;
            }

            $status[] = [
                'roomId' => $room['id'],
                'roomName' => $room['name'],
                'subscriptionId' => $config['webhookSubscriptionId'] ?? null,
                'expiresAt' => $config['webhookExpiresAt'] ?? null,
                'hasWebhook' => !empty($config['webhookSubscriptionId']),
            ];
        }

        $notificationUrl = $this->webhookService->getNotificationUrl();

        return new JSONResponse([
            'notificationUrl' => $notificationUrl,
            'httpsAvailable' => $notificationUrl !== null,
            'rooms' => $status,
        ]);
    }

    /**
     * Create webhook subscriptions for all Exchange-enabled rooms.
     */
    public function createWebhooks(): JSONResponse {
        if (!$this->requireAdmin()) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        $rooms = $this->roomService->getAllRooms();
        $results = [];

        foreach ($rooms as $room) {
            if (!$this->syncService->isExchangeRoom($room)) {
                continue;
            }

            $success = $this->webhookService->createSubscription($room);
            $results[] = [
                'roomId' => $room['id'],
                'roomName' => $room['name'],
                'success' => $success,
            ];
        }

        return new JSONResponse(['results' => $results]);
    }

    private function requireAdmin(): bool {
        $user = $this->userSession->getUser();
        $userId = $user?->getUID();
        return $userId !== null && $this->groupManager->isAdmin($userId);
    }
}
