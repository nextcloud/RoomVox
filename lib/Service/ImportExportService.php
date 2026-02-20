<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCA\RoomVox\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

class ImportExportService {
    /** RoomVox CSV column order */
    private const EXPORT_COLUMNS = [
        'name', 'email', 'capacity', 'roomNumber', 'roomType',
        'building', 'street', 'postalCode', 'city',
        'facilities', 'description', 'autoAccept', 'active',
    ];

    /** MS365 column name → RoomVox field mapping */
    private const MS365_COLUMN_MAP = [
        'displayname' => 'name',
        'primarysmtpaddress' => 'email',
        'emailaddress' => 'email',
        'capacity' => 'capacity',
        'resourcecapacity' => 'capacity',
        'building' => 'building',
        'floor' => 'roomNumber',
        'floorlabel' => 'roomNumber',
        'city' => 'city',
        'tags' => 'facilities',
        'iswheelchairaccessible' => '_wheelchair',
    ];

    /** Default facility IDs (matching frontend canonical IDs) */
    private const DEFAULT_FACILITY_IDS = [
        'projector', 'whiteboard', 'videoconf', 'audio', 'display', 'wheelchair',
    ];

    public function __construct(
        private RoomService $roomService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }

    private function getKnownFacilityIds(): array {
        $json = $this->appConfig->getValueString(Application::APP_ID, 'facilities', '');
        if ($json !== '') {
            $facilities = json_decode($json, true);
            if (is_array($facilities)) {
                return array_map(fn($f) => $f['id'], $facilities);
            }
        }
        return self::DEFAULT_FACILITY_IDS;
    }

    /**
     * Export all rooms as CSV string
     */
    public function exportCsv(): string {
        $rooms = $this->roomService->getAllRooms();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, self::EXPORT_COLUMNS);

