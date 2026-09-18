<?php

declare(strict_types=1);

namespace App\System\Support;

enum SystemExecutionMode: string
{
    case Legacy = 'legacy';
    case Shadow = 'shadow';
    case Remote = 'remote';

    public static function tryParse(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        $normalized = strtolower(trim($raw));
        if ($normalized === 'native') {
            $normalized = 'remote';
        }

        return self::tryFrom($normalized);
    }
}
