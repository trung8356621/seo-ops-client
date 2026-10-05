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
use App\Services\ClientTransfer\Exceptions\FatalImportException;
use App\Services\ClientTransfer\Exceptions\TargetNotEmptyException;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\ZipArchiveManager;
use Illuminate\Support\Facades\Queue;
use Omnichannel\Addons\Content\Models\SeoArticle;
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
                'Job [' . get_class($job) . '] must be explicitly bound to [client-transfer] queue.'
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
        $badZipPath = $this->tempDir . DIRECTORY_SEPARATOR . 'traversal.zip';
        $zip = new ZipArchive();
        $zip->open($badZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('../evil.txt', 'malicious content');
        $zip->close();

        $destDir = $this->tempDir . DIRECTORY_SEPARATOR . 'traversal_extract';

        $this->expectException(FatalImportException::class);
        $this->expectExceptionMessage('attempts illegal directory traversal');
        ZipArchiveManager::extractAndValidate($badZipPath, $destDir);
    }

    public function test_hardened_zip_extraction_rejects_absolute_path(): void
    {
        $badZipPath = $this->tempDir . DIRECTORY_SEPARATOR . 'absolute.zip';
        $zip = new ZipArchive();
        $zip->open($badZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('/etc/passwd', 'malicious content');
        $zip->close();

        $destDir = $this->tempDir . DIRECTORY_SEPARATOR . 'absolute_extract';

        $this->expectException(FatalImportException::class);
        $this->expectExceptionMessage('contains an illegal absolute path');
        ZipArchiveManager::extractAndValidate($badZipPath, $destDir);
    }

    public function test_strict_empty_target_check_refuses_when_dirty_and_not_forced(): void
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@dirty.test']);
        $site = Site::query()->create(['domain' => 'dirty.test', 'user_id' => $user->id, 'status' => 'active']);

        $article = new SeoArticle();
        $article->site_id = (int) $site->id;
        $article->title = 'Existing Dirty Target Record';
        $article->saveQuietly();

        $zipPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_target.zip';
        $exporter = new ClientTransferExporter();
        $exporter->export($zipPath);

        $run = ClientTransferRun::query()->create([
            'run_id' => 'dirty-run',
            'type' => 'import',
            'status' => 'pending',
            'phase' => 'queued',
        ]);

        $job = new PrepareSeoImportJob('dirty-run', $zipPath, force: false);

        try {
            $job->handle(new DatasetRegistry());
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
            $article = new SeoArticle();
            $article->site_id = (int) $site->id;
            $article->author_id = (int) $user->id;
            $article->title = "Queued Article {$i}";
            $article->slug = "queued-article-{$i}";
            $article->body = "<p>Queued article body {$i}</p>";
            $article->saveQuietly();
        }

        // 2. Start Queued Export via continuation pipeline
        $exportRunId = 'exp-' . \Illuminate\Support\Str::random(8);
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
        $importRunId = 'imp-' . \Illuminate\Support\Str::random(8);
        $importRun = ClientTransferRun::query()->create([
            'run_id' => $importRunId,
            'type' => 'import',
            'status' => 'pending',
            'phase' => 'queued',
        ]);

        PrepareSeoImportJob::dispatchSync($importRunId, $exportRun->artifact_path, force: false);

        $importRun->refresh();
        self::assertTrue($importRun->isCompleted(), 'Import run must be marked completed by continuation jobs.');
        self::assertSame(5, SeoArticle::query()->count(), 'Target database must contain 5 imported articles.');
        self::assertGreaterThanOrEqual(5, $importRun->imported_count);
    }
}
