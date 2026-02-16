<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class ApiTokenService {
    private const TOKEN_PREFIX = 'api_token/';
    private const TOKENS_INDEX_KEY = 'api_tokens_index';

    public function __construct(
        private IAppConfig $appConfig,
        private ISecureRandom $secureRandom,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get all tokens (without the hashed secret)
     * @return array<array>
     */
    public function getAllTokens(): array {
        $tokenIds = $this->getTokenIds();
        $tokens = [];

        foreach ($tokenIds as $tokenId) {
            $token = $this->getToken($tokenId);
            if ($token !== null) {
                unset($token['tokenHash']);
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Create a new API token. Returns the full token (only shown once).
     */
    public function createToken(string $name, string $scope, array $roomIds = [], ?string $expiresAt = null): array {
        $id = 'tok_' . $this->secureRandom->generate(12, ISecureRandom::CHAR_ALPHANUMERIC);
        $rawToken = 'rvx_' . $this->secureRandom->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
        $tokenHash = hash('sha256', $rawToken);

        $tokenData = [
            'id' => $id,
            'name' => $name,
            'tokenHash' => $tokenHash,
            'scope' => $scope,
            'roomIds' => $roomIds,
            'createdAt' => date('c'),
            'lastUsedAt' => null,
            'expiresAt' => $expiresAt,
        ];

        $this->appConfig->setValueString(
            Application::APP_ID,
            self::TOKEN_PREFIX . $id,
            json_encode($tokenData)
        );

        $this->addToIndex($id);

        // Return with the raw token (only time it's available)
        $result = $tokenData;
        unset($result['tokenHash']);
        $result['token'] = $rawToken;

        return $result;
    }

    /**
     * Delete a token
     */
    public function deleteToken(string $id): bool {
        $token = $this->getToken($id);
        if ($token === null) {
            return false;
        }

        $this->appConfig->deleteKey(Application::APP_ID, self::TOKEN_PREFIX . $id);
        $this->removeFromIndex($id);

        return true;
    }

    /**
     * Validate a raw token string. Returns token data if valid, null if not.
     */
    public function validateToken(string $rawToken): ?array {
        if (!str_starts_with($rawToken, 'rvx_')) {
            return null;
        }

        $hash = hash('sha256', $rawToken);
        $tokenIds = $this->getTokenIds();

        foreach ($tokenIds as $tokenId) {
            $token = $this->getToken($tokenId);
            if ($token !== null && hash_equals($token['tokenHash'], $hash)) {
                // Check expiry
                if (!empty($token['expiresAt'])) {
                    $expiry = new \DateTimeImmutable($token['expiresAt']);
                    if ($expiry < new \DateTimeImmutable()) {
                        return null;
                    }
                }

                // Update last used
                $token['lastUsedAt'] = date('c');
                $this->appConfig->setValueString(
                    Application::APP_ID,
                    self::TOKEN_PREFIX . $token['id'],
                    json_encode($token)
                );

                $result = $token;
                unset($result['tokenHash']);
                return $result;
            }
        }

        return null;
    }

    /**
     * Check if a token has access to a specific room
     */
    public function hasRoomAccess(array $token, string $roomId): bool {
        if (empty($token['roomIds'])) {
            return true; // No restriction = all rooms
        }
        return in_array($roomId, $token['roomIds']);
    }

    /**
     * Check if token has the required scope
     */
    public function hasScope(array $token, string $requiredScope): bool {
        $scopes = [
            'read' => 1,
            'book' => 2,
            'admin' => 3,
        ];

        $tokenLevel = $scopes[$token['scope']] ?? 0;
        $requiredLevel = $scopes[$requiredScope] ?? 99;

        return $tokenLevel >= $requiredLevel;
    }

    private function getToken(string $id): ?array {
        $json = $this->appConfig->getValueString(
            Application::APP_ID,
            self::TOKEN_PREFIX . $id,
            ''
        );

        if ($json === '') {
            return null;
        }

        return json_decode($json, true);
    }

    private function getTokenIds(): array {
        $json = $this->appConfig->getValueString(
            Application::APP_ID,
            self::TOKENS_INDEX_KEY,
            '[]'
        );

        return json_decode($json, true) ?: [];
    }

    private function addToIndex(string $id): void {
        $ids = $this->getTokenIds();
        if (!in_array($id, $ids)) {
            $ids[] = $id;
            $this->appConfig->setValueString(
                Application::APP_ID,
                self::TOKENS_INDEX_KEY,
                json_encode($ids)
            );
        }
    }

    private function removeFromIndex(string $id): void {
        $ids = $this->getTokenIds();
        $ids = array_values(array_filter($ids, fn($i) => $i !== $id));
        $this->appConfig->setValueString(
            Application::APP_ID,
            self::TOKENS_INDEX_KEY,
            json_encode($ids)
        );
    }
}
