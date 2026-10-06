<?php

declare(strict_types=1);

namespace App\Services\ImportLab;

use RuntimeException;

class ImportLabSafetyGuard
{
    /** @param array<string, string> $databases */
    public function assertSafe(string $environment, string $applicationPath, array $databases): void
    {
        if (! in_array($environment, ['local', 'testing'], true)) {
            throw new RuntimeException('client-test:reset is refused outside local/testing.');
        }

        $instance = strtolower(basename(str_replace('\\', '/', rtrim($applicationPath, '/\\'))));
        if ($instance !== 'seo-ops-import-test') {
            throw new RuntimeException("Application path is not the dedicated import-test instance [{$applicationPath}].");
        }

        if (count($databases) !== 3) {
            throw new RuntimeException('Exactly three import-lab databases must be configured.');
        }

        foreach ($databases as $label => $database) {
            if (! $this->isSafeLabDatabaseName($database)) {
                throw new RuntimeException("Unsafe {$label} database name [{$database}]. Expected *_import_lab_*.");
            }
        }

        if (count(array_unique($databases)) !== 3) {
            throw new RuntimeException('Core, SEO, and Seeding databases must be distinct.');
        }
    }

    public function isSafeLabDatabaseName(string $name): bool
    {
        return preg_match('/^[a-z0-9_]+_import_lab_[a-z0-9_]+$/Di', $name) === 1;
    }
}
