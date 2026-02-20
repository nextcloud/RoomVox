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

namespace Sabre\DAV;

if (!class_exists(\Sabre\DAV\Server::class)) {
    class Server {
        public function on(string $event, callable $callback, int $priority = 100): void {}
    }
}

namespace Sabre\VObject;

if (!class_exists(\Sabre\VObject\Document::class)) {
    /**
     * Minimal VObject document stub. Supports property access via __get
     * so that $vObject->VEVENT, $vEvent->DTSTART etc. work in tests.
     */
    class Document {
        private array $components = [];
        private array $properties = [];

        public function __get(string $name): mixed {
            return $this->components[$name] ?? $this->properties[$name] ?? null;
        }

        public function __set(string $name, mixed $value): void {
            if ($value instanceof Component) {
                $this->components[$name] = $value;
            } else {
                $this->properties[$name] = $value;
            }
        }

        public function __isset(string $name): bool {
            return isset($this->components[$name]) || isset($this->properties[$name]);
        }

        public function serialize(): string {
            return 'BEGIN:VCALENDAR' . "\r\n" . 'END:VCALENDAR' . "\r\n";
        }
    }
}

if (!class_exists(\Sabre\VObject\Component::class)) {
    class Component {
        protected array $properties = [];
        protected array $children = [];

        public function __get(string $name): mixed {
            return $this->properties[$name] ?? null;
        }

        public function __set(string $name, mixed $value): void {
            $this->properties[$name] = $value;
        }

        public function __isset(string $name): bool {
            return isset($this->properties[$name]);
        }

        public function __toString(): string {
            return '';
        }

        public function select(string $name): array {
            return $this->children[$name] ?? [];
        }

        public function addChild(string $name, mixed $child): void {
            $this->children[$name][] = $child;
        }
    }
}

if (!class_exists(\Sabre\VObject\Property::class)) {
    class Property {
        private mixed $value;
        private array $parameters = [];

        public function __construct(mixed $value = null) {
            $this->value = $value;
        }

        public function getDateTime(): ?\DateTimeInterface {
            if ($this->value instanceof \DateTimeInterface) {
                return $this->value;
            }
            return null;
        }

        public function __toString(): string {
            if ($this->value instanceof \DateTimeInterface) {
                return $this->value->format('Ymd\THis\Z');
            }
            return (string)($this->value ?? '');
        }

        public function __get(string $name): mixed {
            return $this->parameters[$name] ?? null;
        }

        public function __set(string $name, mixed $value): void {
            $this->parameters[$name] = $value;
        }

        public function __isset(string $name): bool {
            return isset($this->parameters[$name]);
        }

        public function offsetGet(mixed $offset): mixed {
            return $this->parameters[$offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void {
            $this->parameters[$offset] = $value;
        }

        public function offsetExists(mixed $offset): bool {
            return isset($this->parameters[$offset]);
        }
    }
}

if (!class_exists(\Sabre\VObject\Reader::class)) {
    class Reader {
        /** @var callable|null Custom parser callback for testing */
        private static $testParser = null;

        public static function setTestParser(?callable $parser): void {
            self::$testParser = $parser;
        }

        public static function read(string $data, int $options = 0, ?string $charset = null): mixed {
            if (self::$testParser !== null) {
                return (self::$testParser)($data);
            }
            return new Document();
        }
    }
}

namespace Sabre\VObject\Component;

if (!class_exists(\Sabre\VObject\Component\VEvent::class)) {
    class VEvent extends \Sabre\VObject\Component {}
}

if (!class_exists(\Sabre\VObject\Component\VCalendar::class)) {
    class VCalendar extends \Sabre\VObject\Document {}
}

namespace Sabre\CalDAV\Xml\Property;

if (!class_exists(\Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp::class)) {
    class ScheduleCalendarTransp {
        public const TRANSPARENT = 'transparent';
        public const OPAQUE = 'opaque';
    }
}

namespace Sabre\VObject;

if (!class_exists(\Sabre\VObject\ITip\Message::class)) {
    // Defined in itip namespace block below
}

namespace Sabre\VObject\ITip;

if (!class_exists(\Sabre\VObject\ITip\Message::class)) {
    class Message {
        public string $sender = '';
        public string $recipient = '';
        public ?\Sabre\VObject\Document $message = null;
        public string $scheduleStatus = '';
        public string $method = '';
        public string $component = 'VEVENT';
        public ?string $uid = null;
        public ?string $sequence = null;
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

namespace OCA\RoomVox\Service;

// Stub for MailService if not already autoloaded
if (!class_exists(\OCA\RoomVox\Service\MailService::class)) {
    class MailService {
        public function sendAccepted(array $room, $message): void {}
        public function sendConflict(array $room, $message): void {}
        public function notifyManagers(array $room, $message): void {}
        public function sendCancellation(array $room, $message): void {}
    }
}
