<?php

declare(strict_types=1);

namespace App\Enums;

enum ServiceGatewayProvisionStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case NotRequired = 'not_required';
    case NotEntitled = 'not_entitled';
    case Revoked = 'revoked';
    case Error = 'error';
}
