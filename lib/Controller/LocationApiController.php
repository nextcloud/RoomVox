<?php

declare(strict_types=1);

namespace OCA\RoomVox\Controller;

use OCA\RoomVox\Service\LocationService;
use OCA\RoomVox\Service\RoomService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Calendar\Room\IManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** Session-authenticated, admin-only endpoints (Nextcloud's default). */
class LocationApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private LocationService $locationService,
        private RoomService $roomService,
        private IManager $roomManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    public function index(): JSONResponse {
        return new JSONResponse($this->locationService->getAllLocations());
    }

    public function create(): JSONResponse {
        return $this->save();
    }

    public function update(string $id): JSONResponse {
        return $this->save($id);
    }

    private function save(?string $id = null): JSONResponse {
        $data = array_intersect_key($this->request->getParams(), array_flip(LocationService::FIELDS));
        try {
            $location = $this->locationService->saveLocation($data, $id);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
        if ($location === null) {
            return new JSONResponse(['error' => 'Location not found'], 404);
        }
        // Refresh the address exposed to calendar clients for assigned rooms.
        try {
            $this->roomManager->update();
        } catch (\Exception $e) {
            $this->logger->warning('Failed to sync room cache: ' . $e->getMessage());
        }
        return new JSONResponse($location, $id === null ? 201 : 200);
    }

    public function destroy(string $id): JSONResponse {
        foreach ($this->roomService->getAllRooms() as $room) {
            if (($room['locationId'] ?? null) === $id) {
                return new JSONResponse(['error' => 'Rooms are still assigned to this location'], 409);
            }
        }
        if (!$this->locationService->deleteLocation($id)) {
            return new JSONResponse(['error' => 'Location not found'], 404);
        }
        return new JSONResponse(['status' => 'ok']);
    }
}
