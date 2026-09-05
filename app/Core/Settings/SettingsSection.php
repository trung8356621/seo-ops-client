<?php

declare(strict_types=1);

namespace App\Core\Settings;

/**
 * One navigable settings section entry for the Core Settings shell.
 */
final class SettingsSection
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $icon,
        public readonly string $url,
        public readonly string $owner,
        public readonly int $sort = 100,
        public readonly bool $coreShared = false,
    ) {}
}
