<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Contracts;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ReferenceMap;

interface DatasetInterface
{
    public function key(): string;

    public function relativeSubdir(): string;

    /**
     * @return list<string> Keys of datasets that must be imported before this dataset
     */
    public function dependencies(): array;

    /**
     * Max records per partition file.
     */
    public function maxRecordsPerPart(): int;

    /**
     * Target byte size per partition file.
     */
    public function maxBytesPerPart(): int;

    /**
     * Exports dataset records using the writer and blob manager.
     *
     * @return int Total count of records exported
     */
    public function export(NdjsonPartWriter $writer, BlobManager $blobs): int;

    /**
     * Export current canonical records for the supplied logical references.
     *
     * @param  iterable<string>  $refs
     * @return array{count: int, unresolved: list<string>}
     */
    public function exportSelectedRefs(iterable $refs, NdjsonPartWriter $writer, BlobManager $blobs): array;

    /**
     * Exports a slice of dataset records.
     *
     * @return array{count: int, last_id: int, has_more: bool}
     */
    public function exportSlice(NdjsonPartWriter $writer, BlobManager $blobs, int $afterId = 0, int $limit = 500): array;

    /**
     * Imports a single record from the NDJSON part.
     *
     * @param  array<string, mixed>  $record
     */
    public function importRecord(
        array $record,
        ReferenceMap $refMap,
        ImportRun $run,
        BlobManager $blobs,
        string $partFile,
        int $recordIndex,
    ): void;

    /**
     * Phase 7: Resolves deferred references registered in Pass 1.
     */
    public function resolveDeferred(ReferenceMap $refMap, ImportRun $run): void;

    /**
     * Removes one target record recorded as created by this import run.
     *
     * @param  array<string, mixed>  $context
     */
    public function rollbackImportedRecord(string $targetKey, array $context = []): void;
}
