<?php

declare(strict_types=1);

namespace OCA\RoomVox\Tests\Unit\Support;

use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * An IAppConfig mock backed by an array, for the string accessors and hasKey().
 *
 * Every key read is recorded in $readKeys, so a test can assert that a code
 * path never touched a key (the Exchange credentials, for one).
 */
trait InMemoryAppConfig {
    /** @var array<string, string> */
    protected array $appValues = [];

    /** @var string[] */
    protected array $readKeys = [];

    protected function createInMemoryAppConfig(): IAppConfig&MockObject {
        $config = $this->createMock(IAppConfig::class);

        $config->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default = '') {
                $this->readKeys[] = $key;
                return $this->appValues[$key] ?? $default;
            }
        );
        $config->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value) {
                $this->appValues[$key] = $value;
                return true;
            }
        );
        $config->method('hasKey')->willReturnCallback(
            function (string $app, string $key) {
                $this->readKeys[] = $key;
                return array_key_exists($key, $this->appValues);
            }
        );

        return $config;
    }
}
