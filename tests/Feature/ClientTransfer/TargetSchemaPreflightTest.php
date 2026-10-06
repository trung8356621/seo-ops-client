<?php

declare(strict_types=1);

namespace Tests\Feature\ClientTransfer;

use App\Jobs\ClientTransfer\ImportDatasetSliceJob;
use App\Jobs\ClientTransfer\PrepareSeoImportJob;
use App\Models\ClientTransferRun;
use App\Models\Site;
use App\Models\User;
use App\Services\ClientTransfer\ClientTransferExporter;
use App\Services\ClientTransfer\ClientTransferImporter;
use App\Services\ClientTransfer\DatasetRegistry;
use App\Services\ClientTransfer\Exceptions\FatalImportException;
use App\Services\ClientTransfer\Exceptions\TargetNotEmptyException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Tests\Unit\ClientTransfer\TransferDatabaseTestCase;

final class TargetSchemaPreflightTest extends TransferDatabaseTestCase
{
    public function test_connected_database_with_zero_tables_is_not_schema_ready_and_import_is_blocked(): void
    {
        $package = $this->emptyPackage();
        $emptyDatabase = $this->tempDir.DIRECTORY_SEPARATOR.'empty-target.sqlite';
        touch($emptyDatabase);
        config()->set('database.connections.omi_seo_ai.database', $emptyDatabase);
        DB::purge('omi_seo_ai');

        $importer = new ClientTransferImporter;
        $inspection = $importer->inspect($package);

        self::assertTrue($inspection['connection_ready']);
        self::assertFalse($inspection['schema_ready']);
        self::assertFalse($inspection['target_empty']);
        self::assertContains('Missing table: keywords', $inspection['schema_errors']);
        self::assertContains('Missing table: articles', $inspection['schema_errors']);

        $this->expectException(FatalImportException::class);
        $this->expectExceptionMessage('Target SEO schema is not ready');
        $importer->assertTargetReadyForImport($inspection['manifest']);
    }

    public function test_fresh_current_canonical_schema_is_ready_and_importable(): void
    {
        $inspection = (new ClientTransferImporter)->inspect($this->emptyPackage());

        self::assertTrue($inspection['connection_ready']);
        self::assertTrue($inspection['schema_ready']);
        self::assertTrue($inspection['target_empty']);
        self::assertSame([], $inspection['schema_errors']);
        (new ClientTransferImporter)->assertTargetReadyForImport($inspection['manifest']);
    }

    public function test_missing_required_table_fails_preflight_clearly(): void
    {
        $package = $this->emptyPackage();
        Schema::connection('omi_seo_ai')->drop('articles');

        $inspection = (new ClientTransferImporter)->inspect($package);

        self::assertFalse($inspection['schema_ready']);
        self::assertFalse($inspection['target_empty']);
        self::assertContains('Missing table: articles', $inspection['schema_errors']);
    }

    public function test_missing_required_column_fails_preflight_clearly(): void
    {
        $package = $this->emptyPackage();
        Schema::connection('omi_seo_ai')->table('articles', function (Blueprint $table): void {
            $table->dropColumn('user_id');
        });

        $inspection = (new ClientTransferImporter)->inspect($package);

        self::assertFalse($inspection['schema_ready']);
        self::assertFalse($inspection['target_empty']);
        self::assertContains('Missing column: articles.user_id', $inspection['schema_errors']);
    }

    public function test_compatible_schema_with_business_data_is_not_empty_and_import_is_blocked(): void
    {
        $package = $this->emptyPackage();
        $user = User::query()->create(['name' => 'Existing', 'email' => 'existing@target.test']);
        $site = Site::query()->create(['domain' => 'existing.test', 'user_id' => $user->id, 'status' => 'active']);
        $article = new SeoArticle;
        $article->site_id = (int) $site->id;
        $article->title = 'Existing target article';
        $article->saveQuietly();

        $importer = new ClientTransferImporter;
        $inspection = $importer->inspect($package);

        self::assertTrue($inspection['schema_ready']);
        self::assertFalse($inspection['target_empty']);
        self::assertSame(1, $inspection['non_empty_tables']['articles']);

        $this->expectException(TargetNotEmptyException::class);
        $importer->assertTargetReadyForImport($inspection['manifest']);
    }

    public function test_prepare_job_marks_run_failed_and_never_dispatches_dataset_slice_for_invalid_schema(): void
    {
        Queue::fake();
        $package = $this->emptyPackage();
        Schema::connection('omi_seo_ai')->table('seo_media_meta', function (Blueprint $table): void {
            $table->dropColumn('meta_value');
        });
        $run = ClientTransferRun::query()->create([
            'run_id' => 'invalid-schema-run',
            'type' => 'import',
            'status' => 'pending',
            'phase' => 'queued',
        ]);

        try {
            (new PrepareSeoImportJob($run->run_id, $package))->handle(new DatasetRegistry);
            self::fail('Schema preflight should stop the prepare job.');
        } catch (FatalImportException $e) {
            self::assertStringContainsString('Missing column: seo_media_meta.meta_value', $e->getMessage());
        }

        self::assertSame('failed', $run->refresh()->status);
        self::assertStringContainsString('Missing column: seo_media_meta.meta_value', (string) $run->error_message);
        Queue::assertNotPushed(ImportDatasetSliceJob::class);
    }

    private function emptyPackage(): string
    {
        $path = $this->tempDir.DIRECTORY_SEPARATOR.'empty-package-'.bin2hex(random_bytes(4)).'.zip';
        (new ClientTransferExporter)->export($path);

        return $path;
    }
}
