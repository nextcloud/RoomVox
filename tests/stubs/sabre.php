<?php

/**
 * Minimal Sabre DAV/VObject stubs for unit testing.
 * These provide class definitions so PHP can load RoomVox classes
 * that extend or use Sabre classes.
 */

namespace Sabre\DAV;

if (!class_exists(\Sabre\DAV\ServerPlugin::class)) {
    abstract class ServerPlugin {
        abstract public function getPluginName(): string;
        abstract public function getPluginInfo(): array;
    }
}

namespace Sabre\VObject;

if (!class_exists(\Sabre\VObject\Reader::class)) {
    class Reader {
        public static function read(string $data, int $options = 0, ?string $charset = null): mixed {
            return null;
        }
    }
}

namespace Sabre\VObject\Component;

if (!class_exists(\Sabre\VObject\Component\VEvent::class)) {
    class VEvent {}
}

namespace Sabre\CalDAV\Xml\Property;

if (!class_exists(\Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp::class)) {
    class ScheduleCalendarTransp {
        public const TRANSPARENT = 'transparent';
        public const OPAQUE = 'opaque';
    }
}

namespace OCA\DAV\CalDAV;

if (!class_exists(\OCA\DAV\CalDAV\CalDavBackend::class)) {
    class CalDavBackend {
        public function getCalendarsForUser(string $principalUri): array { return []; }
        public function getCalendarObjects(int $calendarId): array { return []; }
        public function getCalendarObject(int $calendarId, string $objectUri): ?array { return null; }
        public function createCalendarObject(int $calendarId, string $objectUri, string $data): void {}
        public function updateCalendarObject(int $calendarId, string $objectUri, string $data): void {}
        public function deleteCalendarObject(int $calendarId, string $objectUri, int $type = 0, bool $permanent = false): void {}
        public function createCalendar(string $principalUri, string $calendarUri, array $properties): void {}
        public function deleteCalendar(int $calendarId): void {}
        public function calendarQuery(int $calendarId, array $filters, int $calendarType = 0): array { return []; }
    }
}
