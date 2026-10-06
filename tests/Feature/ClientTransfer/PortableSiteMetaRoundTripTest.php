<?php

declare(strict_types=1);

namespace Tests\Feature\ClientTransfer;

use App\Models\Service;
use App\Models\Site;
use App\Models\SiteMeta;
use App\Models\SiteService;
use App\Models\User;
use App\Services\ClientTransfer\ClientTransferExporter;
use App\Services\ClientTransfer\ClientTransferImporter;
use App\Services\ClientTransfer\Datasets\SitesDataset;
use App\Services\ClientTransfer\Support\ZipArchiveManager;
use Illuminate\Support\Facades\DB;
use Tests\Unit\ClientTransfer\TransferDatabaseTestCase;

final class PortableSiteMetaRoundTripTest extends TransferDatabaseTestCase
{
    private function wipeAllTables(): void
    {
        $this->wipeBusinessTables();
        DB::table('site_meta')->delete();
        DB::table('site_services')->delete();
        DB::table('sites')->delete();
        DB::table('users')->delete();
    }

    /**
     * Proves:
     * 1. seo_read_token round-trips byte-for-byte
     * 2. seo_migration_token round-trips byte-for-byte
     * 3. seo_sync_callback_secret round-trips when present
     * 5. portable normal site config round-trips
     */
    public function test_portable_site_meta_round_trips_exact_tokens_and_config(): void
    {
        $owner = User::query()->create([
            'name' => 'Clone Source Owner',
            'email' => 'source-owner@clone.test',
            'role' => 'owner',
        ]);

        $site = Site::query()->create([
            'domain' => 'source-clone.test',
            'user_id' => $owner->id,
            'status' => 'active',
            'ssl' => true,
        ]);

        $expectedMetas = [
            'seo_platform' => 'wordpress',
            'seo_publisher_key' => 'wordpress',
            'seo_domain_type' => 'news',
            'seo_industry_context_key' => 'tech_publishing',
            'seo_primary_language' => 'vi',
            'seo_read_token' => 'read_tok_exact_9876543210_!@#$%^&*()',
            'seo_migration_token' => 'mig_tok_exact_1234567890_!@#$%^&*()',
            'seo_sync_callback_secret' => 'hmac_secret_exact_abcdef_123456',
            'wp_home_url' => 'https://source-clone.test',
        ];

        foreach ($expectedMetas as $key => $val) {
            SiteMeta::query()->create([
                'site_id' => $site->id,
                'meta_key' => $key,
                'meta_value' => $val,
            ]);
        }

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_portable_metas.zip';
        (new ClientTransferExporter)->export($zipPath);

        // Verify exported NDJSON record content
        $extractDir = $this->tempDir.DIRECTORY_SEPARATOR.'extracted_check';
        $res = ZipArchiveManager::extractAndValidate($zipPath, $extractDir);
        $manifest = $res['manifest'];
        self::assertArrayHasKey('sites', $manifest->datasets);

        $sitePartFile = $extractDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $manifest->datasets['sites']->parts[0]->file);
        $lines = file($sitePartFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertNotEmpty($lines);
        $siteRecord = json_decode($lines[0], true);

        self::assertArrayHasKey('portable_meta', $siteRecord);
        $exportedPortable = $siteRecord['portable_meta'];

        foreach ($expectedMetas as $key => $val) {
            self::assertSame($val, $exportedPortable[$key], "Exported portable meta [{$key}] must match byte-for-byte.");
        }

        // Clean database and import to fresh client
        $this->wipeAllTables();

        $importer = new ClientTransferImporter;
        $importResult = $importer->import($zipPath, force: true);

        self::assertSame(0, $importResult['total_failed'], 'Import should succeed without failures.');
        self::assertSame(1, $importResult['dataset_stats']['sites']['imported']);

        $targetSite = Site::query()->where('domain', 'source-clone.test')->firstOrFail();

        foreach ($expectedMetas as $key => $expectedVal) {
            $actualVal = SiteMeta::query()
                ->where('site_id', $targetSite->id)
                ->where('meta_key', $key)
                ->value('meta_value');

            self::assertSame(
                $expectedVal,
                $actualVal,
                "Restored target site meta [{$key}] must equal source value byte-for-byte."
            );
        }
    }

