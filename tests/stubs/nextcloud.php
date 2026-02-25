<?php

/**
 * Nextcloud framework stubs for controller testing.
 * Only loaded if the real classes are not available from nextcloud/ocp.
 */

namespace OCP\AppFramework;

if (!class_exists(\OCP\AppFramework\Controller::class)) {
    abstract class Controller {
        protected \OCP\IRequest $request;

        public function __construct(string $appName, \OCP\IRequest $request) {
            $this->request = $request;
        }
    }
}

namespace OCP\AppFramework\Http;

if (!class_exists(\OCP\AppFramework\Http\JSONResponse::class)) {
    class JSONResponse {
        private mixed $data;
        private int $status;

        public function __construct(mixed $data = [], int $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }

        public function getData(): mixed {
            return $this->data;
        }

        public function getStatus(): int {
            return $this->status;
        }
    }
}

if (!class_exists(\OCP\AppFramework\Http\DataDownloadResponse::class)) {
    class DataDownloadResponse {
        public function __construct(string $data, string $filename, string $contentType) {}
    }
}

namespace OCP\AppFramework\Http\Attribute;

if (!class_exists(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class)) {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    class NoAdminRequired {}
}

if (!class_exists(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class)) {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    class NoCSRFRequired {}
}

if (!class_exists(\OCP\AppFramework\Http\Attribute\PublicPage::class)) {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    class PublicPage {}
}
