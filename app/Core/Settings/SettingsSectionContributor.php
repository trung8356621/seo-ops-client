<?php

declare(strict_types=1);

namespace App\Core\Settings;

interface SettingsSectionContributor
{
    public function ownerSlug(): string;

    /**
     * @return list<SettingsSection>
     */
    public function sections(): array;
}