    /**
     * Proves:
     * 4. target import does NOT generate replacement tokens
     */
    public function test_target_import_does_not_generate_replacement_tokens(): void
    {
        $site = Site::query()->create([
            'domain' => 'no-tokens.test',
            'status' => 'active',
        ]);

        // Provide normal config but NO bridge tokens
        SiteMeta::query()->create([
            'site_id' => $site->id,
            'meta_key' => 'seo_platform',
            'meta_value' => 'custom',
        ]);
        SiteMeta::query()->create([
            'site_id' => $site->id,
            'meta_key' => 'seo_domain_type',
            'meta_value' => 'news',
        ]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_no_tokens.zip';
        (new ClientTransferExporter)->export($zipPath);

        $this->wipeAllTables();

        (new ClientTransferImporter)->import($zipPath, force: true);

        $targetSite = Site::query()->where('domain', 'no-tokens.test')->firstOrFail();

        self::assertNull(
            SiteMeta::query()->where('site_id', $targetSite->id)->where('meta_key', 'seo_read_token')->first(),
            'Target import must not invent seo_read_token when absent in source.'
        );
        self::assertNull(
            SiteMeta::query()->where('site_id', $targetSite->id)->where('meta_key', 'seo_migration_token')->first(),
            'Target import must not invent seo_migration_token when absent in source.'
        );
        self::assertNull(
            SiteMeta::query()->where('site_id', $targetSite->id)->where('meta_key', 'seo_sync_callback_secret')->first(),
            'Target import must not invent seo_sync_callback_secret when absent in source.'
        );
    }

    /**
     * Proves:
     * 6. unrelated/dynamic site_meta keys are NOT exported
     * 7. runtime/plugin cache metadata is NOT exported
     * 8. service_key / DB/API credentials remain excluded
     */
    public function test_runtime_cache_and_sensitive_credentials_remain_excluded(): void
    {
        $service = Service::query()->create([
            'name' => 'SEO Content AI',
            'slug' => 'seo-content-ai',
            'is_active' => true,
            'service_key' => 'super-secret-service-key-do-not-export',
        ]);

        $site = Site::query()->create([
            'domain' => 'clean-export.test',
            'status' => 'active',
        ]);

        SiteService::query()->create([
            'site_id' => $site->id,
            'service_id' => $service->id,
            'bound_type' => 'site',
            'status' => 'active',
            'settings' => [
                'language' => 'vi',
                'api_token' => 'leak_api_token',
                'db_password' => 'secret_db_pass',
            ],
        ]);

        $allowedMetas = [
            'seo_platform' => 'wordpress',
            'seo_read_token' => 'allowed-read-token',
            'seo_migration_token' => 'allowed-migration-token',
        ];

        $forbiddenMetas = [
            'seo_wp_plugin_info' => '{"version":"1.0.69","active":"rank_math"}',
            'seo_wp_plugin_info_fetched_at' => '2026-10-06T10:00:00Z',
            'seo_wp_heartbeat' => '{"alive":true,"timestamp":1728216000}',
            'seo_plugin' => 'rank_math',
            'seo_site_sync_v2_handshake' => '{"status":"ok"}',
            'seo_site_sync_v2_bootstrapped_at' => '2026-10-06T10:00:00Z',
            'seo_site_sync_v2_backfill_report' => '{"backfilled":10}',
            'seo_site_sync_v3_baseline_completed_at' => '2026-10-06T10:00:00Z',
            'seo_site_sync_v3_delta_checkpoint_at' => '2026-10-06T10:00:00Z',
            'seo_site_sync_v3_baseline_generation' => '3',
            'seo_site_sync_v3_language_checkpoints' => '{"vi":"2026-10-06"}',
            'seo_link_analysis_snapshot' => '{"total":50}',
            'seo_keyword_dictionary' => '{"words":["a","b"]}',
            'gsc_query_snapshot' => '{"impressions":100}',
            'seo_is_main' => '1',
            'custom_runtime_checkpoint' => 'transient',
        ];

        foreach (array_merge($allowedMetas, $forbiddenMetas) as $k => $v) {
            SiteMeta::query()->create([
                'site_id' => $site->id,
                'meta_key' => $k,
                'meta_value' => $v,
            ]);
        }

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_runtime_filter.zip';
        (new ClientTransferExporter)->export($zipPath);

        $extractDir = $this->tempDir.DIRECTORY_SEPARATOR.'extracted_runtime_check';
        $res = ZipArchiveManager::extractAndValidate($zipPath, $extractDir);
        $manifest = $res['manifest'];

        $sitePartFile = $extractDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $manifest->datasets['sites']->parts[0]->file);
        $record = json_decode(file($sitePartFile)[0], true);
        $exportedPortable = $record['portable_meta'];

        foreach ($allowedMetas as $k => $v) {
            self::assertSame($v, $exportedPortable[$k]);
        }

        foreach (array_keys($forbiddenMetas) as $k) {
            self::assertArrayNotHasKey($k, $exportedPortable, "Dynamic/runtime meta key [{$k}] must NOT be exported.");
        }

        // Verify service_key and credentials remain excluded
        $allPackageFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($extractDir));
        foreach ($allPackageFiles as $file) {
            if ($file->isFile()) {
                $content = file_get_contents($file->getPathname());
                self::assertStringNotContainsString('super-secret-service-key-do-not-export', $content);
                self::assertStringNotContainsString('leak_api_token', $content);
                self::assertStringNotContainsString('secret_db_pass', $content);
            }
        }
    }

    /**
     * Proves:
     * 9. existing target Site receives the portable meta values without duplicate rows
     */
    public function test_existing_target_site_receives_portable_meta_without_duplicate_rows(): void
    {
        // 1. Source site
        $sourceSite = Site::query()->create([
            'domain' => 'existing-target.test',
            'status' => 'active',
        ]);
        SiteMeta::query()->create([
            'site_id' => $sourceSite->id,
            'meta_key' => 'seo_platform',
            'meta_value' => 'wordpress',
        ]);
        SiteMeta::query()->create([
            'site_id' => $sourceSite->id,
            'meta_key' => 'seo_read_token',
            'meta_value' => 'fresh-cloned-read-token',
        ]);
        SiteMeta::query()->create([
            'site_id' => $sourceSite->id,
            'meta_key' => 'seo_migration_token',
            'meta_value' => 'fresh-cloned-mig-token',
        ]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_existing_target.zip';
        (new ClientTransferExporter)->export($zipPath);

        // 2. Prep target DB: Site already exists with stale or duplicated rows
        $this->wipeAllTables();

        $existingSite = Site::query()->create([
            'domain' => 'existing-target.test',
            'status' => 'active',
        ]);

        // Duplicate rows for seo_read_token before import
        SiteMeta::query()->create([
            'site_id' => $existingSite->id,
            'meta_key' => 'seo_read_token',
            'meta_value' => 'stale-token-1',
        ]);
        SiteMeta::query()->create([
            'site_id' => $existingSite->id,
            'meta_key' => 'seo_read_token',
            'meta_value' => 'stale-token-2',
        ]);

        $res = (new ClientTransferImporter)->import($zipPath, force: true);
        self::assertSame(1, $res['dataset_stats']['sites']['imported']);

        $tokenRows = SiteMeta::query()
            ->where('site_id', $existingSite->id)
            ->where('meta_key', 'seo_read_token')
            ->get();

        self::assertCount(1, $tokenRows, 'Duplicate rows must be eliminated.');
        self::assertSame('fresh-cloned-read-token', $tokenRows->first()->meta_value);

        $migToken = SiteMeta::query()
            ->where('site_id', $existingSite->id)
            ->where('meta_key', 'seo_migration_token')
            ->value('meta_value');
        self::assertSame('fresh-cloned-mig-token', $migToken);
    }

    /**
     * Proves:
     * 10. transfer logs/errors never expose raw token values
     */
    public function test_transfer_logs_and_errors_never_expose_raw_token_values(): void
    {
        $rawSecretToken = 'super_secret_raw_token_value_xyz_99999';

        $dataset = new SitesDataset;
        $targetSite = Site::query()->create([
            'domain' => 'validation-fail.test',
            'status' => 'active',
        ]);

        // Mismatched token on target site
        SiteMeta::query()->create([
            'site_id' => $targetSite->id,
            'meta_key' => 'seo_read_token',
            'meta_value' => 'target_different_value',
        ]);

        $record = [
            'ref' => 'site:99',
            'domain' => 'validation-fail.test',
            'portable_meta' => [
                'seo_platform' => 'wordpress',
                'seo_read_token' => $rawSecretToken,
                'seo_migration_token' => 'another_secret_mig_token',
            ],
        ];

        // Semantic validation directly returns error
        $errors = $dataset->validateImportedPortableMeta($targetSite, 'site:99', $record);
        self::assertNotEmpty($errors);
        foreach ($errors as $errorMsg) {
            self::assertStringNotContainsString($rawSecretToken, $errorMsg);
            self::assertStringNotContainsString('another_secret_mig_token', $errorMsg);
            self::assertStringContainsString('site:99', $errorMsg);
            self::assertTrue(str_contains($errorMsg, 'seo_read_token') || str_contains($errorMsg, 'seo_migration_token'));
        }

        // Test DB error sanitization inside importRecord
        $run = new \App\Services\ClientTransfer\Logging\ImportRun('test-run-sanitization');
        $refMap = new \App\Services\ClientTransfer\Support\ReferenceMap('test-run-sanitization');
        $blobs = new \App\Services\ClientTransfer\Support\BlobManager($this->tempDir);

        // Force a DB exception by passing empty domain
        $badRecord = [
            'ref' => 'site:100',
            'domain' => '',
            'portable_meta' => [
                'seo_read_token' => $rawSecretToken,
            ],
        ];

        $dataset->importRecord($badRecord, $refMap, $run, $blobs, 'part_0001.ndjson', 0);

        $logs = $refMap->getLogsChunk(0, 50);
        self::assertNotEmpty($logs);
        foreach ($logs as $log) {
            self::assertStringNotContainsString($rawSecretToken, (string) ($log['message'] ?? ''));
        }
    }

    /**
     * Proves:
     * Retry export uses the same SitesDataset mapping and preserves portable meta.
     */
    public function test_retry_export_and_import_preserves_portable_meta(): void
    {
        $site = Site::query()->create([
            'domain' => 'retry-clone.test',
            'status' => 'active',
        ]);
        SiteMeta::query()->create([
            'site_id' => $site->id,
            'meta_key' => 'seo_platform',
            'meta_value' => 'wordpress',
        ]);
        SiteMeta::query()->create([
            'site_id' => $site->id,
            'meta_key' => 'seo_read_token',
            'meta_value' => 'retry-token-read-exact',
        ]);
        SiteMeta::query()->create([
            'site_id' => $site->id,
            'meta_key' => 'seo_migration_token',
            'meta_value' => 'retry-token-mig-exact',
        ]);

        $fullZip = $this->tempDir.DIRECTORY_SEPARATOR.'full_for_retry.zip';
        (new ClientTransferExporter)->export($fullZip);

        // Create failure package using QuarantinePackageBuilder
        $runId = 'orig-retry-run';
        $refMap = new \App\Services\ClientTransfer\Support\ReferenceMap($runId);
        $refMap->logRecord(
            runId: $runId,
            dataset: 'sites',
            part: 'core/sites/part-000001.ndjson',
            recordRef: 'site:'.$site->id,
            status: 'failed',
            errorType: 'DB_ERROR',
            message: 'Transient retry simulated',
        );

        $extractFull = $this->tempDir.DIRECTORY_SEPARATOR.'extract_full_for_retry';
        ZipArchiveManager::extractAndValidate($fullZip, $extractFull);

        $failureZip = $this->tempDir.DIRECTORY_SEPARATOR.'simulated-failure.zip';
        \App\Services\ClientTransfer\QuarantinePackageBuilder::buildFromRefMap(
            $runId,
            $refMap,
            $extractFull,
            $failureZip,
            new \App\Services\ClientTransfer\DatasetRegistry,
        );

        // Export retry data package
        $retryExporter = new \App\Services\ClientTransfer\RetryDataPackageExporter;
        $retryResult = $retryExporter->export($failureZip);

        self::assertFileExists($retryResult['destination_path']);
        self::assertSame(1, $retryResult['counts']['sites']);

        // Inspect retry zip NDJSON content
        $extractRetryDir = $this->tempDir.DIRECTORY_SEPARATOR.'extract_retry';
        $res = ZipArchiveManager::extractAndValidate($retryResult['destination_path'], $extractRetryDir);
        $manifest = $res['manifest'];
        self::assertTrue($manifest->isRetryData());

        $sitePart = $extractRetryDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $manifest->datasets['sites']->parts[0]->file);
        $record = json_decode(file($sitePart)[0], true);
        self::assertSame('retry-token-read-exact', $record['portable_meta']['seo_read_token']);
        self::assertSame('retry-token-mig-exact', $record['portable_meta']['seo_migration_token']);

        // Import retry package
        $this->wipeAllTables();
        $importRes = (new ClientTransferImporter)->import($retryResult['destination_path'], force: true);
        self::assertSame(1, $importRes['dataset_stats']['sites']['imported']);

        $targetSite = Site::query()->where('domain', 'retry-clone.test')->firstOrFail();
        self::assertSame('retry-token-read-exact', SiteMeta::query()->where('site_id', $targetSite->id)->where('meta_key', 'seo_read_token')->value('meta_value'));
        self::assertSame('retry-token-mig-exact', SiteMeta::query()->where('site_id', $targetSite->id)->where('meta_key', 'seo_migration_token')->value('meta_value'));
    }
}
