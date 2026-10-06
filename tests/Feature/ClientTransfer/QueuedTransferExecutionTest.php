<?php

declare(strict_types=1);

namespace Tests\Feature\ClientTransfer;

use App\Filament\Pages\SeoExport;
use App\Filament\Pages\SeoImport;
use App\Jobs\ClientTransfer\BuildRetryPackageJob;
use App\Jobs\ClientTransfer\ExportDatasetSliceJob;
use App\Jobs\ClientTransfer\FinalizeSeoExportJob;
use App\Jobs\ClientTransfer\FinalizeSeoImportJob;
use App\Jobs\ClientTransfer\ImportDatasetSliceJob;
use App\Jobs\ClientTransfer\ImportMediaOrphansSliceJob;
use App\Jobs\ClientTransfer\PrepareSeoExportJob;
use App\Jobs\ClientTransfer\PrepareSeoImportJob;
use App\Jobs\ClientTransfer\ResolveDeferredSliceJob;
use App\Jobs\ClientTransfer\RollbackSeoImportJob;
use App\Jobs\ClientTransfer\ValidateSeoImportJob;
use App\Models\ClientTransferRun;
use App\Models\Site;
use App\Models\User;
use App\Services\ClientTransfer\ClientTransferExporter;
use App\Services\ClientTransfer\DatasetRegistry;
use App\Services\ClientTransfer\Datasets\ArticleMetaDataset;
use App\Services\ClientTransfer\Exceptions\FatalImportException;
use App\Services\ClientTransfer\Exceptions\TargetNotEmptyException;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ReferenceMap;
use App\Services\ClientTransfer\Support\ZipArchiveManager;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use ReflectionClass;
use ReflectionProperty;
use Tests\Unit\ClientTransfer\TransferDatabaseTestCase;
use ZipArchive;

final class QueuedTransferExecutionTest extends TransferDatabaseTestCase
{
    public function test_seo_import_page_recovers_active_queued_run_after_refresh(): void
    {
        Queue::fake();
        $packagePath = $this->tempDir.DIRECTORY_SEPARATOR.'queued-import.zip';
        file_put_contents($packagePath, 'test package');

        $page = new SeoImport;
        $page->uploadedFilePath = $packagePath;
        $page->connectionReady = true;
        $page->schemaReady = true;
        $page->targetEmpty = true;
        $page->runImport();

        $run = ClientTransferRun::query()->where('type', 'import')->where('status', 'pending')->firstOrFail();
        Queue::assertPushed(PrepareSeoImportJob::class, fn (PrepareSeoImportJob $job): bool => $job->runId === $run->run_id);

        $freshPage = new SeoImport;
        self::assertNull($freshPage->runId);
        self::assertSame($run->id, $freshPage->getRunProperty()?->id);

        $restore = new \ReflectionMethod($freshPage, 'restoreActiveImportRun');
        $restore->invoke($freshPage);
        self::assertSame($run->run_id, $freshPage->runId);
    }

    public function test_seo_import_page_recovers_latest_terminal_run_after_refresh(): void
    {
        foreach (['completed', 'failed', 'rolled_back', 'rollback_failed'] as $status) {
            $run = ClientTransferRun::query()->create([
                'run_id' => $status.'-'.\Illuminate\Support\Str::random(8),
                'type' => 'import',
                'status' => $status,
                'phase' => $status,
            ]);

            $freshPage = new SeoImport;
            self::assertSame($run->id, $freshPage->getRunProperty()?->id);
            self::assertSame($status, $freshPage->getRunProperty()?->status);
        }
    }

    public function test_seo_import_page_recovers_rolling_back_run_and_blocks_duplicate_import(): void
    {
        Queue::fake();
        $run = ClientTransferRun::query()->create([
            'run_id' => 'rolling-back-'.\Illuminate\Support\Str::random(8),
            'type' => 'import',
            'status' => 'rolling_back',
            'phase' => 'rollback',
        ]);

        $freshPage = new SeoImport;
        self::assertSame($run->id, $freshPage->getRunProperty()?->id);

        $freshPage->runImport();

        self::assertSame(1, ClientTransferRun::query()->where('type', 'import')->count());
        Queue::assertNotPushed(PrepareSeoImportJob::class);
    }

