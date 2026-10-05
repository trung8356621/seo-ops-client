<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Logging;

final class ImportRecordLog
{
    public function __construct(
        public readonly string $importRunId,
        public readonly string $dataset,
        public readonly string $part,
        public readonly string $recordRef,
        public readonly ImportStatus $status,
        public readonly ?string $errorType = null,
        public readonly ?string $message = null,
        public readonly ?string $sourceFile = null,
        public readonly int $recordIndex = 0,
        public readonly ?string $createdAt = null,
        public readonly ?array $rawRecord = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'import_run_id' => $this->importRunId,
            'dataset' => $this->dataset,
            'part' => $this->part,
            'record_ref' => $this->recordRef,
            'status' => $this->status->value,
            'error_type' => $this->errorType,
            'message' => $this->message,
            'source_file' => $this->sourceFile,
            'record_index' => $this->recordIndex,
            'created_at' => $this->createdAt ?? date('c'),
        ];
    }
}
