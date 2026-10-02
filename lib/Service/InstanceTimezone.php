<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service;

use OCP\IConfig;

/**
 * The timezone a room's wall-clock settings and outgoing mails are read in.
 *
 * Nextcloud runs PHP in UTC regardless of where the instance is, so
 * `new \DateTime()` and a parsed ISO string carry UTC or whatever offset the
 * client happened to send. Booking hours ("08:00-18:00") and the times in a
 * confirmation mail are local wall-clock values, so they have to be compared
 * and rendered in the instance's own zone: `default_timezone` from config.php,
 * the same setting Nextcloud itself falls back to for users without one.
 *
 * Rooms have no timezone of their own; if that is ever added, this is the
 * place to resolve it.
 */
class InstanceTimezone {
    public function __construct(
        private IConfig $config,
    ) {
    }

    public function get(): \DateTimeZone {
        $name = $this->config->getSystemValueString('default_timezone', 'UTC');

        try {
            return new \DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (\Exception $e) {
            return new \DateTimeZone('UTC');
        }
    }
}
