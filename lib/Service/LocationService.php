<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\IAppConfig;

class LocationService {
    private const KEY = 'locations';
    public const FIELDS = ['name', 'building', 'street', 'postalCode', 'city', 'country', 'description'];

    public function __construct(private IAppConfig $appConfig) {
    }

    public function getAllLocations(): array {
        $locations = json_decode($this->appConfig->getValueString(Application::APP_ID, self::KEY, '[]'), true);
        return is_array($locations) ? array_values($locations) : [];
    }

    public function getLocation(string $id): ?array {
        foreach ($this->getAllLocations() as $location) {
            if ($location['id'] === $id) {
                return $location;
            }
        }
        return null;
    }

    public function saveLocation(array $data, ?string $id = null): ?array {
        $locations = $this->getAllLocations();
        $location = $id === null ? ['id' => bin2hex(random_bytes(12))] : $this->getLocation($id);
        if ($location === null) {
            return null;
        }
        foreach (self::FIELDS as $field) {
            $value = $data[$field] ?? $location[$field] ?? '';
            if (!is_string($value) || mb_strlen($value) > 2000) {
                throw new \InvalidArgumentException('Invalid location field: ' . $field);
            }
            $location[$field] = trim($value);
        }
        if ($location['name'] === '') {
            throw new \InvalidArgumentException('Location name is required');
        }
        $locations = array_values(array_filter($locations, fn($item) => $item['id'] !== $location['id']));
        $locations[] = $location;
        $this->appConfig->setValueString(Application::APP_ID, self::KEY, json_encode($locations, JSON_THROW_ON_ERROR));
        return $location;
    }

    /** Caller must ensure no rooms are assigned. */
    public function deleteLocation(string $id): bool {
        if ($this->getLocation($id) === null) {
            return false;
        }
        $locations = array_values(array_filter($this->getAllLocations(), fn($item) => $item['id'] !== $id));
        $this->appConfig->setValueString(Application::APP_ID, self::KEY, json_encode($locations, JSON_THROW_ON_ERROR));
        return true;
    }

    public static function formatAddress(array $location): string {
        // Preserve the four positional fields used by existing room consumers.
        $city = implode(', ', array_filter([$location['city'], $location['country']], fn($part) => $part !== ''));
        return implode(', ', [$location['building'] ?: $location['name'], $location['street'], $location['postalCode'], $city]);
    }
}
