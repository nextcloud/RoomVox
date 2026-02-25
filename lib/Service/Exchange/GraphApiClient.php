<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service\Exchange;

use OCA\RoomVox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class GraphApiClient {
    private const GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';
    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const SCOPE = 'https://graph.microsoft.com/.default';
    private const REQUEST_TIMEOUT = 10;

    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    /** GUID for RoomVox custom extended properties on Exchange events */
    public const PROPERTY_SET_ID = '66f5a359-4659-4830-9070-00047ec6ac6e';
    public const SYNC_SOURCE_PROP = 'String {' . self::PROPERTY_SET_ID . '} Name RoomVoxSyncSource';
    public const ROOMVOX_UID_PROP = 'String {' . self::PROPERTY_SET_ID . '} Name RoomVoxUID';

    public function __construct(
        private IAppConfig $appConfig,
        private ICrypto $crypto,
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool {
        return $this->isEnabled()
            && $this->getTenantId() !== ''
            && $this->getClientId() !== ''
            && $this->getClientSecret() !== '';
    }

    public function isEnabled(): bool {
        return $this->appConfig->getValueString(Application::APP_ID, 'exchange_enabled', 'false') === 'true';
    }

    /**
     * Test the connection by fetching organization info.
     * @return array{success: bool, tenantName?: string, error?: string}
     */
    public function testConnection(): array {
        try {
            $result = $this->get('/organization', ['$select' => 'displayName']);
            $tenantName = $result['value'][0]['displayName'] ?? 'Unknown';
            return ['success' => true, 'tenantName' => $tenantName];
        } catch (ExchangeApiException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array Decoded JSON response
     * @throws ExchangeApiException
     */
    public function get(string $endpoint, array $params = []): array {
        $url = self::GRAPH_BASE_URL . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $this->request('GET', $url);
    }

    /**
     * @return array Decoded JSON response
     * @throws ExchangeApiException
     */
    public function post(string $endpoint, array $body): array {
        return $this->request('POST', self::GRAPH_BASE_URL . $endpoint, $body);
    }

    /**
     * @return array Decoded JSON response
     * @throws ExchangeApiException
     */
    public function patch(string $endpoint, array $body): array {
        return $this->request('PATCH', self::GRAPH_BASE_URL . $endpoint, $body);
    }

    /**
     * @throws ExchangeApiException
     */
    public function delete(string $endpoint): void {
        $this->request('DELETE', self::GRAPH_BASE_URL . $endpoint);
    }

    /**
     * Create a Graph webhook subscription for calendar events.
     * @return array{id: string, expirationDateTime: string}
     * @throws ExchangeApiException
     */
    public function createSubscription(string $resourceEmail, string $notificationUrl, string $clientState): array {
        $expiration = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i:s.0000000\Z');

        return $this->post('/subscriptions', [
            'changeType' => 'created,updated,deleted',
            'notificationUrl' => $notificationUrl,
            'resource' => '/users/' . urlencode($resourceEmail) . '/events',
            'expirationDateTime' => $expiration,
            'clientState' => $clientState,
        ]);
    }

    /**
     * Renew a Graph webhook subscription.
     * @throws ExchangeApiException
     */
    public function renewSubscription(string $subscriptionId): array {
        $expiration = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i:s.0000000\Z');

        return $this->patch('/subscriptions/' . urlencode($subscriptionId), [
            'expirationDateTime' => $expiration,
        ]);
    }

    /**
     * Delete a Graph webhook subscription.
     * @throws ExchangeApiException
     */
    public function deleteSubscription(string $subscriptionId): void {
        $this->delete('/subscriptions/' . urlencode($subscriptionId));
    }

    /**
     * Follow a delta link or next link (full URL, not relative endpoint).
     * @return array Decoded JSON response
     * @throws ExchangeApiException
     */
    public function getUrl(string $fullUrl): array {
        return $this->request('GET', $fullUrl);
    }

    /**
     * @throws ExchangeApiException
     */
    private function request(string $method, string $url, ?array $body = null): array {
        $token = $this->getAccessToken();

        $client = $this->clientService->newClient();
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Prefer' => 'outlook.timezone="UTC"',
            ],
            'timeout' => self::REQUEST_TIMEOUT,
        ];

        if ($body !== null) {
            $options['body'] = json_encode($body);
        }

        try {
            $response = match ($method) {
                'GET' => $client->get($url, $options),
                'POST' => $client->post($url, $options),
                'PATCH' => $client->patch($url, $options),
                'DELETE' => $client->delete($url, $options),
                default => throw new ExchangeApiException("Unsupported HTTP method: {$method}"),
            };

            $statusCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();

            if ($statusCode === 204 || empty($responseBody)) {
                return [];
            }

            $decoded = json_decode($responseBody, true);
            if (!is_array($decoded)) {
                return [];
            }

            return $decoded;
        } catch (ExchangeApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $statusCode = 0;
            $graphError = '';
            $message = $e->getMessage();

            // Try to extract Graph API error details from the response
            if (method_exists($e, 'getResponse')) {
                $resp = $e->getResponse();
                if ($resp !== null) {
                    $statusCode = $resp->getStatusCode();
                    $errorBody = json_decode((string) $resp->getBody(), true);
                    if (isset($errorBody['error'])) {
                        $graphError = $errorBody['error']['code'] ?? '';
                        $message = $errorBody['error']['message'] ?? $message;
                    }
                }
            }

            throw new ExchangeApiException(
                "Graph API {$method} {$url} failed: {$message}",
                $statusCode,
                $graphError,
                $e
            );
        }
    }

    /**
     * Acquire or return cached OAuth2 access token.
     * @throws ExchangeApiException
     */
    private function getAccessToken(): string {
        // Return cached token if still valid (with 60s margin)
        if ($this->accessToken !== null && time() < ($this->tokenExpiresAt - 60)) {
            return $this->accessToken;
        }

        $tenantId = $this->getTenantId();
        $clientId = $this->getClientId();
        $clientSecret = $this->getClientSecret();

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            throw new ExchangeApiException('Exchange integration not configured: missing tenant, client ID, or client secret');
        }

        $tokenUrl = sprintf(self::TOKEN_URL_TEMPLATE, urlencode($tenantId));

        $client = $this->clientService->newClient();
        try {
            $response = $client->post($tokenUrl, [
                'body' => http_build_query([
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => self::SCOPE,
                ]),
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (!isset($data['access_token'])) {
                throw new ExchangeApiException(
                    'Token response missing access_token: ' . ($data['error_description'] ?? 'unknown error'),
                    401,
                    $data['error'] ?? 'TokenError'
                );
            }

            $this->accessToken = $data['access_token'];
            $this->tokenExpiresAt = time() + (int)($data['expires_in'] ?? 3600);

            return $this->accessToken;
        } catch (ExchangeApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ExchangeApiException(
                'Failed to acquire Exchange access token: ' . $e->getMessage(),
                401,
                'AuthenticationError',
                $e
            );
        }
    }

    private function getTenantId(): string {
        return $this->appConfig->getValueString(Application::APP_ID, 'exchange_tenant_id', '');
    }

    private function getClientId(): string {
        return $this->appConfig->getValueString(Application::APP_ID, 'exchange_client_id', '');
    }

    private function getClientSecret(): string {
        $encrypted = $this->appConfig->getValueString(Application::APP_ID, 'exchange_client_secret', '');
        if ($encrypted === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($encrypted);
        } catch (\Exception $e) {
            $this->logger->error('Failed to decrypt Exchange client secret: ' . $e->getMessage());
            return '';
        }
    }
}
