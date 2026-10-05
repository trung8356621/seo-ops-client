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
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->firstOrFail();
        $run->update([
            'phase' => 'validate',
        ]);

        $refMap = new ReferenceMap($this->runId);
        $failuresCount = $refMap->countFailures();

        // Update run metrics if needed
        $run->update([
            'failed_count' => max($run->failed_count, $failuresCount),
        ]);

        BuildRetryPackageJob::dispatch($this->runId)->onQueue('client-transfer');
    }

    public function failed(\Throwable $e): void
    {
        ClientTransferRun::query()->where('run_id', $this->runId)->first()?->markFailed($e->getMessage());
    }
}
