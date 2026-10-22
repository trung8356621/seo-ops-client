<?php

declare(strict_types=1);

namespace App\Core\Installation;

final class InstallationState
{
    public const UNCONFIGURED = 'UNCONFIGURED';
    public const CONFIGURING = 'CONFIGURING';
    public const INSTALLED = 'INSTALLED';
    public const DATABASE_UNAVAILABLE = 'DATABASE_UNAVAILABLE';
    public const RECOVERABLE_FAILURE = 'RECOVERABLE_FAILURE';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::UNCONFIGURED,
            self::CONFIGURING,
            self::INSTALLED,
            self::DATABASE_UNAVAILABLE,
            self::RECOVERABLE_FAILURE,
        ];
    }
}
