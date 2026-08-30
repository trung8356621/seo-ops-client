<?php

declare(strict_types=1);

namespace App\Help;

/**
 * Resolves the sibling seo-ops-help checkout for local development.
 * Path is relative (junction under .local/help-repo) — never machine absolute.
 */
final class HelpLocalRepo
{
    public static function path(): ?string
    {
        $configured = trim((string) config('help.local.path', ''));
        if ($configured !== '') {
            $resolved = self::normalizeExistingRoot($configured);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $default = base_path('.local'.DIRECTORY_SEPARATOR.'help-repo');

        return self::normalizeExistingRoot($default);
    }

    public static function isAvailable(): bool
    {
        return self::path() !== null;
    }

    /**
     * Prefer local sibling repo when APP_ENV=local (or HELP_LOCAL_REPO=1) and path exists.
     * Production never uses this unless HELP_LOCAL_REPO is explicitly forced.
     */
    public static function shouldUseLocal(): bool
    {
        $flag = config('help.local.enabled');
        if ($flag === true || $flag === 1 || $flag === '1') {
            return self::isAvailable();
        }
        if ($flag === false || $flag === 0 || $flag === '0') {
            return false;
        }

        if (! app()->environment('local')) {
            return false;
        }

        return self::isAvailable();
    }

    public static function docsMtime(): int
    {
        $root = self::path();
        if ($root === null) {
            return 0;
        }

        $max = 0;
        $docs = $root.DIRECTORY_SEPARATOR.'docs';
        if (is_dir($docs)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($docs, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                    continue;
                }
                if (strtolower($file->getExtension()) !== 'md') {
                    continue;
                }
                $max = max($max, (int) $file->getMTime());
            }
        }

        foreach (['groups.json', 'VERSION'] as $name) {
            $file = $root.DIRECTORY_SEPARATOR.$name;
            if (is_file($file)) {
                $max = max($max, (int) filemtime($file));
            }
        }

        return $max;
    }

    private static function normalizeExistingRoot(string $path): ?string
    {
        $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        if ($path === '' || ! is_dir($path)) {
            return null;
        }
        $docs = $path.DIRECTORY_SEPARATOR.'docs';
        if (! is_dir($docs)) {
            return null;
        }

        $real = realpath($path);

        return is_string($real) && $real !== '' ? $real : $path;
    }
}
