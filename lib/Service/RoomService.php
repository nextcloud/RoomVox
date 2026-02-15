<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class RoomService {
    private const ROOM_PREFIX = 'room/';
    private const ROOMS_INDEX_KEY = 'rooms_index';
    private const USER_PREFIX = 'rb_';

    public function __construct(
        private IAppConfig $appConfig,
        private ICrypto $crypto,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get all rooms
     * @return array<string, array>
     */
    public function getAllRooms(): array {
        $roomIds = $this->getRoomIds();
        $rooms = [];

        foreach ($roomIds as $roomId) {
            $room = $this->getRoom($roomId);
            if ($room !== null) {
                $rooms[$roomId] = $room;
            }
        }

        return $rooms;
    }

    /**
     * Get a single room by ID
     */
    public function getRoom(string $roomId): ?array {
        $json = $this->appConfig->getValueString(
            Application::APP_ID,
            self::ROOM_PREFIX . $roomId,
            ''
        );

        if ($json === '') {
            return null;
        }

        $room = json_decode($json, true);
        if (!is_array($room)) {
            return null;
        }

        // Migrate legacy fields to new model (roomNumber + address)
        if (isset($room['building']) && !isset($room['roomNumber'])) {
            $building = $room['building'] ?? '';
            $address = $room['address'] ?? '';

            if ($building !== '' && $address !== '') {
                $room['address'] = $building . ', ' . $address;
            } elseif ($building !== '') {
                $room['address'] = $building;
            }

            $room['roomNumber'] = '';
            unset($room['building'], $room['floor'], $room['location']);
        } elseif (!empty($room['location']) && !isset($room['roomNumber'])) {
            $room['address'] = $room['location'];
            $room['roomNumber'] = '';
            unset($room['location']);
        }

        // Decrypt SMTP password if present
        if (!empty($room['smtpConfig']['password'])) {
            try {
                $room['smtpConfig']['password'] = $this->crypto->decrypt($room['smtpConfig']['password']);
            } catch (\Exception $e) {
                $room['smtpConfig']['password'] = '';
            }
        }

        return $room;
    }

    /**
     * Get room by service account userId (rb_*)
     */
    public function getRoomByUserId(string $userId): ?array {
        if (!str_starts_with($userId, self::USER_PREFIX)) {
            return null;
        }

        $roomId = substr($userId, strlen(self::USER_PREFIX));
        return $this->getRoom($roomId);
    }

    /**
     * Create a new room
     */
    public function createRoom(array $data): array {
        $roomId = $this->generateSlug($data['name']);
        $userId = self::USER_PREFIX . $roomId;

        // Ensure unique ID
        $existingIds = $this->getRoomIds();
        $baseId = $roomId;
        $counter = 1;
        while (in_array($roomId, $existingIds)) {
            $roomId = $baseId . '-' . $counter;
            $userId = self::USER_PREFIX . $roomId;
            $counter++;
        }

        $room = [
            'id' => $roomId,
            'userId' => $userId,
            'name' => $data['name'],
            'email' => !empty($data['email']) ? $data['email'] : $roomId . '@roomvox.local',
            'description' => $data['description'] ?? '',
            'capacity' => (int)($data['capacity'] ?? 0),
            'roomNumber' => $data['roomNumber'] ?? '',
            'roomType' => $data['roomType'] ?? 'meeting-room',
            'address' => $data['address'] ?? '',
            'facilities' => $data['facilities'] ?? [],
            'autoAccept' => (bool)($data['autoAccept'] ?? false),
            'groupId' => $data['groupId'] ?? null,
            'availabilityRules' => $data['availabilityRules'] ?? ['enabled' => false, 'rules' => []],
            'maxBookingHorizon' => (int)($data['maxBookingHorizon'] ?? 0),
            'active' => true,
            'calendarUri' => '',
            'smtpConfig' => null,
            'createdAt' => date('c'),
        ];

        // Handle SMTP config
        if (!empty($data['smtpConfig'])) {
            $room['smtpConfig'] = $this->prepareSMTPConfig($data['smtpConfig']);
        }

        $this->saveRoom($room);
        $this->addRoomId($roomId);

        $this->logger->info("Room created: {$roomId} ({$room['name']})");

        return $room;
    }

    /**
     * Update an existing room
     */
    public function updateRoom(string $roomId, array $data): ?array {
        $room = $this->getRoom($roomId);
        if ($room === null) {
            return null;
        }

        $updatableFields = ['name', 'email', 'description', 'capacity', 'roomNumber', 'roomType', 'address', 'facilities', 'autoAccept', 'active', 'groupId', 'availabilityRules', 'maxBookingHorizon'];

        foreach ($updatableFields as $field) {
            if (array_key_exists($field, $data)) {
                $room[$field] = $data[$field];
            }
        }

        // Ensure correct types
        $room['capacity'] = (int)$room['capacity'];
        $room['autoAccept'] = (bool)$room['autoAccept'];
        $room['active'] = (bool)$room['active'];

        // Handle SMTP config update
        if (array_key_exists('smtpConfig', $data)) {
            if ($data['smtpConfig'] === null) {
                $room['smtpConfig'] = null;
            } else {
                $room['smtpConfig'] = $this->prepareSMTPConfig($data['smtpConfig']);
            }
        }

        $this->saveRoom($room);

        $this->logger->info("Room updated: {$roomId}");

        return $room;
    }

    /**
     * Delete a room
     */
    public function deleteRoom(string $roomId): bool {
        $room = $this->getRoom($roomId);
        if ($room === null) {
            return false;
        }

        $this->appConfig->deleteKey(Application::APP_ID, self::ROOM_PREFIX . $roomId);
        $this->removeRoomId($roomId);

        $this->logger->info("Room deleted: {$roomId}");

        return true;
    }

    /**
     * Set the calendar URI for a room (called after provisioning)
     */
    public function setCalendarUri(string $roomId, string $calendarUri): void {
        $room = $this->getRoom($roomId);
        if ($room !== null) {
            $room['calendarUri'] = $calendarUri;
            $this->saveRoom($room);
        }
    }

    /**
     * Check if a uid belongs to a room service account
     */
    public function isRoomAccount(string $uid): bool {
        if (!str_starts_with($uid, self::USER_PREFIX)) {
            return false;
        }

        $roomId = substr($uid, strlen(self::USER_PREFIX));
        return $this->getRoom($roomId) !== null;
    }

    /**
     * Check if a CalDAV principal URI belongs to a room
     */
    public function isRoomPrincipal(string $principalUri): bool {
        $userId = $this->extractUserIdFromPrincipal($principalUri);
        if ($userId === null) {
            return false;
        }

        return $this->isRoomAccount($userId);
    }

    /**
     * Get room ID from a CalDAV principal URI
     */
    public function getRoomIdByPrincipal(string $principalUri): ?string {
        $userId = $this->extractUserIdFromPrincipal($principalUri);
        if ($userId === null || !str_starts_with($userId, self::USER_PREFIX)) {
            return null;
        }

        $roomId = substr($userId, strlen(self::USER_PREFIX));
        return $this->getRoom($roomId) !== null ? $roomId : null;
    }

    /**
     * Extract user ID from principal URI (principals/users/rb_xxx → rb_xxx)
     */
    public function extractUserIdFromPrincipal(string $principalUri): ?string {
        $prefix = 'principals/users/';
        if (str_starts_with($principalUri, $prefix)) {
            return substr($principalUri, strlen($prefix));
        }

        // Also handle mailto: format — match room email or SMTP username
        if (str_starts_with(strtolower($principalUri), 'mailto:')) {
            $email = strtolower(substr($principalUri, 7));
            foreach ($this->getAllRooms() as $room) {
                if (strtolower($room['email']) === $email) {
                    return $room['userId'];
                }
                // Also match SMTP username (clients sometimes use this as room email)
                $smtpUser = strtolower($room['smtpConfig']['username'] ?? '');
                if ($smtpUser !== '' && $smtpUser === $email) {
                    return $room['userId'];
                }
            }
        }

        return null;
    }

    /**
     * Get all room IDs
     * @return string[]
     */
    private function getRoomIds(): array {
        $json = $this->appConfig->getValueString(
            Application::APP_ID,
            self::ROOMS_INDEX_KEY,
            '[]'
        );

        $ids = json_decode($json, true);
        return is_array($ids) ? $ids : [];
    }

    /**
     * Add a room ID to the index
     */
    private function addRoomId(string $roomId): void {
        $ids = $this->getRoomIds();
        if (!in_array($roomId, $ids)) {
            $ids[] = $roomId;
            $this->appConfig->setValueString(
                Application::APP_ID,
                self::ROOMS_INDEX_KEY,
                json_encode($ids)
            );
        }
    }

    /**
     * Remove a room ID from the index
     */
    private function removeRoomId(string $roomId): void {
        $ids = $this->getRoomIds();
        $ids = array_values(array_filter($ids, fn($id) => $id !== $roomId));
        $this->appConfig->setValueString(
            Application::APP_ID,
            self::ROOMS_INDEX_KEY,
            json_encode($ids)
        );
    }

    /**
     * Save room data to app config
     */
    private function saveRoom(array $room): void {
        // Encrypt SMTP password before saving
        $roomToSave = $room;
        if (!empty($roomToSave['smtpConfig']['password'])) {
            $roomToSave['smtpConfig']['password'] = $this->crypto->encrypt($roomToSave['smtpConfig']['password']);
        }

        $this->appConfig->setValueString(
            Application::APP_ID,
            self::ROOM_PREFIX . $room['id'],
            json_encode($roomToSave)
        );
    }

    /**
     * Prepare SMTP config (validate and structure)
     */
    private function prepareSMTPConfig(array $config): array {
        return [
            'host' => $config['host'] ?? '',
            'port' => (int)($config['port'] ?? 587),
            'username' => $config['username'] ?? '',
            'password' => $config['password'] ?? '',
            'encryption' => $config['encryption'] ?? 'tls',
        ];
    }

    /**
     * Build a LOCATION string from room fields.
     * Format: "Street, PostalCode City (Building, Room Nr)"
     * Optimized for iOS/macOS Maps geocoding (street + postal code + city first).
     *
     * Address is stored as "Building, Street, PostalCode, City".
     */
    public function buildRoomLocation(array $room): string {
        $address = trim($room['address'] ?? '');
        $roomNumber = trim($room['roomNumber'] ?? '');

        // Parse address parts: "Building, Street, PostalCode, City"
        $parts = array_map('trim', explode(',', $address));
        $building = '';
        $street = '';
        $postalCode = '';
        $city = '';

        if (count($parts) >= 4) {
            $building = $parts[0];
            $street = $parts[1];
            $postalCode = $parts[2];
            $city = implode(', ', array_slice($parts, 3));
        } elseif (count($parts) === 3) {
            $building = $parts[0];
            $street = $parts[1];
            // Detect if 3rd part is postal code (e.g. "3584 CS") or city
            if (preg_match('/^\d{4}\s*[A-Z]{2}$/i', $parts[2])) {
                $postalCode = $parts[2];
            } else {
                $city = $parts[2];
            }
        } elseif (count($parts) === 2) {
            $building = $parts[0];
            $street = $parts[1];
        } elseif (count($parts) === 1 && $parts[0] !== '') {
            $street = $parts[0];
        }

        // Build geocodable address: "Street, PostalCode City"
        $geocodable = $street;
        $cityPart = trim($postalCode . ' ' . $city);
        if ($geocodable !== '' && $cityPart !== '') {
            $geocodable .= ', ' . $cityPart;
        } elseif ($cityPart !== '') {
            $geocodable = $cityPart;
        }

        // Build detail: "(Building, Room Nr)"
        $detailParts = [];
        if ($building !== '') {
            $detailParts[] = $building;
        }
        if ($roomNumber !== '') {
            $detailParts[] = 'Room ' . $roomNumber;
        }
        $detail = implode(', ', $detailParts);

        // Combine: "Street, PostalCode City (Building, Room Nr)"
        if ($geocodable !== '' && $detail !== '') {
            return $geocodable . ' (' . $detail . ')';
        }
        if ($geocodable !== '') {
            return $geocodable;
        }
        if ($detail !== '') {
            return $room['name'] . ' (' . $detail . ')';
        }

        return $room['name'];
    }

    /**
     * Strip mailto: prefix from an email string
     */
    public static function stripMailto(string $email): string {
        if (str_starts_with(strtolower($email), 'mailto:')) {
            return substr($email, 7);
        }
        return $email;
    }

    /**
     * Generate a URL-safe slug from a name
     */
    private function generateSlug(string $name): string {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug ?: 'room';
    }
}
