<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Locates migration files across Client shell + external addon monorepo.
 * No hard-coded business addon class list — discovers {slug}/database/migrations under addons root.
 */
final class MigrationPathLocator
{
    /**
     * @return list<string>
     */
    public static function searchRoots(?string $projectRoot = null): array
    {
        $root = $projectRoot;
        if ($root === null || $root === '') {
            $root = function_exists('base_path') ? base_path() : dirname(__DIR__, 3);
        }

        $roots = [
            $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations',
        ];

        $addonsRoot = self::addonsRoot($root);
        if ($addonsRoot !== null) {
            // _legacy-obsolete is NOT part of fresh-install discovery.
            // Legacy upgrade uses tolerant upgrader / explicit path only.
            foreach (glob($addonsRoot.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $addonDir) {
                if (str_ends_with($addonDir, DIRECTORY_SEPARATOR.'_legacy-obsolete')
                    || basename($addonDir) === '_legacy-obsolete') {
                    continue;
                }
                $migrations = $addonDir.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
                if (is_dir($migrations)) {
                    $roots[] = $migrations;
                }
            }
        }

        return array_values(array_unique($roots));
    }

    public static function find(string $basename, ?string $projectRoot = null): ?string
    {
        $basename = basename($basename);
        foreach (self::searchRoots($projectRoot) as $dir) {
            $candidate = $dir.DIRECTORY_SEPARATOR.$basename;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function addonsRoot(string $projectRoot): ?string
    {
        if (function_exists('config') && function_exists('app')) {
            try {
                $app = app();
                if ($app instanceof \Illuminate\Foundation\Application) {
                    $configured = config('addons.addons_path');
                    if (is_string($configured) && $configured !== '') {
                        if (preg_match('#^[A-Za-z]:[\\\\/]#', $configured) || str_starts_with($configured, '/') || str_starts_with($configured, '\\')) {
                            return is_dir($configured) ? $configured : null;
                        }
                        $abs = $projectRoot.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured);

                        return is_dir($abs) ? $abs : null;
                    }
                }
            } catch (\Throwable) {
                // pure PHPUnit / no app container
            }
        }

        $junction = $projectRoot.DIRECTORY_SEPARATOR.'addons';
        if (is_dir($junction)) {
            return $junction;
        }

        $sibling = dirname($projectRoot).DIRECTORY_SEPARATOR.'omnichannel-addons';
        if (is_dir($sibling)) {
            return $sibling;
        }

        return null;
    }
}