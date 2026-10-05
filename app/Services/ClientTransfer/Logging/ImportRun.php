<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Logging;

use App\Services\ClientTransfer\Support\ReferenceMap;

final class ImportRun
{
    /** @var array<string, array{export_count: int, imported: int, failed: int, blocked: int, warnings: int, missing_refs: int}> */
    private array $datasetStats = [];

    private ReferenceMap $refMap;

    public function __construct(
        public readonly string $runId,
        ?ReferenceMap $refMap = null,
    ) {
        $this->refMap = $refMap ?? new ReferenceMap($runId);
    }

    public function getReferenceMap(): ReferenceMap
    {
        return $this->refMap;
    }

    public function initDataset(string $dataset, int $exportCount = 0): void
    {
        if (! isset($this->datasetStats[$dataset])) {
            $this->datasetStats[$dataset] = [
                'export_count' => $exportCount,
                'imported' => 0,
                'failed' => 0,
                'blocked' => 0,
                'warnings' => 0,
                'missing_refs' => 0,
            ];
        } elseif ($exportCount > 0) {
            $this->datasetStats[$dataset]['export_count'] = $exportCount;
        }
    }

    public function markFailedRoot(string $rootRef): void
    {
        $this->refMap->markFailedRoot($rootRef);
    }

    public function isRootFailed(string $rootRef): bool
    {
        return $this->refMap->isRootFailed($rootRef);
    }

    public function recordImported(string $dataset, string $ref, ?string $part = null, int $index = 0): void
    {
        $this->initDataset($dataset);
        $this->datasetStats[$dataset]['imported']++;
    }

    public function recordFailed(
        string $dataset,
        string $ref,
        string $errorType,
        string $message,
        ?string $part = null,
        int $index = 0,
        ?string $file = null,
        ?array $rawRecord = null,
    ): void {
        $this->initDataset($dataset);
        $this->datasetStats[$dataset]['failed']++;

        $blobRef = ! empty($rawRecord['body_blob']) ? (string) $rawRecord['body_blob'] : null;

        $this->refMap->logRecord(
            runId: $this->runId,
            dataset: $dataset,
            part: $part ?? 'unknown',
            recordRef: $ref,
            status: ImportStatus::Failed->value,
            errorType: $errorType,
            message: $message,
            recordIndex: $index,
            sourceFile: $file,
            blobRef: $blobRef,
        );
    }

    public function recordBlocked(
        string $dataset,
        string $ref,
        string $parentRef,
        ?string $part = null,
        int $index = 0,
        ?string $file = null,
        ?array $rawRecord = null,
    ): void {
        $this->initDataset($dataset);
        $this->datasetStats[$dataset]['blocked']++;

        $blobRef = ! empty($rawRecord['body_blob']) ? (string) $rawRecord['body_blob'] : null;

        $this->refMap->logRecord(
            runId: $this->runId,
            dataset: $dataset,
            part: $part ?? 'unknown',
            recordRef: $ref,
            status: ImportStatus::BlockedByParent->value,
            errorType: 'BLOCKED_BY_PARENT',
            message: "Blocked by failed parent aggregate root [{$parentRef}]",
            recordIndex: $index,
            sourceFile: $file,
            blobRef: $blobRef,
        );
    }

    public function recordWarning(
        string $dataset,
        string $ref,
        string $message,
        ?string $part = null,
        int $index = 0,
        bool $isMissingRef = false,
    ): void {
        $this->initDataset($dataset);
        $this->datasetStats[$dataset]['warnings']++;
        if ($isMissingRef) {
            $this->datasetStats[$dataset]['missing_refs']++;
        }

        $this->refMap->logRecord(
            runId: $this->runId,
            dataset: $dataset,
            part: $part ?? 'unknown',
            recordRef: $ref,
            status: ImportStatus::Warning->value,
            errorType: $isMissingRef ? 'MISSING_REF' : 'WARNING',
            message: $message,
            recordIndex: $index,
        );
    }

    public function totalImported(): int
    {
        return array_sum(array_column($this->datasetStats, 'imported'));
    }

    public function totalFailed(): int
    {
        return array_sum(array_column($this->datasetStats, 'failed'));
    }

    public function totalBlocked(): int
    {
        return array_sum(array_column($this->datasetStats, 'blocked'));
    }

    public function totalWarnings(): int
    {
        return array_sum(array_column($this->datasetStats, 'warnings'));
    }

    public function totalMissingRefs(): int
    {
        return array_sum(array_column($this->datasetStats, 'missing_refs'));
    }

    /**
     * @return array<string, array{export_count: int, imported: int, failed: int, blocked: int, warnings: int, missing_refs: int}>
     */
    public function getDatasetStats(): array
    {
        return $this->datasetStats;
    }

    /**
     * @return list<ImportRecordLog>
     */
    public function getLogs(): array
    {
        $rows = $this->refMap->getLogsChunk(0, 1000);
        $logs = [];
        foreach ($rows as $row) {
            $logs[] = new ImportRecordLog(
                importRunId: $row['run_id'],
                dataset: $row['dataset'],
                part: $row['part'],
                recordRef: $row['record_ref'],
                status: ImportStatus::tryFrom($row['status']) ?? ImportStatus::Failed,
                errorType: $row['error_type'],
                message: $row['message'],
                sourceFile: $row['source_file'],
                recordIndex: $row['record_index'],
                createdAt: $row['created_at'],
            );
        }

        return $logs;
    }

    public function hasFailures(): bool
    {
        return $this->totalFailed() > 0 || $this->totalBlocked() > 0;
    }
}
