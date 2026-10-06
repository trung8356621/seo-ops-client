<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer;

use App\Services\ClientTransfer\Exceptions\FatalImportException;
use App\Services\ClientTransfer\Exceptions\TargetNotEmptyException;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Manifest\TransferManifest;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartReader;
use App\Services\ClientTransfer\Support\ReferenceMap;
use App\Services\ClientTransfer\Support\ZipArchiveManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClientTransferImporter
{
    public function __construct(
        private readonly DatasetRegistry $registry = new DatasetRegistry,
        private readonly TargetSchemaValidator $schemaValidator = new TargetSchemaValidator,
    ) {}

    /**
     * Inspects a transfer package without importing, returning manifest and target readiness.
     *
     * @return array{
     *     manifest: TransferManifest,
     *     connection_ready: bool,
     *     schema_ready: bool,
     *     schema_errors: list<string>,
     *     target_empty: bool,
     *     non_empty_tables: array<string, int>,
     *     service_ready: bool
     * }
     */
    public function inspect(string $zipPath): array
    {
        $extractDir = storage_path('app/client-transfer/staging_inspect_'.Str::random(8));
        try {
            $res = ZipArchiveManager::extractAndValidate($zipPath, $extractDir);
            $manifest = $res['manifest'];

            $connectionReady = $this->isSeoConnectionReady();
            $schemaErrors = $connectionReady ? $this->schemaValidator->validate($manifest) : [];
            $schemaReady = $connectionReady && $schemaErrors === [];
            $nonEmpty = $schemaReady ? $this->getNonEmptyTables($manifest) : [];

            return [
                'manifest' => $manifest,
                'connection_ready' => $connectionReady,
                'schema_ready' => $schemaReady,
                'schema_errors' => $schemaErrors,
                'target_empty' => $schemaReady && empty($nonEmpty),
                'non_empty_tables' => $nonEmpty,
                'service_ready' => $connectionReady && $schemaReady,
            ];
        } finally {
            $this->deleteDir($extractDir);
        }
    }

    /**
     * Imports the transfer package into the client database.
     *
     * @return array{
     *     run_id: string,
     *     total_imported: int,
     *     total_failed: int,
     *     total_blocked: int,
     *     total_warnings: int,
     *     total_missing_refs: int,
     *     dataset_stats: array<string, array{export_count: int, imported: int, failed: int, blocked: int, warnings: int, missing_refs: int}>,
     *     retry_package_path: ?string
     * }
     */
    public function import(string $zipPath, bool $force = false): array
    {
        $runId = Str::random(12);
        $run = new ImportRun($runId);
        $refMap = new ReferenceMap($runId);

        $extractDir = storage_path("app/client-transfer/staging_import_{$runId}");
        if (is_dir($extractDir)) {
            $this->deleteDir($extractDir);
        }

        $res = ZipArchiveManager::extractAndValidate($zipPath, $extractDir);
        $manifest = $res['manifest'];

        try {
            $this->assertTargetReadyForImport($manifest, $force);

            $blobDir = $extractDir.DIRECTORY_SEPARATOR.'content'.DIRECTORY_SEPARATOR.'blobs';
            $blobs = new BlobManager($blobDir);

            // Get topologically sorted datasets
            $datasets = $this->registry->sortedDatasets();

            // Initialize stats from manifest
            foreach ($manifest->datasets as $dKey => $dManifest) {
                $run->initDataset($dKey, $dManifest->count);
            }

            // PHASES 1 - 6: Dataset imports
            foreach ($datasets as $dataset) {
                $datasetKey = $dataset->key();
                $datasetManifest = $manifest->datasets[$datasetKey] ?? null;

                if ($datasetManifest === null) {
                    continue;
                }

                foreach ($datasetManifest->parts as $part) {
                    $partPath = $extractDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $part->file);
                    if (! file_exists($partPath)) {
                        throw new FatalImportException("Part file missing [{$part->file}] for dataset [{$datasetKey}].");
                    }

                    foreach (NdjsonPartReader::read($partPath) as $lineIndex => $record) {
                        $dataset->importRecord(
                            record: $record,
                            refMap: $refMap,
                            run: $run,
                            blobs: $blobs,
                            partFile: $part->file,
                            recordIndex: $lineIndex,
                        );
                    }
                }
            }

            // PHASE 7: Resolve deferred references
            foreach ($datasets as $dataset) {
                $dataset->resolveDeferred($refMap, $run);
            }

            // PHASE 8: Build retry quarantine package if there are failures
            $retryZipPath = null;
            if ($run->hasFailures()) {
                $quarantineDir = storage_path('app/client-transfer/quarantine');
                if (! is_dir($quarantineDir)) {
                    mkdir($quarantineDir, 0755, true);
                }
                $quarantineZipName = 'seo-import-failed-'.date('Ymd-His').'-'.$runId.'.zip';
                $destQuarantineZip = $quarantineDir.DIRECTORY_SEPARATOR.$quarantineZipName;

                $retryZipPath = QuarantinePackageBuilder::build($run, $extractDir, $destQuarantineZip, $this->registry);
            } else {
                $refMap->cleanup();
            }

            return [
                'run_id' => $runId,
                'total_imported' => $run->totalImported(),
                'total_failed' => $run->totalFailed(),
                'total_blocked' => $run->totalBlocked(),
                'total_warnings' => $run->totalWarnings(),
                'total_missing_refs' => $run->totalMissingRefs(),
                'dataset_stats' => $run->getDatasetStats(),
                'retry_package_path' => $retryZipPath,
            ];
        } finally {
            $this->deleteDir($extractDir);
        }
    }

    public function assertTargetReadyForImport(TransferManifest $manifest, bool $force = false): void
    {
        try {
            DB::connection('omi_seo_ai')->getPdo();
        } catch (\Throwable $e) {
            throw new FatalImportException('Target SEO database connection [omi_seo_ai] failed: '.$e->getMessage(), 0, $e);
        }

        $schemaErrors = $this->schemaValidator->validate($manifest);
        if ($schemaErrors !== []) {
            throw new FatalImportException('Target SEO schema is not ready: '.implode('; ', $schemaErrors));
        }

        if ($force) {
            return;
        }

        $nonEmpty = $this->getNonEmptyTables($manifest);
        if (! empty($nonEmpty)) {
            $details = [];
            foreach ($nonEmpty as $tbl => $cnt) {
                $details[] = "{$tbl} ({$cnt} rows)";
            }
            throw new TargetNotEmptyException('Target SEO database contains existing records: ['.implode(', ', $details).']. V1 import requires an empty target database.');
        }
    }

    /**
     * @return array<string, int>
     */
    public function getNonEmptyTables(TransferManifest $manifest): array
    {
        $nonEmpty = [];

        foreach ($this->schemaValidator->portableTables($manifest) as $target) {
            $table = $target['table'];
            try {
                $cnt = DB::connection($target['connection'])->table($table)->count();
                if ($cnt > 0) {
                    $nonEmpty[$table] = $cnt;
                }
            } catch (\Throwable $e) {
                throw new FatalImportException("Failed inspecting target table [{$table}]: ".$e->getMessage(), 0, $e);
            }
        }

        return $nonEmpty;
    }

    public function isSeoConnectionReady(): bool
    {
        try {
            DB::connection('omi_seo_ai')->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
