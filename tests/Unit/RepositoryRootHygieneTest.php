<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RepositoryRootHygieneTest extends TestCase
{
    public function test_temporary_probe_and_report_artifacts_are_not_kept_in_repository_root(): void
    {
        $root = dirname(__DIR__, 2);
        $patterns = [
            '_audit_*.php',
            '_base*.php',
            '_cleanup_*.php',
            '_clr*.php',
            '_cu*.php',
            '_dbg*.php',
            '_del.php',
            '_f[0-9]*.php',
            '_fin*.php',
            '_find_*.php',
            '_gh*.php',
            '_inv*.php',
            '_r[0-9]*.php',
            '_read_dbg.php',
            '_restore_*.php',
            '_touch_*.php',
            '_trash*.php',
            '_try_*.php',
            '_upd*.php',
            '_v3_*.php',
            '_w_*.py',
            '_*_report.json',
            '_kd_*.json',
            'debug-*.log',
            'remaining_wp_post_id.txt',
        ];

        $artifacts = [];

        foreach ($patterns as $pattern) {
            foreach (glob($root.DIRECTORY_SEPARATOR.$pattern) ?: [] as $path) {
                $artifacts[] = basename($path);
            }
        }

        sort($artifacts);

        self::assertSame(
            [],
            array_values(array_unique($artifacts)),
            'Temporary probe/report artifacts must not be stored in the repository root.'
        );
    }
}
