<?php

declare(strict_types=1);

namespace App\Enums;

enum ServiceApiCredentialKind: string
{
    case User = 'user';
    case Managed = 'managed';
}
