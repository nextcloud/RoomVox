<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service\Exchange;

class SyncResult {
    public int $created = 0;
    public int $updated = 0;
    public int $deleted = 0;
    public int $skipped = 0;
    public array $errors = [];
    public ?string $newDeltaToken = null;

    /** @var string[] Exchange event IDs seen during this sync (for reconciliation) */
    public array $seenExchangeIds = [];

    public function hasChanges(): bool {
        return $this->created > 0 || $this->updated > 0 || $this->deleted > 0;
    }

    public function toArray(): array {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'deleted' => $this->deleted,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }
}
