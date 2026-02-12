<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

class RoomGroupService {
    private const GROUP_PREFIX = 'group/';
    private const GROUPS_INDEX_KEY = 'room_groups_index';

    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get all room groups
     * @return array[]
     */
    public function getAllGroups(): array {
        $groupIds = $this->getGroupIds();
        $groups = [];

        foreach ($groupIds as $groupId) {
            $group = $this->getGroup($groupId);
            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * Get a single room group by ID
     */
    public function getGroup(string $groupId): ?array {
        $json = $this->appConfig->getValueString(
            Application::APP_ID,
            self::GROUP_PREFIX . $groupId,
            ''
        );

        if ($json === '') {
            return null;
        }

        $group = json_decode($json, true);
        return is_array($group) ? $group : null;
    }

    /**
     * Create a new room group
     */
    public function createGroup(array $data): array {
        $groupId = $this->generateSlug($data['name']);

        // Ensure unique ID
        $existingIds = $this->getGroupIds();
        $baseId = $groupId;
        $counter = 1;
        while (in_array($groupId, $existingIds)) {
            $groupId = $baseId . '-' . $counter;
            $counter++;
        }

        $group = [
            'id' => $groupId,
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'createdAt' => date('c'),
        ];

        $this->saveGroup($group);
        $this->addGroupId($groupId);

        $this->logger->info("Room group created: {$groupId} ({$group['name']})");

        return $group;
    }

    /**
     * Update an existing room group
     */
    public function updateGroup(string $groupId, array $data): ?array {
        $group = $this->getGroup($groupId);
        if ($group === null) {
            return null;
        }

        if (array_key_exists('name', $data)) {
            $group['name'] = $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $group['description'] = $data['description'];
        }

        $this->saveGroup($group);

        $this->logger->info("Room group updated: {$groupId}");

        return $group;
    }

    /**
     * Delete a room group (caller must ensure no rooms are assigned)
     */
    public function deleteGroup(string $groupId): bool {
        $group = $this->getGroup($groupId);
        if ($group === null) {
            return false;
        }

        $this->appConfig->deleteKey(Application::APP_ID, self::GROUP_PREFIX . $groupId);
        $this->removeGroupId($groupId);

        $this->logger->info("Room group deleted: {$groupId}");

        return true;
    }

    /**
     * Get all group IDs
     * @return string[]
     */
    private function getGroupIds(): array {
        $json = $this->appConfig->getValueString(
            Application::APP_ID,
            self::GROUPS_INDEX_KEY,
            '[]'
        );

        $ids = json_decode($json, true);
        return is_array($ids) ? $ids : [];
    }

    private function addGroupId(string $groupId): void {
        $ids = $this->getGroupIds();
        if (!in_array($groupId, $ids)) {
            $ids[] = $groupId;
            $this->appConfig->setValueString(
                Application::APP_ID,
                self::GROUPS_INDEX_KEY,
                json_encode($ids)
            );
        }
    }

    private function removeGroupId(string $groupId): void {
        $ids = $this->getGroupIds();
        $ids = array_values(array_filter($ids, fn($id) => $id !== $groupId));
        $this->appConfig->setValueString(
            Application::APP_ID,
            self::GROUPS_INDEX_KEY,
            json_encode($ids)
        );
    }

    private function saveGroup(array $group): void {
        $this->appConfig->setValueString(
            Application::APP_ID,
            self::GROUP_PREFIX . $group['id'],
            json_encode($group)
        );
    }

    private function generateSlug(string $name): string {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug ?: 'group';
    }
}
