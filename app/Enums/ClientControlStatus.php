<?php

declare(strict_types=1);

namespace App\Enums;

enum ClientControlStatus: string
{
    case Unregistered = 'unregistered';
    case Active = 'active';
    case Locked = 'locked';
    case Revoked = 'revoked';

    public function blocksBusinessOperations(): bool
    {
        return $this === self::Locked || $this === self::Revoked;
    }

    public function acceptsControlCommands(): bool
    {
        return $this === self::Active || $this === self::Locked;
    }
}
