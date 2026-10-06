<?php

declare(strict_types=1);

namespace App\Jobs\ClientTransfer;

use App\Models\ClientTransferRun;
use App\Services\ClientTransfer\RetryDataPackageExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class BuildRetryDataPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $runId, public readonly string $failureZipPath)
    {
        $this->onQueue('client-transfer');
    }

    public function handle(RetryDataPackageExporter $exporter): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->firstOrFail();
        $run->update(['status' => 'running', 'phase' => 'retry_export']);
        $result = $exporter->export($this->failureZipPath);
        $run->update([
            'processed_records' => array_sum($result['counts']),
            'total_records' => array_sum($result['counts']),
            'metadata' => array_merge($run->metadata ?? [], ['unresolvable_refs' => $result['unresolvable_refs'], 'counts' => $result['counts']]),
        ]);
        $run->markCompleted($result['destination_path']);
    }

    public function failed(\Throwable $e): void
    {
        ClientTransferRun::query()->where('run_id', $this->runId)->first()?->markFailed($e->getMessage());
    }
}
