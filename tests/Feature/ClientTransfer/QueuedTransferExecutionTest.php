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
use App\Jobs\ClientTransfer\PrepareSeoExportJob;
use App\Jobs\ClientTransfer\PrepareSeoImportJob;
use App\Jobs\ClientTransfer\ResolveDeferredSliceJob;
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
            new ResolveDeferredSliceJob('test-run'),
            new ValidateSeoImportJob('test-run'),
            new BuildRetryPackageJob('test-run'),
            new FinalizeSeoImportJob('test-run'),
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
        $user = User::query()->create(['name' => 'Queued Author', 'email' => 'author@queued.test']);
        $site = Site::query()->create(['domain' => 'queued.test', 'user_id' => $user->id, 'status' => 'active']);

        for ($i = 1; $i <= 5; $i++) {
            $article = new SeoArticle;
            $article->site_id = (int) $site->id;
            $article->author_id = (int) $user->id;
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
}