        foreach ($rooms as $room) {
            $address = $this->parseAddress($room['address'] ?? '');
            fputcsv($output, [
                $room['name'] ?? '',
                $room['email'] ?? '',
                $room['capacity'] ?? 0,
                $room['roomNumber'] ?? '',
                $room['roomType'] ?? '',
                $address['building'],
                $address['street'],
                $address['postalCode'],
                $address['city'],
                implode(',', $room['facilities'] ?? []),
                $room['description'] ?? '',
                ($room['autoAccept'] ?? false) ? 'true' : 'false',
                ($room['active'] ?? true) ? 'true' : 'false',
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Parse and validate a CSV file, return preview data
     *
     * @return array{columns: array, rows: array, detected_format: string}
     */
    public function parseCsv(string $csvContent): array {
        // Normalize line endings to \n
        $csvContent = str_replace(["\r\n", "\r"], "\n", $csvContent);

        $lines = explode("\n", $csvContent);
        $lines = array_filter($lines, fn($l) => trim($l) !== '');
        if (empty($lines)) {
            return ['columns' => [], 'rows' => [], 'detected_format' => 'unknown'];
        }
        $lines = array_values($lines);

        // Detect delimiter (comma, semicolon, or tab)
        $firstLine = $lines[0];
        $delimiter = $this->detectDelimiter($firstLine);

        // Parse header
        $header = str_getcsv(array_shift($lines), $delimiter);
        $header = array_map('trim', $header);

        // Detect format and build column mapping
        $mapping = $this->detectColumnMapping($header);
        $detectedFormat = $mapping['format'];
        $columnMap = $mapping['map'];

        // Parse rows
        $existingRooms = $this->roomService->getAllRooms();
        $existingByName = [];
        $existingByEmail = [];
        $existingById = [];
        foreach ($existingRooms as $room) {
            $existingByName[strtolower($room['name'])] = $room;
            if (!empty($room['email'])) {
                $existingByEmail[strtolower($room['email'])] = $room;
            }
            $existingById[$room['id']] = $room;
        }

        $rows = [];
        $seenNames = [];
        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $fields = str_getcsv($line, $delimiter);
            $row = $this->mapRow($fields, $header, $columnMap);

            // Validate
            $errors = [];
            if (empty($row['name'])) {
                $errors[] = 'Name is required';
            }
            if ($row['capacity'] !== '' && !is_numeric($row['capacity'])) {
                $errors[] = 'Capacity must be a number';
            }
            if (!empty($row['email']) && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email format';
            }

            // Check for duplicates within the CSV
            $nameLower = strtolower($row['name'] ?? '');
            if ($nameLower !== '' && isset($seenNames[$nameLower])) {
                $errors[] = 'Duplicate name in CSV';
            }
            $seenNames[$nameLower] = true;

            // Determine action: match by email, name, or generated slug ID
            $action = 'create';
            $matchedRoom = null;
            if (!empty($row['email']) && isset($existingByEmail[strtolower($row['email'])])) {
                $action = 'update';
                $matchedRoom = $existingByEmail[strtolower($row['email'])];
            } elseif ($nameLower !== '' && isset($existingByName[$nameLower])) {
                $action = 'update';
                $matchedRoom = $existingByName[$nameLower];
            } elseif ($nameLower !== '') {
                // Match by slug: if the name generates the same ID as an existing room
                $slug = $this->generateSlug($row['name']);
                if ($slug !== '' && isset($existingById[$slug])) {
                    $action = 'update';
                    $matchedRoom = $existingById[$slug];
                }
            }

            $rows[] = [
                'line' => $lineNum + 2, // 1-indexed, header is line 1
                'data' => $row,
                'action' => $action,
                'matchedId' => $matchedRoom['id'] ?? null,
                'matchedName' => $matchedRoom['name'] ?? null,
                'errors' => $errors,
            ];
        }

        return [
            'columns' => array_keys($columnMap),
            'rows' => $rows,
            'detected_format' => $detectedFormat,
        ];
    }

    /**
     * Execute import based on parsed data
     *
     * @param string $csvContent Raw CSV content
     * @param string $mode 'create' (skip existing) or 'update' (create + update)
     * @return array{created: int, updated: int, skipped: int, errors: array}
     */
    public function importCsv(string $csvContent, string $mode, CalDAVService $calDAVService, PermissionService $permissionService): array {
        $parsed = $this->parseCsv($csvContent);
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($parsed['rows'] as $row) {
            // Skip rows with validation errors
            if (!empty($row['errors'])) {
                $result['errors'][] = [
                    'line' => $row['line'],
                    'name' => $row['data']['name'] ?? '',
                    'errors' => $row['errors'],
                ];
                continue;
            }

            $data = $row['data'];
            $roomData = $this->buildRoomData($data);

            if ($row['action'] === 'update' && $mode === 'update') {
                // Update existing room
                try {
                    $this->roomService->updateRoom($row['matchedId'], $roomData);
                    $result['updated']++;
                } catch (\Exception $e) {
                    $result['errors'][] = [
                        'line' => $row['line'],
                        'name' => $data['name'] ?? '',
                        'errors' => ['Update failed: ' . $e->getMessage()],
                    ];
                }
            } elseif ($row['action'] === 'create') {
                // Create new room
                try {
                    $room = $this->roomService->createRoom($roomData);

                    // Provision CalDAV calendar
                    $calendarUri = $calDAVService->provisionCalendar($room['userId'], $room['name']);
                    $this->roomService->setCalendarUri($room['id'], $calendarUri);

                    // Initialize empty permissions
                    $permissionService->setPermissions($room['id'], [
                        'viewers' => [],
                        'bookers' => [],
                        'managers' => [],
                    ]);

                    $result['created']++;
                } catch (\Exception $e) {
                    $result['errors'][] = [
                        'line' => $row['line'],
                        'name' => $data['name'] ?? '',
                        'errors' => ['Create failed: ' . $e->getMessage()],
                    ];
                }
            } else {
                // mode=create but room already exists → skip
                $result['skipped']++;
            }
        }

        return $result;
    }

    /**
     * Detect column format (RoomVox or MS365) and build mapping
     */
    private function detectColumnMapping(array $header): array {
        $headerLower = array_map('strtolower', $header);

        // Check if it's RoomVox format (has 'name' column)
        if (in_array('name', $headerLower)) {
            $map = [];
            foreach ($header as $col) {
                $map[$col] = strtolower($col);
            }
            return ['format' => 'roomvox', 'map' => $map];
        }

        // Check if it's MS365 format (has 'DisplayName' column)
        if (in_array('displayname', $headerLower)) {
            $map = [];
            foreach ($header as $col) {
                $colLower = strtolower($col);
                if (isset(self::MS365_COLUMN_MAP[$colLower])) {
                    $map[$col] = self::MS365_COLUMN_MAP[$colLower];
                } else {
                    $map[$col] = $colLower;
                }
            }
            return ['format' => 'ms365', 'map' => $map];
        }

        // Unknown format — try best-effort mapping
        $map = [];
        foreach ($header as $col) {
            $colLower = strtolower($col);
            if (isset(self::MS365_COLUMN_MAP[$colLower])) {
                $map[$col] = self::MS365_COLUMN_MAP[$colLower];
            } else {
                $map[$col] = $colLower;
            }
        }
        return ['format' => 'unknown', 'map' => $map];
    }

    /**
     * Map a CSV row to RoomVox fields using column mapping
     */
    private function mapRow(array $fields, array $header, array $columnMap): array {
        $row = [
            'name' => '',
            'email' => '',
            'capacity' => '',
            'roomNumber' => '',
            'roomType' => '',
            'building' => '',
            'street' => '',
            'postalCode' => '',
            'city' => '',
            'facilities' => '',
            'description' => '',
            'autoAccept' => '',
            'active' => '',
        ];

        $wheelchair = false;

        foreach ($header as $i => $col) {
            $value = trim($fields[$i] ?? '');
            $target = $columnMap[$col] ?? null;

            if ($target === '_wheelchair') {
                $wheelchair = strtolower($value) === 'true' || $value === '1';
                continue;
            }

            if ($target !== null && array_key_exists($target, $row)) {
                $row[$target] = $value;
            }
        }

        // Append wheelchair to facilities if detected from MS365 column
        if ($wheelchair) {
            $facilities = $row['facilities'];
            if ($facilities !== '') {
                $facilities .= ',wheelchair';
            } else {
                $facilities = 'wheelchair';
            }
            $row['facilities'] = $facilities;
        }

        return $row;
    }

    /**
     * Build room data array suitable for RoomService::createRoom/updateRoom
     */
    private function buildRoomData(array $data): array {
        // Build address from parts: "Building, Street, PostalCode, City"
        $addressParts = [];
        if (!empty($data['building'])) {
            $addressParts[] = $data['building'];
        }
        if (!empty($data['street'])) {
            $addressParts[] = $data['street'];
        }
        if (!empty($data['postalCode'])) {
            $addressParts[] = $data['postalCode'];
        }
        if (!empty($data['city'])) {
            $addressParts[] = $data['city'];
        }

        // Parse facilities
        $facilities = [];
        if (!empty($data['facilities'])) {
            $raw = array_map('trim', explode(',', $data['facilities']));
            foreach ($raw as $f) {
                $normalized = $this->normalizeFacility($f);
                if ($normalized !== null) {
                    $facilities[] = $normalized;
                }
            }
            $facilities = array_unique($facilities);
        }

        $roomData = [
            'name' => $data['name'],
            'address' => implode(', ', $addressParts),
            'facilities' => array_values($facilities),
        ];

        if (!empty($data['email'])) {
            $roomData['email'] = $data['email'];
        }
        if ($data['capacity'] !== '') {
            $roomData['capacity'] = (int)$data['capacity'];
        }
        if (!empty($data['roomNumber'])) {
            $roomData['roomNumber'] = $data['roomNumber'];
        }
        if (!empty($data['roomType'])) {
            $roomData['roomType'] = $data['roomType'];
        }
        if (!empty($data['description'])) {
            $roomData['description'] = $data['description'];
        }
        if ($data['autoAccept'] !== '') {
            $roomData['autoAccept'] = in_array(strtolower($data['autoAccept']), ['true', '1', 'yes']);
        }
        if ($data['active'] !== '') {
            $roomData['active'] = in_array(strtolower($data['active']), ['true', '1', 'yes']);
        }

        return $roomData;
    }

    /**
     * Normalize a facility name to match RoomVox known facilities
     */
    private function normalizeFacility(string $facility): ?string {
        $lower = strtolower(trim($facility));
        if ($lower === '') {
            return null;
        }

        $knownIds = $this->getKnownFacilityIds();

        // Direct match against configured facility IDs
        if (in_array($lower, $knownIds)) {
            return $lower;
        }

        // Common aliases (map legacy/external names to canonical IDs)
        $aliases = [
            'beamer' => 'projector',
            'video-conference' => 'videoconf',
            'videoconference' => 'videoconf',
            'videoconferencing' => 'videoconf',
            'video conferencing' => 'videoconf',
            'video conference' => 'videoconf',
            'display-screen' => 'display',
            'screen' => 'display',
            'tv' => 'display',
            'monitor' => 'display',
            'audio-system' => 'audio',
            'speakers' => 'audio',
            'wheelchair-accessible' => 'wheelchair',
            'accessible' => 'wheelchair',
        ];

        $mapped = $aliases[$lower] ?? null;
        if ($mapped !== null && in_array($mapped, $knownIds)) {
            return $mapped;
        }

        // Pass through unknown facilities as-is
        return $lower;
    }

    /**
     * Generate a sample CSV file with headers and one example row
     */
    public function sampleCsv(): string {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, self::EXPORT_COLUMNS);
        fputcsv($output, [
            'Meeting Room 1',
            'room1@company.com',
            12,
            '2.17',
            'meeting-room',
            'Building A',
            'Heidelberglaan 8',
            '3584 CS',
            'Utrecht',
            'projector,whiteboard,videoconf',
            'Large meeting room on 2nd floor',
            'true',
            'true',
        ]);
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /**
     * Detect CSV delimiter by counting occurrences in the header line.
     * Supports comma, semicolon, and tab.
     */
    private function detectDelimiter(string $headerLine): string {
        $delimiters = [',' => 0, ';' => 0, "\t" => 0];

        foreach ($delimiters as $d => &$count) {
            $count = substr_count($headerLine, $d);
        }
        unset($count);

        // Pick the delimiter with the most occurrences
        arsort($delimiters);
        $best = array_key_first($delimiters);

        // Fall back to comma if no delimiters found
        return $delimiters[$best] > 0 ? $best : ',';
    }

    /**
     * Parse internal address format "Building, Street, PostalCode, City" into parts
     */
    private function parseAddress(string $address): array {
        $parts = array_map('trim', explode(',', $address));
        $result = ['building' => '', 'street' => '', 'postalCode' => '', 'city' => ''];

        if (count($parts) >= 4) {
            $result['building'] = $parts[0];
            $result['street'] = $parts[1];
            $result['postalCode'] = $parts[2];
            $result['city'] = implode(', ', array_slice($parts, 3));
        } elseif (count($parts) === 3) {
            $result['building'] = $parts[0];
            $result['street'] = $parts[1];
            if (preg_match('/^\d{4}\s*[A-Z]{2}$/i', $parts[2])) {
                $result['postalCode'] = $parts[2];
            } else {
                $result['city'] = $parts[2];
            }
        } elseif (count($parts) === 2) {
            $result['building'] = $parts[0];
            $result['street'] = $parts[1];
        } elseif (count($parts) === 1 && $parts[0] !== '') {
            $result['building'] = $parts[0];
        }

        return $result;
    }

    /**
     * Generate a URL-safe slug from a name (same logic as RoomService)
     */
    private function generateSlug(string $name): string {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'room';
    }
}
