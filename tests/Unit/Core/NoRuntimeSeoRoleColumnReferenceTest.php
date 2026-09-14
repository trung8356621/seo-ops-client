<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Tests\TestCase;

/**
 * Guard: runtime PHP must not read/write users.seo_role column.
 * Allowed: form-only key, lang labels, migration history, backfill helpers.
 */
final class NoRuntimeSeoRoleColumnReferenceTest extends TestCase
{
    public function test_runtime_php_has_no_users_seo_role_column_access(): void
    {
        $roots = [
            base_path('app'),
            base_path('addons'),
        ];

        $allowedPathFragments = [
            DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR,
            'SeoRoleAssignment.php',
            'SeoMembersSectionContributor.php', // form-only FORM_KEY = seo_role
            'LegacySeoRoleBridge.php',
            'SyncAddonPermissionsCommand.php', // CLI help text for backfill
        ];

        $forbiddenPatterns = [
            '/\$[a-zA-Z_][a-zA-Z0-9_]*->seo_role\b/',
            "/where\(\s*'seo_role'/",
            "/whereIn\(\s*'seo_role'/",
            "/\['seo_role'\s*=>/",
            '/forceFill\(\s*\[[^\]]*seo_role/',
            "/update\(\s*\[[^\]]*seo_role/",
        ];

        $violations = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
                foreach ($allowedPathFragments as $frag) {
                    if (str_contains($normalized, str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $frag))) {
                        continue 2;
                    }
                }
                // Skip tests
                if (str_contains($normalized, DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                // Skip lang / views
                if (str_contains($normalized, DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR)
                    || str_contains($normalized, DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR)
                ) {
                    continue;
                }

                $contents = (string) file_get_contents($path);
                foreach ($forbiddenPatterns as $pattern) {
                    if (preg_match($pattern, $contents) === 1) {
                        $violations[] = $path.' matches '.$pattern;
                    }
                }
            }
        }

        self::assertSame([], $violations, "Runtime seo_role column refs:\n".implode("\n", $violations));
    }
}
