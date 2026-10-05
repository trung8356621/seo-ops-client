<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Exceptions;

class BlobChecksumMismatchException extends TransferException
{
    public function __construct(
        public readonly string $dataset,
        public readonly string $recordRef,
        public readonly string $field,
        public readonly string $expectedSha256,
        public readonly string $actualSha256,
    ) {
        parent::__construct(
            "Checksum mismatch for {$dataset} record [{$recordRef}] field [{$field}]: expected {$expectedSha256}, got {$actualSha256}"
        );
    }
}
