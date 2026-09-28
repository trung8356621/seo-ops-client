<?php

declare(strict_types=1);

namespace App\Help;

/**
 * Panel-level Help contexts owned by the client shell.
 * SEO page contexts stay in the legacy SEO registry and resolve first.
 */
final class HelpPanelContextRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function contexts(): array
    {
        return [
            'admin' => [
                'id' => 'admin',
                'modalTitle' => 'Admin',
                'defaultGroupId' => 'admin',
                'routeNames' => ['filament.admin.*'],
                'pathPatterns' => ['\\/admin(?:\\/|$)'],
                'groupIds' => ['admin'],
            ],
            'seeding' => [
                'id' => 'seeding',
                'modalTitle' => 'Seeding',
                'defaultGroupId' => 'seeding',
                'routeNames' => ['filament.seeding.*'],
                'pathPatterns' => ['\\/seeding(?:\\/|$)'],
                'groupIds' => ['seeding'],
            ],
        ];
    }
}