    public function test_import_upload_resolves_through_local_disk_and_queues_persistent_absolute_path(): void
    {
        $relativePath = 'client-transfer/uploads/test.zip';
        $sourceZip = $this->tempDir.DIRECTORY_SEPARATOR.'test.zip';
        (new ClientTransferExporter)->export($sourceZip);

        self::assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));

        $disk = Storage::disk('local');
        $disk->put($relativePath, fopen($sourceZip, 'rb'));
        $expectedPath = $disk->path($relativePath);

        try {
            self::assertFileExists($expectedPath);

            $resolver = new \ReflectionMethod(SeoImport::class, 'resolveUploadedPackagePath');
            $resolvedPath = $resolver->invoke(new SeoImport, $relativePath);

            self::assertSame($expectedPath, $resolvedPath);
            $inspection = (new \App\Services\ClientTransfer\ClientTransferImporter)->inspect($resolvedPath);
            self::assertTrue($inspection['target_empty']);

            Queue::fake();
            PrepareSeoImportJob::dispatch('upload-path-run', $resolvedPath);

            Queue::assertPushed(PrepareSeoImportJob::class, function (PrepareSeoImportJob $job) use ($expectedPath): bool {
                return $job->uploadedZipPath === $expectedPath && file_exists($job->uploadedZipPath);
            });
        } finally {
            $disk->delete($relativePath);
        }
    }

    public function test_livewire_pages_do_not_expose_complex_objects_in_public_properties(): void
    {
        $allowedScalarTypes = ['string', 'int', 'bool', 'array', 'null', 'float'];

        foreach ([SeoExport::class, SeoImport::class] as $pageClass) {
            $reflector = new ReflectionClass($pageClass);
            $properties = $reflector->getProperties(ReflectionProperty::IS_PUBLIC);

            foreach ($properties as $prop) {
                if ($prop->isStatic()) {
                    continue;
                }

                $type = $prop->getType();
                if ($type === null) {
                    continue;
                }

                $typeNames = [];
                if ($type instanceof \ReflectionUnionType) {
                    foreach ($type->getTypes() as $t) {
                        $typeNames[] = $t->getName();
                    }
                } elseif ($type instanceof \ReflectionNamedType) {
                    $typeNames[] = $type->getName();
                }

                foreach ($typeNames as $tn) {
                    self::assertContains(
                        $tn,
                        $allowedScalarTypes,
                        "Public property [{$prop->getName()}] on [{$pageClass}] must only be scalar or array of scalars, got [{$tn}]."
                    );
                }
            }
        }
    }

    public function test_all_client_transfer_jobs_are_queued_on_client_transfer_queue(): void
    {
        $jobs = [
            new PrepareSeoExportJob('test-run'),
            new ExportDatasetSliceJob('test-run', 'articles', 0),
            new FinalizeSeoExportJob('test-run'),
            new PrepareSeoImportJob('test-run', 'test.zip'),
            new ImportDatasetSliceJob('test-run', 'articles', 0),
            new ImportMediaOrphansSliceJob('test-run', 0),
            new ResolveDeferredSliceJob('test-run'),
            new ValidateSeoImportJob('test-run'),
            new BuildRetryPackageJob('test-run'),
            new FinalizeSeoImportJob('test-run'),
            new RollbackSeoImportJob('test-run'),
        ];

        foreach ($jobs as $job) {
            self::assertSame(
                'client-transfer',
                $job->queue,
                'Job ['.get_class($job).'] must be explicitly bound to [client-transfer] queue.'
            );
        }
    }

    public function test_import_run_init_dataset_does_not_reset_export_count_on_subsequent_calls(): void
    {
        $run = new ImportRun('counter-test');
        $run->initDataset('articles', 100);

        self::assertSame(100, $run->getDatasetStats()['articles']['export_count']);

        // Recording an imported record calls initDataset('articles') with default exportCount = 0
        $run->recordImported('articles', 'article:1');

        self::assertSame(
            100,
            $run->getDatasetStats()['articles']['export_count'],
            'Recording a record must never overwrite or reset export_count to 0.'
        );
        self::assertSame(1, $run->getDatasetStats()['articles']['imported']);
    }

    public function test_rollback_failure_stops_and_marks_run_without_creating_a_new_journal(): void
    {
        $runId = 'rollback-failure-'.\Illuminate\Support\Str::random(8);
        $run = ClientTransferRun::query()->create([
            'run_id' => $runId,
            'type' => 'import',
            'status' => 'completed',
            'phase' => 'finished',
        ]);

        try {
            (new RollbackSeoImportJob($runId))->handle(new DatasetRegistry);
            self::fail('Rollback must fail when its import journal is unavailable.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Import journal is missing', $e->getMessage());
        }

        $run->refresh();
        self::assertSame('rollback_failed', $run->status);
        self::assertSame('rollback_failed', $run->phase);
        self::assertFileDoesNotExist(storage_path("app/client-transfer/refmap_{$runId}.sqlite"));
    }

    public function test_rollback_processes_more_than_one_thousand_created_records_in_bounded_chunks(): void
    {
        $runId = 'rollback-chunks-'.\Illuminate\Support\Str::random(8);
        $run = ClientTransferRun::query()->create([
            'run_id' => $runId,
            'type' => 'import',
            'status' => 'completed',
            'phase' => 'finished',
        ]);
        $refMap = new ReferenceMap($runId);

        $user = User::query()->create(['name' => 'Chunk User', 'email' => 'chunks@test.test']);
        $site = Site::query()->create(['domain' => 'chunks.test', 'user_id' => $user->id, 'status' => 'active']);
        $refMap->trackCreated('users', (int) $user->id);
        $refMap->trackCreated('sites', (int) $site->id);

        $rows = [];
        for ($i = 1; $i <= 1201; $i++) {
            $rows[] = [
                'site_id' => (int) $site->id,
                'title' => "Chunked rollback article {$i}",
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            SeoArticle::query()->insert($chunk);
        }
        foreach (SeoArticle::query()->orderBy('id')->pluck('id') as $id) {
            $refMap->trackCreated('articles', (int) $id);
        }

        $firstChunk = $refMap->getCreatedRecordsChunk('articles', limit: 500);
        $secondChunk = $refMap->getCreatedRecordsChunk('articles', beforeId: $firstChunk[499]['id'], limit: 500);
        $thirdChunk = $refMap->getCreatedRecordsChunk('articles', beforeId: $secondChunk[499]['id'], limit: 500);
        self::assertCount(500, $firstChunk);
        self::assertCount(500, $secondChunk);
        self::assertCount(201, $thirdChunk);
        self::assertGreaterThan($firstChunk[499]['id'], $firstChunk[0]['id'], 'Each chunk must be newest-first.');
        self::assertGreaterThan($secondChunk[0]['id'], $firstChunk[499]['id'], 'Later pages must continue toward older journal rows.');
        unset($refMap);

        (new RollbackSeoImportJob($runId))->handle(new DatasetRegistry);

        self::assertTrue($run->refresh()->isRolledBack());
        self::assertSame(0, SeoArticle::withTrashed()->count());
        self::assertSame(0, Site::withTrashed()->count());
        self::assertSame(0, User::withTrashed()->count());
        self::assertFileDoesNotExist(storage_path("app/client-transfer/refmap_{$runId}.sqlite"));
    }

    public function test_rollback_deletes_non_soft_delete_dataset_records_without_with_trashed_scope(): void
    {
        $user = User::query()->create(['name' => 'Meta User', 'email' => 'meta-rollback@test.test']);
        $site = Site::query()->create(['domain' => 'meta-rollback.test', 'user_id' => $user->id, 'status' => 'active']);
        $article = new SeoArticle;
        $article->site_id = (int) $site->id;
        $article->title = 'Article meta rollback';
        $article->saveQuietly();
        $meta = ArticleMeta::query()->create([
            'article_id' => (int) $article->id,
            'meta_key' => 'rollback_test',
            'meta_value' => 'value',
        ]);

        (new ArticleMetaDataset)->rollbackImportedRecord((string) $meta->id);

        self::assertSame(0, ArticleMeta::query()->count());
    }

    public function test_hardened_zip_extraction_rejects_directory_traversal(): void
    {
        $badZipPath = $this->tempDir.DIRECTORY_SEPARATOR.'traversal.zip';
        $zip = new ZipArchive;
        $zip->open($badZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('../evil.txt', 'malicious content');
        $zip->close();

        $destDir = $this->tempDir.DIRECTORY_SEPARATOR.'traversal_extract';

        $this->expectException(FatalImportException::class);
        $this->expectExceptionMessage('attempts illegal directory traversal');
        ZipArchiveManager::extractAndValidate($badZipPath, $destDir);
    }

    public function test_hardened_zip_extraction_rejects_absolute_path(): void
    {
        $badZipPath = $this->tempDir.DIRECTORY_SEPARATOR.'absolute.zip';
        $zip = new ZipArchive;
        $zip->open($badZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('/etc/passwd', 'malicious content');
        $zip->close();

        $destDir = $this->tempDir.DIRECTORY_SEPARATOR.'absolute_extract';

        $this->expectException(FatalImportException::class);
        $this->expectExceptionMessage('contains an illegal absolute path');
        ZipArchiveManager::extractAndValidate($badZipPath, $destDir);
    }

    public function test_strict_empty_target_check_refuses_when_dirty_and_not_forced(): void
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@dirty.test']);
        $site = Site::query()->create(['domain' => 'dirty.test', 'user_id' => $user->id, 'status' => 'active']);

        $article = new SeoArticle;
        $article->site_id = (int) $site->id;
        $article->title = 'Existing Dirty Target Record';
        $article->saveQuietly();

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'test_target.zip';
        $exporter = new ClientTransferExporter;
        $exporter->export($zipPath);

        $run = ClientTransferRun::query()->create([
            'run_id' => 'dirty-run',
            'type' => 'import',
            'status' => 'pending',
            'phase' => 'queued',
        ]);

        $job = new PrepareSeoImportJob('dirty-run', $zipPath);

        try {
            $job->handle(new DatasetRegistry);
            self::fail('Expected TargetNotEmptyException was not thrown.');
        } catch (TargetNotEmptyException $e) {
            self::assertStringContainsString('Target SEO database contains existing records', $e->getMessage());
        }
    }

    public function test_full_export_and_import_pipeline_with_persisted_run_state(): void
    {
        // 1. Create sample source records
        \Illuminate\Support\Facades\DB::table('services')->insert([
            'name' => 'SEO',
            'slug' => 'seo',
            'db_connection' => 'omi_seo_ai',
            'is_active' => true,
            'config' => json_encode(['portable' => true], JSON_THROW_ON_ERROR),
            'service_key' => 'keep-this-service-key',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::query()->create(['name' => 'Queued Author', 'email' => 'author@queued.test']);
        $site = Site::query()->create(['domain' => 'queued.test', 'user_id' => $user->id, 'status' => 'active']);

        for ($i = 1; $i <= 5; $i++) {
            $article = new SeoArticle;
            $article->site_id = (int) $site->id;
            $article->user_id = (int) $user->id;
            $article->title = "Queued Article {$i}";
            $article->slug = "queued-article-{$i}";
            $article->body = "<p>Queued article body {$i}</p>";
            $article->saveQuietly();
        }

        // 2. Start Queued Export via continuation pipeline
        $exportRunId = 'exp-'.\Illuminate\Support\Str::random(8);
        $exportRun = ClientTransferRun::query()->create([
            'run_id' => $exportRunId,
            'type' => 'export',
            'status' => 'pending',
            'phase' => 'queued',
        ]);

        // Dispatches on client-transfer; runs continuation chain to completion
        PrepareSeoExportJob::dispatchSync($exportRunId);

        $exportRun->refresh();
        self::assertTrue($exportRun->isCompleted(), 'Export run must be completed by continuation jobs.');
        self::assertNotNull($exportRun->artifact_path);
        self::assertFileExists($exportRun->artifact_path);
        self::assertGreaterThanOrEqual(5, $exportRun->processed_records);

        // 3. Wipe target tables for clean import
        $this->wipeBusinessTables();
        self::assertSame(0, SeoArticle::query()->count());

        $systemUser = User::query()->create([
            'name' => 'System User',
            'email' => 'system@target.test',
            'is_system' => true,
        ]);

        // 4. Start Queued Import via continuation pipeline
        $importRunId = 'imp-'.\Illuminate\Support\Str::random(8);
        $importRun = ClientTransferRun::query()->create([
            'run_id' => $importRunId,
            'type' => 'import',
            'status' => 'pending',
            'phase' => 'queued',
        ]);

        PrepareSeoImportJob::dispatchSync($importRunId, $exportRun->artifact_path);

        $importRun->refresh();
        self::assertTrue($importRun->isCompleted(), 'Import run must be marked completed by continuation jobs.');
        self::assertSame(5, SeoArticle::query()->count(), 'Target database must contain 5 imported articles.');
        self::assertGreaterThanOrEqual(5, $importRun->imported_count);

        // 5. Roll back only records journaled as created by this run.
        $retryPath = $this->tempDir.DIRECTORY_SEPARATOR.'retry.zip';
        file_put_contents($retryPath, 'retry');
        $stagingPath = storage_path("app/client-transfer/staging_import_{$importRunId}");
        mkdir($stagingPath, 0755, true);
        file_put_contents($stagingPath.DIRECTORY_SEPARATOR.'temp.txt', 'temporary');
        $importRun->update(['retry_package_path' => $retryPath]);

        (new RollbackSeoImportJob($importRunId))->handle(new DatasetRegistry);

        $importRun->refresh();
        self::assertTrue($importRun->isRolledBack());
        self::assertSame(0, SeoArticle::withTrashed()->count());
        self::assertTrue(User::query()->whereKey($systemUser->id)->exists(), 'Unrelated pre-existing rows must remain.');
        self::assertTrue(User::query()->where('email', 'system@target.test')->where('is_system', true)->exists());
        self::assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('services')->count(), 'Service config rows must remain.');
        self::assertSame('keep-this-service-key', \Illuminate\Support\Facades\DB::table('services')->value('service_key'));
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasTable('client_transfer_runs'), 'Migration-managed tables must remain.');
        self::assertFileDoesNotExist(storage_path("app/client-transfer/refmap_{$importRunId}.sqlite"));
        self::assertFileDoesNotExist($retryPath);
        self::assertDirectoryDoesNotExist($stagingPath);
        $rolledBackInspection = (new \App\Services\ClientTransfer\ClientTransferImporter)->inspect($exportRun->artifact_path);
        self::assertTrue($rolledBackInspection['schema_ready']);
        self::assertTrue($rolledBackInspection['target_empty']);

        // 6. The rolled-back target accepts the same package again.
        $secondImportRunId = 'imp-'.\Illuminate\Support\Str::random(8);
        $secondImportRun = ClientTransferRun::query()->create([
            'run_id' => $secondImportRunId,
            'type' => 'import',
            'status' => 'pending',
            'phase' => 'queued',
        ]);
        PrepareSeoImportJob::dispatchSync($secondImportRunId, $exportRun->artifact_path);

        self::assertTrue($secondImportRun->refresh()->isCompleted());
        self::assertSame(5, SeoArticle::query()->count());
        self::assertTrue(User::query()->whereKey($systemUser->id)->exists());
    }

    public function test_bounded_export_slices_for_non_article_dataset_with_more_than_slice_limit(): void
    {
        $user = User::query()->create(['name' => 'Slice Author', 'email' => 'slice@author.test']);
        $site = Site::query()->create(['domain' => 'slice.test', 'user_id' => $user->id, 'status' => 'active']);
        $article = new SeoArticle;
        $article->site_id = (int) $site->id;
        $article->title = 'Article for Meta Slicing';
        $article->saveQuietly();

        // Seed 1,200 ArticleMeta rows (> 500 slice limit)
        $metaRows = [];
        for ($i = 1; $i <= 1200; $i++) {
            $metaRows[] = [
                'article_id' => (int) $article->id,
                'meta_key' => "key_{$i}",
                'meta_value' => "value_{$i}",
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        ArticleMeta::query()->insert($metaRows);
        self::assertSame(1200, ArticleMeta::query()->count());

        $dataset = new ArticleMetaDataset;
        self::assertSame(500, $dataset->sliceLimit());

        $stagingDir = $this->tempDir.DIRECTORY_SEPARATOR.'meta_slice_test';
        mkdir($stagingDir.'/content/article_meta', 0755, true);
        mkdir($stagingDir.'/content/blobs', 0755, true);

        $blobs = new BlobManager($stagingDir.'/content/blobs');
        $writer = new NdjsonPartWriter(
            directory: $stagingDir.'/content/article_meta',
            relativeSubdir: 'content/article_meta',
            maxRecords: 20000,
            maxBytes: 16777216,
        );

        // Slice 1: records 1..500
        $slice1 = $dataset->exportSlice($writer, $blobs, afterId: 0, limit: 500);
        self::assertSame(500, $slice1['count']);
        self::assertTrue($slice1['has_more'], 'Slice 1 must have more records remaining.');
        self::assertGreaterThan(0, $slice1['last_id']);

        // Slice 2: records 501..1000
        $slice2 = $dataset->exportSlice($writer, $blobs, afterId: $slice1['last_id'], limit: 500);
        self::assertSame(500, $slice2['count']);
        self::assertTrue($slice2['has_more'], 'Slice 2 must have more records remaining.');
        self::assertGreaterThan($slice1['last_id'], $slice2['last_id']);

        // Slice 3: records 1001..1200
        $slice3 = $dataset->exportSlice($writer, $blobs, afterId: $slice2['last_id'], limit: 500);
        self::assertSame(200, $slice3['count']);
        self::assertFalse($slice3['has_more'], 'Slice 3 must have reached the end of records.');
        self::assertGreaterThan($slice2['last_id'], $slice3['last_id']);
    }

    public function test_deferred_resolution_for_project_source_draft_and_task_archived_and_keyword_article(): void
    {
        $user = User::query()->create(['name' => 'Deferred Tester', 'email' => 'deferred@test.test']);
        $site = Site::query()->create(['domain' => 'deferred.test', 'user_id' => $user->id, 'status' => 'active']);

        // 1. Projects
        $sourceDraftProject = new SeoProject;
        $sourceDraftProject->site_id = (int) $site->id;
        $sourceDraftProject->name = 'Source Draft Project';
        $sourceDraftProject->status = SeoProject::STATUS_DRAFT;
        $sourceDraftProject->kind = SeoProject::KIND_MONTHLY;
        $sourceDraftProject->total_tasks = 0;
        $sourceDraftProject->save();

        $executionProject = new SeoProject;
        $executionProject->site_id = (int) $site->id;
        $executionProject->name = 'Execution Project';
        $executionProject->status = SeoProject::STATUS_PENDING;
        $executionProject->kind = SeoProject::KIND_MONTHLY;
        $executionProject->total_tasks = 0;
        $executionProject->save();

        // 2. Project Task
        $task = new SeoProjectTask;
        $task->project_id = (int) $executionProject->id;
        $task->site_id = (int) $site->id;
        $task->keyword = 'test keyword phrase';
        $task->title = 'Task Title';
        $task->source_content = 'test keyword phrase';
        $task->save();

        // 3. Keyword & Article
        $keyword = new Keyword;
        $keyword->phrase = 'deferred keyword test';
        $keyword->type = Keyword::TYPE_NORMAL;
        $keyword->source = 'test';
        $keyword->save();

        $article = new SeoArticle;
        $article->site_id = (int) $site->id;
        $article->title = 'Deferred Target Article';
        $article->saveQuietly();

        // Setup run & refMap
        $runId = 'test-deferred-'.\Illuminate\Support\Str::random(8);
        $run = ClientTransferRun::query()->create([
            'run_id' => $runId,
            'type' => 'import',
            'status' => 'running',
            'phase' => 'import:completed',
        ]);

        $refMap = new ReferenceMap($runId);

        // Register source references
        $refMap->set('project:source-draft-1', 'seo_projects', (int) $sourceDraftProject->id);
        $refMap->set('project:archived-origin-1', 'seo_projects', (int) $sourceDraftProject->id);
        $refMap->set('article:article-meta-target-1', 'articles', (int) $article->id);

        // Register deferred references
        $refMap->addDeferred('seo_projects', (int) $executionProject->id, 'source_draft_project_id', 'project:source-draft-1');
        $refMap->addDeferred('seo_project_tasks', (int) $task->id, 'archived_from_project_id', 'project:archived-origin-1');
        $refMap->addDeferred('keyword_meta_global_article', (int) $keyword->id, KeywordMetaKey::MainArticleId->value, 'article:article-meta-target-1');

        // Execute deferred resolution continuation job
        ResolveDeferredSliceJob::dispatchSync($runId, 0);

        // Verify project.source_draft_project_id was resolved
        $executionProject->refresh();
        self::assertSame((int) $sourceDraftProject->id, (int) $executionProject->source_draft_project_id);

        // Verify task.archived_from_project_id was resolved
        $task->refresh();
        self::assertSame((int) $sourceDraftProject->id, (int) $task->archived_from_project_id);

        // Verify keyword meta repository resolved article id
        $repo = app(KeywordMetaRepository::class);
        $resolvedArticleId = $repo->get((int) $keyword->id, KeywordMetaKey::MainArticleId->value);
        self::assertSame((string) $article->id, $resolvedArticleId);
    }

    public function test_pending_import_can_be_cancelled_with_worker_off(): void
    {
        $run = ClientTransferRun::query()->create([
            'run_id' => 'cancel-pending-'.\Illuminate\Support\Str::random(8),
            'type' => 'import',
            'status' => 'pending',
            'phase' => 'queued',
        ]);

        $page = new SeoImport;
        $page->runId = $run->run_id;
        $page->stopImport();

        $run->refresh();
        self::assertSame('cancelled', $run->status);
        self::assertSame('cancelled', $run->phase);
        self::assertNotNull($run->finished_at);
        self::assertTrue($run->isCancelled());
    }

    public function test_running_import_can_be_cancelled(): void
    {
        $run = ClientTransferRun::query()->create([
            'run_id' => 'cancel-running-'.\Illuminate\Support\Str::random(8),
            'type' => 'import',
            'status' => 'running',
            'phase' => 'import:articles',
        ]);

        $page = new SeoImport;
        $page->runId = $run->run_id;
        $page->stopImport();

        $run->refresh();
        self::assertSame('cancelled', $run->status);
        self::assertSame('cancelled', $run->phase);
        self::assertNotNull($run->finished_at);
        self::assertTrue($run->isCancelled());
    }

    public function test_cancelled_run_is_no_longer_returned_by_active_import_run(): void
    {
        $run = ClientTransferRun::query()->create([
            'run_id' => 'cancel-active-'.\Illuminate\Support\Str::random(8),
            'type' => 'import',
            'status' => 'cancelled',
            'phase' => 'cancelled',
            'finished_at' => now(),
        ]);

        $page = new SeoImport;
        self::assertNull($page->activeImportRun());
        self::assertSame($run->id, $page->latestImportRun()?->id);
    }

    public function test_new_import_may_start_after_cancellation(): void
    {
        Queue::fake();

        ClientTransferRun::query()->create([
            'run_id' => 'stuck-cancelled-'.\Illuminate\Support\Str::random(8),
            'type' => 'import',
            'status' => 'cancelled',
            'phase' => 'cancelled',
            'finished_at' => now(),
        ]);

        $packagePath = $this->tempDir.DIRECTORY_SEPARATOR.'new-import.zip';
        file_put_contents($packagePath, 'dummy zip content');

        $page = new SeoImport;
        $page->uploadedFilePath = $packagePath;
        $page->connectionReady = true;
        $page->schemaReady = true;
        $page->targetEmpty = true;
        $page->runImport();

        self::assertNotNull($page->runId);
        $newRun = ClientTransferRun::query()->where('run_id', $page->runId)->firstOrFail();
        self::assertSame('pending', $newRun->status);
        Queue::assertPushed(PrepareSeoImportJob::class, fn (PrepareSeoImportJob $job) => $job->runId === $newRun->run_id);
    }

    public function test_queued_prepare_seo_import_job_becomes_noop_for_cancelled_run(): void
    {
        Queue::fake();

        $run = ClientTransferRun::query()->create([
            'run_id' => 'cancelled-prep-'.\Illuminate\Support\Str::random(8),
            'type' => 'import',
            'status' => 'cancelled',
            'phase' => 'cancelled',
            'finished_at' => now(),
        ]);

        (new PrepareSeoImportJob($run->run_id, 'dummy-path.zip'))->handle(new DatasetRegistry);

        Queue::assertNothingPushed();
        $run->refresh();
        self::assertSame('cancelled', $run->status);
    }

    public function test_queued_import_dataset_slice_job_becomes_noop_for_cancelled_run(): void
    {
        Queue::fake();

        $run = ClientTransferRun::query()->create([
            'run_id' => 'cancelled-slice-'.\Illuminate\Support\Str::random(8),
            'type' => 'import',
            'status' => 'cancelled',
            'phase' => 'cancelled',
            'finished_at' => now(),
        ]);

        (new ImportDatasetSliceJob($run->run_id, 'articles', 0))->handle(new DatasetRegistry);

        Queue::assertNothingPushed();
        $run->refresh();
        self::assertSame('cancelled', $run->status);
    }

    public function test_if_cancellation_happens_during_a_slice_job_does_not_dispatch_next_slice(): void
    {
        Queue::fake();

        // 1. Export package with users
        $user = User::query()->create(['name' => 'Slice User', 'email' => 'sliceuser@test.test']);
        $sourceZip = $this->tempDir.DIRECTORY_SEPARATOR.'slice_export.zip';
        (new ClientTransferExporter)->export($sourceZip);

        $runId = 'slice-cancel-'.\Illuminate\Support\Str::random(8);
        $run = ClientTransferRun::query()->create([
            'run_id' => $runId,
            'type' => 'import',
            'status' => 'running',
            'phase' => 'import:users',
        ]);

        // Staging extraction
        $stagingDir = storage_path("app/client-transfer/staging_import_{$runId}");
        ZipArchiveManager::extractAndValidate($sourceZip, $stagingDir);

        // Cancel the run in DB before slice finishes
        $run->cancel();

        (new ImportDatasetSliceJob(
            runId: $runId,
            datasetKey: 'users',
            datasetQueueIndex: 0,
            partIndex: 0,
            byteOffset: 0,
            recordIndex: 0,
        ))->handle(new DatasetRegistry);

        Queue::assertNothingPushed();
        $run->refresh();
        self::assertSame('cancelled', $run->status);
    }

    public function test_resolve_deferred_validate_retry_package_and_finalize_jobs_do_not_continue_cancelled_run(): void
    {
        Queue::fake();

        $run = ClientTransferRun::query()->create([
            'run_id' => 'cancelled-cont-'.\Illuminate\Support\Str::random(8),
            'type' => 'import',
            'status' => 'cancelled',
            'phase' => 'cancelled',
            'finished_at' => now(),
        ]);

        // ResolveDeferredSliceJob
        (new ResolveDeferredSliceJob($run->run_id))->handle();
        Queue::assertNothingPushed();

        // ValidateSeoImportJob
        (new ValidateSeoImportJob($run->run_id))->handle();
        Queue::assertNothingPushed();

        // BuildRetryPackageJob
        (new BuildRetryPackageJob($run->run_id))->handle(new DatasetRegistry);
        Queue::assertNothingPushed();

        // FinalizeSeoImportJob
        (new FinalizeSeoImportJob($run->run_id))->handle();
        Queue::assertNothingPushed();

        (new ImportMediaOrphansSliceJob($run->run_id, 0))->handle();
        Queue::assertNothingPushed();

        $run->refresh();
        self::assertSame('cancelled', $run->status);
    }

    public function test_cancelled_run_can_still_be_rolled_back(): void
    {
        $run = ClientTransferRun::query()->create([
            'run_id' => 'cancelled-can-rollback-'.\Illuminate\Support\Str::random(8),
            'type' => 'import',
            'status' => 'cancelled',
            'phase' => 'cancelled',
            'finished_at' => now(),
        ]);

        self::assertTrue($run->canRollback());
    }

    public function test_rollback_pipeline_still_works_from_cancelled_state(): void
    {
        $user = User::query()->create(['name' => 'Rollback User', 'email' => 'rbuser@test.test']);
        $site = Site::query()->create(['domain' => 'rollback.test', 'user_id' => $user->id, 'status' => 'active']);

        $article = new SeoArticle;
        $article->site_id = (int) $site->id;
        $article->title = 'Article to Rollback';
        $article->saveQuietly();

        $exportZip = $this->tempDir.DIRECTORY_SEPARATOR.'rb_export.zip';
        (new ClientTransferExporter)->export($exportZip);

        $this->wipeBusinessTables();
        self::assertSame(0, SeoArticle::query()->count());

        $runId = 'rb-cancel-'.\Illuminate\Support\Str::random(8);
        $run = ClientTransferRun::query()->create([
            'run_id' => $runId,
            'type' => 'import',
            'status' => 'running',
            'phase' => 'import:articles',
        ]);

        $stagingDir = storage_path("app/client-transfer/staging_import_{$runId}");
        ZipArchiveManager::extractAndValidate($exportZip, $stagingDir);

        $refMap = new ReferenceMap($runId);
        $refMap->set('site:1', 'sites', (int) $site->id);
        $importRun = new ImportRun($runId, $refMap);
        $blobs = new BlobManager($stagingDir.'/content/blobs');
        $registry = new DatasetRegistry;

        // Import article
        $articleDataset = $registry->get('articles');
        $articleDataset->importRecord(
            record: ['ref' => 'article:1', 'site_ref' => 'site:1', 'site_id' => (int) $site->id, 'title' => 'Imported Article', 'slug' => 'imp-art'],
            refMap: $refMap,
            run: $importRun,
            blobs: $blobs,
            partFile: 'articles.ndjson',
            recordIndex: 0,
        );

        self::assertSame(1, SeoArticle::query()->count());

        // Cancel the run
        $run->cancel();
        self::assertSame('cancelled', $run->status);
        self::assertTrue($run->canRollback());

        // Rollback from cancelled state
        (new RollbackSeoImportJob($runId))->handle($registry);

        $run->refresh();
        self::assertTrue($run->isRolledBack());
        self::assertSame(0, SeoArticle::query()->count());
    }

    public function test_cancelling_one_run_does_not_affect_another_run_or_other_queues(): void
    {
        $run1 = ClientTransferRun::query()->create([
            'run_id' => 'run-1-'.\Illuminate\Support\Str::random(8),
            'type' => 'import',
            'status' => 'running',
            'phase' => 'import:articles',
        ]);

        $run2 = ClientTransferRun::query()->create([
            'run_id' => 'run-2-'.\Illuminate\Support\Str::random(8),
            'type' => 'import',
            'status' => 'running',
            'phase' => 'import:articles',
        ]);

        $page = new SeoImport;
        $page->runId = $run1->run_id;
        $page->stopImport();

        $run1->refresh();
        $run2->refresh();

        self::assertSame('cancelled', $run1->status);
        self::assertSame('running', $run2->status);
    }

    public function test_completed_failed_and_rolled_back_runs_cannot_be_cancelled(): void
    {
        foreach (['completed', 'failed', 'rolled_back', 'rollback_failed', 'cancelled'] as $status) {
            $run = ClientTransferRun::query()->create([
                'run_id' => "can-cancel-{$status}-".\Illuminate\Support\Str::random(8),
                'type' => 'import',
                'status' => $status,
                'phase' => $status,
            ]);

            self::assertFalse($run->canCancel(), "Run in status {$status} must not be cancellable.");
        }

        // Rolling back runs cannot be cancelled
        $rollingBackRun = ClientTransferRun::query()->create([
            'run_id' => 'rolling-back-cancel-'.\Illuminate\Support\Str::random(8),
            'type' => 'import',
            'status' => 'rolling_back',
            'phase' => 'rollback',
        ]);
        self::assertFalse($rollingBackRun->canCancel(), 'Run rolling back must not be cancellable.');
    }
}
