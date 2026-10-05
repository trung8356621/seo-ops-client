<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ClientTransferRun extends Model
{
    protected $table = 'client_transfer_runs';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'current_part' => 'integer',
        'record_offset' => 'integer',
        'total_records' => 'integer',
        'processed_records' => 'integer',
        'imported_count' => 'integer',
        'failed_count' => 'integer',
        'blocked_count' => 'integer',
        'warnings_count' => 'integer',
        'missing_refs_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['pending', 'running'], true);
    }

    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'finished_at' => now(),
        ]);
    }

    public function markCompleted(?string $artifactPath = null, ?string $retryPackagePath = null): void
    {
        $updates = [
            'status' => 'completed',
            'phase' => 'finished',
            'finished_at' => now(),
        ];

        if ($artifactPath !== null) {
            $updates['artifact_path'] = $artifactPath;
        }

        if ($retryPackagePath !== null) {
            $updates['retry_package_path'] = $retryPackagePath;
        }

        $this->update($updates);
    }
}
