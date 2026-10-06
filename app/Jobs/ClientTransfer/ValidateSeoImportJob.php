<?php

declare(strict_types=1);

namespace App\Jobs\ClientTransfer;

use App\Models\ClientTransferRun;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ValidateSeoImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('client-transfer');
    }

    public function handle(): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->first();
        if ($run === null || $run->shouldStopTransfer()) {
            return;
        }

        $run->update([
            'phase' => 'validate',
        ]);

        $refMap = new ReferenceMap($this->runId);
        $failedCount = $refMap->countByStatus('failed');
        $blockedCount = $refMap->countByStatus('blocked_by_parent');
        $warningsCount = $refMap->countByStatus('warning');
        $missingRefsCount = $refMap->countMissingRefs();

        // Update run metrics from persistent log storage without mixing blocked into failed
        $run->update([
            'failed_count' => $failedCount,
            'blocked_count' => $blockedCount,
            'warnings_count' => $warningsCount,
            'missing_refs_count' => $missingRefsCount,
        ]);

        $run->refresh();
        if ($run->shouldStopTransfer()) {
            return;
        }

        BuildRetryPackageJob::dispatch($this->runId)->onQueue('client-transfer');
    }

    public function failed(\Throwable $e): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->first();
        if ($run !== null && ! $run->isCancelled()) {
            $run->markFailed($e->getMessage());
        }
    }
}
