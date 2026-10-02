<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Middleware;

use OCA\RoomVox\Controller\PublicApiController;
use OCA\RoomVox\Controller\RoomApiController;
use OCA\RoomVox\Middleware\ApiTokenException;
use OCA\RoomVox\Middleware\ApiTokenMiddleware;
use OCA\RoomVox\Service\ApiTokenService;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Browsers call the Public API from other origins, for room displays and
 * dashboards. They send a CORS preflight without the Bearer token first, and
 * read a response only when it carries Access-Control-Allow-Origin. The API
 * authenticates with Bearer tokens, never cookies, so allowing any origin
 * exposes nothing a token holder could not already reach.
 */
class ApiTokenMiddlewareCorsTest extends TestCase {
    private IRequest $request;
    private ApiTokenService $tokenService;
    private ApiTokenMiddleware $middleware;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->tokenService = $this->createMock(ApiTokenService::class);
        $this->middleware = new ApiTokenMiddleware($this->request, $this->tokenService);
    }

    public function testPreflightNeedsNoToken(): void {
        $this->request->method('getMethod')->willReturn('OPTIONS');
        $this->request->method('getHeader')->willReturn('');
        $this->tokenService->expects($this->never())->method('validateToken');

        $this->middleware->beforeController($this->createMock(PublicApiController::class), 'preflight');

        $this->assertNull($this->middleware->getValidatedToken());
    }

    /** Only the preflight route is exempt, not any OPTIONS request. */
    public function testOtherMethodsStillNeedAToken(): void {
        $this->request->method('getMethod')->willReturn('OPTIONS');
        $this->request->method('getHeader')->willReturn('');

        $this->expectException(ApiTokenException::class);
        $this->middleware->beforeController($this->createMock(PublicApiController::class), 'roomStatus');
    }

    public function testPublicApiResponsesAllowAnyOrigin(): void {
        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('addHeader')->with('Access-Control-Allow-Origin', '*');

        $this->middleware->afterController($this->createMock(PublicApiController::class), 'roomStatus', $response);
    }

    /** A rejected token must be readable cross-origin too, or a display only sees a network error. */
    public function testTokenErrorsAllowAnyOrigin(): void {
        $response = $this->middleware->afterException(
            $this->createMock(PublicApiController::class),
            'roomStatus',
            new ApiTokenException('Invalid or expired API token', 401),
        );

        $this->assertSame(401, $response->getStatus());
        $this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin'] ?? null);
    }

    /** The session-authenticated admin API stays same-origin. */
    public function testOtherControllersGetNoCorsHeader(): void {
        $response = $this->createMock(Response::class);
        $response->expects($this->never())->method('addHeader');

        $this->middleware->afterController($this->createMock(RoomApiController::class), 'index', $response);
    }
}
