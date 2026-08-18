<?php

declare(strict_types=1);

namespace App\Enums;

enum ControlCommandName: string
{
    case ServicesApply = 'services.apply';
    case ClientLock = 'client.lock';
    case ClientUnlock = 'client.unlock';
    case ClientUpdate = 'client.update';
}
