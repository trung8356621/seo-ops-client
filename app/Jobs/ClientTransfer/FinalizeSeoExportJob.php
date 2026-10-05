<?php

declare(strict_types=1);

namespace App\Jobs\ClientTransfer;

use App\Models\ClientTransferRun;
use App\Services\ClientTransfer\Manifest\DatasetManifest;
use App\Services\ClientTransfer\Manifest\TransferManifest;
use App\Services\ClientTransfer\Support\ZipArchiveManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class FinalizeSeoExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('client-transfer');
    }

    public function handle(): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->firstOrFail();
        $stagingDir = storage_path("app/client-transfer/staging_export_{$this->runId}");
        $stateFile = $stagingDir . DIRECTORY_SEPARATOR . 'export_state.json';

        $state = file_exists($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
        if (! is_array($state)) {
            $state = [];
        }

        $datasetManifests = [];
        foreach ((array) ($state['dataset_manifests'] ?? []) as $key => $manifestData) {
            if (is_array($manifestData)) {
                $datasetManifests[(string) $key] = DatasetManifest::fromArray((string) $key, $manifestData);
            }
        }

        $manifest = new TransferManifest(
            format: TransferManifest::FORMAT,
            formatVersion: TransferManifest::CURRENT_VERSION,
            exportedAt: date('c'),
            source: [
                'app_version' => '1.0.0',
                'database_driver' => config('database.default', 'mysql'),
            ],
            datasets: $datasetManifests,
        );

        file_put_contents($stagingDir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest->toJson());

        $exportDir = storage_path('app/client-transfer/exports');
        if (! is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $destinationZipPath = $exportDir . DIRECTORY_SEPARATOR . 'seo-export-' . date('Ymd-His') . '-' . $this->runId . '.zip';
        ZipArchiveManager::create($stagingDir, $destinationZipPath);

        self::deleteDir($stagingDir);

        $run->markCompleted(artifactPath: $destinationZipPath);
    }

    public function failed(\Throwable $e): void
    {
        ClientTransferRun::query()->where('run_id', $this->runId)->first()?->markFailed($e->getMessage());
    }

    private static function deleteDir(string $dir): void
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
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($p) ? self::deleteDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
