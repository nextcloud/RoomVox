<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service\Exchange;

use OCA\RoomVox\AppInfo\Application;
use OCA\RoomVox\Service\RoomService;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class WebhookService {
    public function __construct(
        private GraphApiClient $graphClient,
        private RoomService $roomService,
        private IURLGenerator $urlGenerator,
        private ISecureRandom $secureRandom,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Create a webhook subscription for a room.
     */
    public function createSubscription(array $room): bool {
        $config = $room['exchangeConfig'] ?? null;
        if ($config === null || empty($config['resourceEmail'])) {
            return false;
        }

        $notificationUrl = $this->getNotificationUrl();
        if ($notificationUrl === null) {
            return false;
        }

        $clientState = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);

        try {
            $result = $this->graphClient->createSubscription(
                $config['resourceEmail'],
                $notificationUrl,
                $clientState,
            );

            $this->roomService->updateWebhookState(
                $room['id'],
                $result['id'],
                $result['expirationDateTime'],
                $clientState,
            );

            $this->logger->info("WebhookService: Created subscription {$result['id']} for room {$room['id']}");
            return true;
        } catch (ExchangeApiException $e) {
            $this->logger->error("WebhookService: Failed to create subscription for room {$room['id']}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Renew a webhook subscription for a room.
     */
    public function renewSubscription(array $room): bool {
        $subscriptionId = $room['exchangeConfig']['webhookSubscriptionId'] ?? null;
        if ($subscriptionId === null) {
            return false;
        }

        try {
            $result = $this->graphClient->renewSubscription($subscriptionId);
            $this->roomService->updateWebhookState(
                $room['id'],
                $subscriptionId,
                $result['expirationDateTime'],
                null,
            );

            $this->logger->info("WebhookService: Renewed subscription {$subscriptionId} for room {$room['id']}");
            return true;
        } catch (ExchangeApiException $e) {
            if ($e->getHttpStatus() === 404) {
                $this->roomService->updateWebhookState($room['id'], null, null, null);
                $this->logger->warning("WebhookService: Subscription {$subscriptionId} not found, recreating for room {$room['id']}");
                return $this->createSubscription($room);
            }
            $this->logger->error("WebhookService: Failed to renew subscription for room {$room['id']}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a webhook subscription for a room.
     */
    public function deleteSubscription(array $room): void {
        $subscriptionId = $room['exchangeConfig']['webhookSubscriptionId'] ?? null;
        if ($subscriptionId === null) {
            return;
        }

        try {
            $this->graphClient->deleteSubscription($subscriptionId);
        } catch (ExchangeApiException $e) {
            if ($e->getHttpStatus() !== 404) {
                $this->logger->error("WebhookService: Failed to delete subscription {$subscriptionId}: " . $e->getMessage());
            }
        }

        $this->roomService->updateWebhookState($room['id'], null, null, null);
    }

    /**
     * Check if a room's subscription needs renewal (expires within 36 hours).
     */
    public function needsRenewal(array $room): bool {
        $expiresAt = $room['exchangeConfig']['webhookExpiresAt'] ?? null;
        if ($expiresAt === null) {
            return false;
        }

        try {
            $expiry = new \DateTimeImmutable($expiresAt);
            $threshold = new \DateTimeImmutable('+36 hours');
            return $expiry < $threshold;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Find a room by its webhook subscription ID (O(1) via lookup table).
     */
    public function findRoomBySubscriptionId(string $subscriptionId): ?array {
        $roomId = $this->roomService->getRoomIdBySubscriptionId($subscriptionId);
        if ($roomId === null) {
            return null;
        }
        return $this->roomService->getRoom($roomId);
    }

    /**
     * Build the public notification URL for this Nextcloud instance.
     */
    public function getNotificationUrl(): ?string {
        $baseUrl = $this->urlGenerator->getAbsoluteURL('');
        $baseUrl = rtrim($baseUrl, '/');
        $url = $baseUrl . '/index.php/apps/' . Application::APP_ID . '/api/webhook/exchange';

        if (!str_starts_with($url, 'https://')) {
            $this->logger->warning('WebhookService: Notification URL must be HTTPS: ' . $url);
            return null;
        }

        return $url;
    }
}
