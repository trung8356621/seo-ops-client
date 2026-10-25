<?php

declare(strict_types=1);

namespace App\Services\AaPanelCron;

/**
 * Restrict project/PHP paths to absolute Unix filesystem paths (no shell metacharacters).
 */
final class AaPanelCronPathValidator
{
    private const MAX_LENGTH = 255;

    /** Absolute Unix path: /seg/seg — no shell injection characters. */
    private const SAFE_PATH = '#^(/[A-Za-z0-9._-]+)+/?$#';

    public function isValidProjectDirectory(string $path): bool
    {
        return $this->isSafeAbsolutePath($path);
    }

    public function isValidPhpBinary(string $path): bool
    {
        if (! $this->isSafeAbsolutePath($path)) {
            return false;
        }

        $base = basename($path);

        return $base === 'php' || str_starts_with($base, 'php');
    }

    public function assertSafeProjectDirectory(string $path): string
    {
        $normalized = $this->normalize($path);
        if (! $this->isValidProjectDirectory($normalized)) {
            throw new \InvalidArgumentException('Invalid project directory path.');
        }

        return rtrim($normalized, '/');
    }

    public function assertSafePhpBinary(string $path): string
    {
        $normalized = $this->normalize($path);
        if (! $this->isValidPhpBinary($normalized)) {
            throw new \InvalidArgumentException('Invalid PHP binary path.');
        }

        return rtrim($normalized, '/');
    }

    private function isSafeAbsolutePath(string $path): bool
    {
        $normalized = $this->normalize($path);
        if ($normalized === '' || strlen($normalized) > self::MAX_LENGTH) {
            return false;
        }
        if (str_contains($normalized, '..')) {
            return false;
        }
        if (preg_match('/[\s;|&$`\\\\\'"<>(){}!#*?\\[\\]\\n\\r\\t]/', $normalized) === 1) {
            return false;
        }

        return preg_match(self::SAFE_PATH, $normalized) === 1;
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }
}
