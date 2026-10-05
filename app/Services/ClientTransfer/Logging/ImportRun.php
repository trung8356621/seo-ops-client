<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Logging;

final class ImportRun
{
    /** @var array<string, array{export_count: int, imported: int, failed: int, blocked: int, warnings: int, missing_refs: int}> */
    private array $datasetStats = [];

    /** @var list<ImportRecordLog> */
    private array $logs = [];

    /** @var array<string, bool> */
    private array $failedRoots = [];

    /** @var list<array{dataset: string, record: array<string, mixed>, log: ImportRecordLog}> */
    private array $quarantinedRecords = [];

    public function __construct(
        public readonly string $runId,
    ) {
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
        } else {
            $this->datasetStats[$dataset]['export_count'] = $exportCount;
        }
    }

    public function markFailedRoot(string $rootRef): void
    {
        $this->failedRoots[$rootRef] = true;
    }

    public function isRootFailed(string $rootRef): bool
    {
        return isset($this->failedRoots[$rootRef]);
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

        $log = new ImportRecordLog(
            importRunId: $this->runId,
            dataset: $dataset,
            part: $part ?? 'unknown',
            recordRef: $ref,
            status: ImportStatus::Failed,
            errorType: $errorType,
            message: $message,
            sourceFile: $file,
            recordIndex: $index,
            rawRecord: $rawRecord,
        );

        $this->logs[] = $log;
        if ($rawRecord !== null) {
            $this->quarantinedRecords[] = [
                'dataset' => $dataset,
                'record' => $rawRecord,
                'log' => $log,
            ];
        }
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

        $log = new ImportRecordLog(
            importRunId: $this->runId,
            dataset: $dataset,
            part: $part ?? 'unknown',
            recordRef: $ref,
            status: ImportStatus::BlockedByParent,
            errorType: 'BLOCKED_BY_PARENT',
            message: "Blocked by failed parent aggregate root [{$parentRef}]",
            sourceFile: $file,
            recordIndex: $index,
            rawRecord: $rawRecord,
        );

        $this->logs[] = $log;
        if ($rawRecord !== null) {
            $this->quarantinedRecords[] = [
                'dataset' => $dataset,
                'record' => $rawRecord,
                'log' => $log,
            ];
        }
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

        $this->logs[] = new ImportRecordLog(
            importRunId: $this->runId,
            dataset: $dataset,
            part: $part ?? 'unknown',
            recordRef: $ref,
            status: ImportStatus::Warning,
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
        return $this->logs;
    }

    /**
     * @return list<array{dataset: string, record: array<string, mixed>, log: ImportRecordLog}>
     */
    public function getQuarantinedRecords(): array
    {
        return $this->quarantinedRecords;
    }

    public function hasFailures(): bool
    {
        return $this->totalFailed() > 0 || $this->totalBlocked() > 0;
    }
}
