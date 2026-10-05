<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Contracts\DatasetInterface;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\ReferenceMap;

abstract class BaseDataset implements DatasetInterface
{
    public function maxRecordsPerPart(): int
    {
        return 20000;
    }

    public function maxBytesPerPart(): int
    {
        return 16777216; // 16 MB
    }

    public function resolveDeferred(ReferenceMap $refMap, ImportRun $run): void
    {
        // Default no-op for datasets without deferred references
    }
}
