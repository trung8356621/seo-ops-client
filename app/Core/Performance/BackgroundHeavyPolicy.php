<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Whether optional background-tab heavy work may start under the current preset.
 */
final class BackgroundHeavyPolicy
{
    public function __construct(
        private readonly PerformanceSettings $settings = new PerformanceSettings(),
        private readonly ActiveUserPresence $presence = new ActiveUserPresence(),
    ) {}

    public function allowsOptionalBackground(?int $activeUsers = null): bool
    {
        $preset = $this->settings->preset();
        if ($preset === PerformancePreset::Low) {
            return false;
        }

        $active = $activeUsers ?? $this->presence->count();
        $threshold = $preset->backgroundUserThreshold();

        return $active <= $threshold;
    }
}
