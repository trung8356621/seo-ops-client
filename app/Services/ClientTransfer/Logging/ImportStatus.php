<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Logging;

enum ImportStatus: string
{
    case Imported = 'imported';
    case Skipped = 'skipped';
    case Warning = 'warning';
    case Failed = 'failed';
    case BlockedByParent = 'blocked_by_parent';
}
