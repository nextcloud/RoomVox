<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load stubs for Sabre DAV/VObject and OCA\DAV classes
require_once __DIR__ . '/stubs/sabre.php';

// Register OCP/NCU stubs from nextcloud/ocp package (package has no autoload config)
$ocpPath = __DIR__ . '/../vendor/nextcloud/ocp';
spl_autoload_register(function (string $class) use ($ocpPath): void {
    if (str_starts_with($class, 'OCP\\') || str_starts_with($class, 'NCU\\')) {
        $file = $ocpPath . '/' . str_replace('\\', '/', $class) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});
