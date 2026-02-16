<?php

declare(strict_types=1);

namespace OCA\RoomVox\Middleware;

use OCA\RoomVox\Service\ApiTokenService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Middleware that authenticates requests to /api/v1/* endpoints using Bearer tokens.
 * Sets the validated token data on the request for use by controllers.
 */
class ApiTokenMiddleware extends Middleware {
    private ?array $validatedToken = null;

    public function __construct(
        private IRequest $request,
        private ApiTokenService $tokenService,
    ) {
    }

    public function beforeController(mixed $controller, string $methodName): void {
        // Only apply to PublicApiController
        if (!($controller instanceof \OCA\RoomVox\Controller\PublicApiController)) {
            return;
        }

        $authHeader = $this->request->getHeader('Authorization');

        if (empty($authHeader) || !preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            throw new ApiTokenException('Missing or invalid Authorization header', 401);
        }

        $rawToken = trim($matches[1]);
        if (empty($rawToken)) {
            throw new ApiTokenException('Missing or invalid Authorization header', 401);
        }

        $token = $this->tokenService->validateToken($rawToken);

        if ($token === null) {
            throw new ApiTokenException('Invalid or expired API token', 401);
        }

        $this->validatedToken = $token;
    }

    public function afterException(mixed $controller, string $methodName, \Exception $exception): JSONResponse {
        if ($exception instanceof ApiTokenException) {
            return new JSONResponse(
                ['error' => $exception->getMessage()],
                $exception->getHttpCode()
            );
        }

        throw $exception;
    }

    public function getValidatedToken(): ?array {
        return $this->validatedToken;
    }
}
