<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\Service\ApiTokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

class ApiTokenController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private ApiTokenService $tokenService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IL10N $l10n,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List all API tokens (admin only)
     */
    #[NoCSRFRequired]
    public function index(): JSONResponse {
        if (!$this->isAdmin()) {
            // TRANSLATORS Error when someone who is not a Nextcloud administrator calls an administration action
            return new JSONResponse(['error' => $this->l10n->t('Administrator privileges required')], 403);
        }

        return new JSONResponse($this->tokenService->getAllTokens());
    }

    /**
     * Create a new API token (admin only)
     */
    public function create(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => $this->l10n->t('Administrator privileges required')], 403);
        }

        $name = trim((string)$this->request->getParam('name', ''));
        $scope = (string)$this->request->getParam('scope', 'read');
        $roomIds = (array)$this->request->getParam('roomIds', []);
        $expiresAt = $this->request->getParam('expiresAt', null);

        if (empty($name)) {
            // TRANSLATORS Error: an API token was created without a name. An API token is a key that external systems use to access RoomVox
            return new JSONResponse(['error' => $this->l10n->t('Token name is required')], 400);
        }

        if (strlen($name) > 255) {
            // TRANSLATORS Error when creating an API token; keep the number 255
            return new JSONResponse(['error' => $this->l10n->t('Token name too long (max 255 characters)')], 400);
        }

        if (!in_array($scope, ['read', 'book', 'admin'])) {
            return new JSONResponse(['error' => 'Invalid scope. Use: read, book, or admin'], 400);
        }

        if ($expiresAt !== null) {
            try {
                new \DateTimeImmutable($expiresAt);
            } catch (\Exception $e) {
                return new JSONResponse(['error' => $this->l10n->t('Invalid expiration date format')], 400);
            }
        }

        $roomIds = array_values(array_filter($roomIds, 'is_string'));

        $token = $this->tokenService->createToken($name, $scope, $roomIds, $expiresAt);

        return new JSONResponse($token, 201);
    }

    /**
     * Delete an API token (admin only)
     */
    public function destroy(string $id): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => $this->l10n->t('Administrator privileges required')], 403);
        }

        if (!$this->tokenService->deleteToken($id)) {
            // TRANSLATORS Error: the API token to delete no longer exists
            return new JSONResponse(['error' => $this->l10n->t('Token not found')], 404);
        }

        return new JSONResponse(['status' => 'ok']);
    }

    private function isAdmin(): bool {
        $user = $this->userSession->getUser();
        return $user !== null && $this->groupManager->isAdmin($user->getUID());
    }
}
