<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer;

use App\Services\ClientTransfer\Exceptions\FatalImportException;
use App\Services\ClientTransfer\Support\ZipArchiveManager;
use Illuminate\Support\Str;

final class FailureRequestInspector
{
    /** @return array{manifest: \App\Services\ClientTransfer\Manifest\TransferManifest, original_import_run_id: string, refs: array<string, list<string>>, failed_roots: int, blocked: int, datasets: list<string>} */
    public function inspect(string $zipPath): array
    {
        $dir = storage_path('app/client-transfer/staging_failure_inspect_'.Str::random(8));
        try {
            $result = ZipArchiveManager::extractAndValidate($zipPath, $dir);
            $manifest = $result['manifest'];
            if (! $manifest->isFailureRequest() || $manifest->isRetryData()) {
                throw new FatalImportException('Package is not an import failure request package.');
            }

            $runId = $manifest->originalImportRunId();
            if ($runId === null) {
                throw new FatalImportException('Failure request is missing original import run id.');
            }

            $logPath = $dir.DIRECTORY_SEPARATOR.'import-errors.ndjson';
            if (! is_file($logPath)) {
                throw new FatalImportException('Failure request is missing import-errors.ndjson.');
            }

            $refs = [];
            $failed = 0;
            $blocked = 0;
            $handle = fopen($logPath, 'rb');
            if ($handle === false) {
                throw new FatalImportException('Unable to read failure request log.');
            }
            try {
                while (($line = fgets($handle)) !== false) {
                    $row = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($row)) {
                        throw new FatalImportException('Invalid failure request log entry.');
                    }
                    $status = (string) ($row['status'] ?? '');
                    if (! in_array($status, ['failed', 'blocked_by_parent'], true)) {
                        continue;
                    }
                    $dataset = (string) ($row['dataset'] ?? '');
                    $ref = (string) ($row['record_ref'] ?? '');
                    if ($dataset === '' || $ref === '') {
                        throw new FatalImportException('Failure request log entry is missing dataset or logical ref.');
                    }
                    $refs[$dataset][$ref] = true;
                    $status === 'failed' ? $failed++ : $blocked++;
                }
            } finally {
                fclose($handle);
            }

            $grouped = [];
            foreach ($refs as $dataset => $datasetRefs) {
                $grouped[$dataset] = array_keys($datasetRefs);
            }

            return [
                'manifest' => $manifest,
                'original_import_run_id' => $runId,
                'refs' => $grouped,
                'failed_roots' => $failed,
                'blocked' => $blocked,
                'datasets' => array_keys($grouped),
            ];
        } finally {
            self::deleteDir($dir);
        }
    }

    private static function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? self::deleteDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
