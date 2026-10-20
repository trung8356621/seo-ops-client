<?php

declare(strict_types=1);

namespace App\Core\Performance;

use App\Models\WpOption;

/**
 * Persist System Settings → Performance preset via existing WpOption store.
 */
final class PerformanceSettings
{
    public const OPTION_KEY = 'system_performance_preset';

    public const KEY_PRESET = 'preset';

    private const CACHE_KEY = 'system_performance_preset.v1';

    private ?PerformancePreset $memo = null;

    public function preset(): PerformancePreset
    {
        if ($this->memo instanceof PerformancePreset) {
            return $this->memo;
        }

        if (function_exists('cache')) {
            try {
                $cached = cache()->get(self::CACHE_KEY);
                if (is_string($cached) && $cached !== '') {
                    return $this->memo = PerformancePreset::tryFromMixed($cached);
                }
            } catch (\Throwable) {
                // unbound cache in pure unit tests
            }
        }

        $raw = WpOption::get(self::OPTION_KEY, []);
        $preset = is_array($raw)
            ? PerformancePreset::tryFromMixed($raw[self::KEY_PRESET] ?? null)
            : PerformancePreset::tryFromMixed($raw);

        $this->memo = $preset;
        $this->remember($preset);

        return $preset;
    }

    public function maxHeavyConcurrent(): int
    {
        return $this->preset()->maxHeavyConcurrent();
    }

    public function backgroundUserThreshold(): int
    {
        return $this->preset()->backgroundUserThreshold();
    }

    public function backgroundMode(): string
    {
        return $this->preset()->backgroundMode();
    }

    /**
     * @return array{preset: string, max_heavy_concurrent: int, background_user_threshold: int, background_mode: string}
     */
    public function snapshot(): array
    {
        $preset = $this->preset();

        return [
            self::KEY_PRESET => $preset->value,
            'max_heavy_concurrent' => $preset->maxHeavyConcurrent(),
            'background_user_threshold' => $preset->backgroundUserThreshold(),
            'background_mode' => $preset->backgroundMode(),
        ];
    }

    public function savePreset(PerformancePreset|string $preset): PerformancePreset
    {
        $resolved = PerformancePreset::tryFromMixed($preset);
        WpOption::set(self::OPTION_KEY, [
            self::KEY_PRESET => $resolved->value,
        ], 'yes');
        $this->memo = $resolved;
        $this->remember($resolved);

        return $resolved;
    }

    public function forgetMemo(): void
    {
        $this->memo = null;
        if (function_exists('cache')) {
            try {
                cache()->forget(self::CACHE_KEY);
            } catch (\Throwable) {
            }
        }
    }

    private function remember(PerformancePreset $preset): void
    {
        if (! function_exists('cache')) {
            return;
        }

        try {
            cache()->forever(self::CACHE_KEY, $preset->value);
        } catch (\Throwable) {
        }
    }
}
