<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Support;

use OCP\IL10N;

/**
 * An IL10N that returns the English source text with its parameters filled
 * in, so tests can keep asserting on the messages users see in English.
 */
class EnglishL10N implements IL10N {
    public function t(string $text, $parameters = []): string {
        $parameters = is_array($parameters) ? $parameters : [$parameters];
        return $parameters === [] ? $text : vsprintf($text, $parameters);
    }

    public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
        $text = str_replace('%n', (string)$count, $count === 1 ? $text_singular : $text_plural);
        return $parameters === [] ? $text : vsprintf($text, $parameters);
    }

    public function l(string $type, $data, array $options = []) {
        return $data instanceof \DateTimeInterface ? $data->format('c') : (string)$data;
    }

    public function getLanguageCode(): string {
        return 'en';
    }

    public function getLocaleCode(): string {
        return 'en_US';
    }
}
