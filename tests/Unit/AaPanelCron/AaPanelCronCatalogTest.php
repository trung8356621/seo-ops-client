<?php

declare(strict_types=1);

namespace Tests\Unit\AaPanelCron;

use App\Services\AaPanelCron\AaPanelCronCatalog;
use App\Services\AaPanelCron\AaPanelCronPathValidator;
use Tests\TestCase;

final class AaPanelCronCatalogTest extends TestCase
{
    public function test_exports_exactly_five_seo_ops_jobs_without_ssl_tasks(): void
    {
        $catalog = $this->catalog();
        $rows = $catalog->exportAaPanelJson(
            '/www/wwwroot/seo-ops-client',
            '/www/server/php/83/bin/php',
        );

        $this->assertCount(5, $rows);
        $names = array_map(static fn (array $row): string => (string) $row['name'], $rows);
        $this->assertSame([
            'SEO-OPS Scheduler',
            'SEO-OPS External',
            'SEO-OPS Content',
            'SEO-OPS Critical',
            'SEO-OPS General',
        ], $names);

        foreach ($rows as $row) {
            $name = strtolower((string) $row['name']);
            $this->assertStringNotContainsString('ssl', $name);
            $this->assertStringNotContainsString('backup', $name);
            $this->assertSame('toShell', $row['sType']);
            $this->assertSame('minute-n', $row['type']);
            $this->assertSame('1', $row['where1']);
            $this->assertSame(1, $row['flock']);
        }
    }

    public function test_export_commands_match_vps_sample_flags(): void
    {
        $catalog = $this->catalog();
        $rows = $catalog->exportAaPanelJson(
            '/www/wwwroot/seo-ops-client',
            '/www/server/php/83/bin/php',
        );
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row['sBody'];
        }

        $this->assertStringContainsString('artisan schedule:run', $byName['SEO-OPS Scheduler']);
        $this->assertStringContainsString('--queue=automation-external', $byName['SEO-OPS External']);
        $this->assertStringContainsString('--timeout=900', $byName['SEO-OPS External']);
        $this->assertStringContainsString('--max-time=55', $byName['SEO-OPS External']);
        $this->assertStringContainsString('--queue=seo-content-run', $byName['SEO-OPS Content']);
        $this->assertStringContainsString('--max-jobs=1', $byName['SEO-OPS Content']);
        $this->assertStringNotContainsString('--max-time=', $byName['SEO-OPS Content']);
        $this->assertStringContainsString('--queue=automation-critical', $byName['SEO-OPS Critical']);
        $this->assertStringContainsString('--queue=automation,seo,media_generation,default', $byName['SEO-OPS General']);
    }

    public function test_path_override_and_injection_rejection(): void
    {
        $catalog = $this->catalog();
        $task = $catalog->tasks('/www/wwwroot/custom-client', '/www/server/php/82/bin/php')[0];
        $this->assertStringContainsString('/www/wwwroot/custom-client', $task['command']);

        $validator = new AaPanelCronPathValidator;
        $this->assertFalse($validator->isValidProjectDirectory('/www/wwwroot/seo-ops-client; rm -rf /'));
        $this->assertTrue($validator->isValidPhpBinary('/www/server/php/83/bin/php'));
    }

    private function catalog(): AaPanelCronCatalog
    {
        return new AaPanelCronCatalog(new AaPanelCronPathValidator);
    }
}
