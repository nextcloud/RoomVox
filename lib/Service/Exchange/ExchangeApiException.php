<?php

declare(strict_types=1);

namespace OCA\RoomVox\Service\Exchange;

class ExchangeApiException extends \RuntimeException {
    public function __construct(
        string $message,
        private int $httpStatus = 0,
        private string $graphErrorCode = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int {
        return $this->httpStatus;
    }

    public function getGraphErrorCode(): string {
        return $this->graphErrorCode;
    }

    public function isTransient(): bool {
        return in_array($this->httpStatus, [429, 500, 502, 503, 504], true);
    }

    public function isAuthError(): bool {
        return in_array($this->httpStatus, [401, 403], true)
            || in_array($this->graphErrorCode, ['AuthenticationError', 'InvalidAuthenticationToken'], true);
    }
}
