<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\WpOption;
use DateTimeZone;

/**
 * Canonical application Date & Time settings (shared Core).
 * Storage key kept as legacy `seo_datetime_settings` for compatibility — no dual-write.
 */
final class CoreDateTimeSettingsService
{
    public const OPTION_KEY = 'seo_datetime_settings';

    public const KEY_TIMEZONE = 'timezone';

    public const KEY_PRESET = 'preset';

    public const PRESET_VI = 'vi';

    public const PRESET_EN = 'en';

    public const DEFAULT_TIMEZONE = 'Asia/Ho_Chi_Minh';

    public const DEFAULT_PRESET = self::PRESET_VI;

    private const CACHE_KEY = 'seo_datetime_settings.v1';

    /** @var array{timezone: string, preset: string}|null */
    private ?array $inMemorySettings = null;

    /**
     * @return array{timezone: string, preset: string}
     */
    public function defaultSettings(): array
    {
        return [
            self::KEY_TIMEZONE => self::DEFAULT_TIMEZONE,
            self::KEY_PRESET => self::DEFAULT_PRESET,
        ];
    }

    /**
     * @return array{timezone: string, preset: string}
     */
    public function getSettings(): array
    {
        if ($this->inMemorySettings !== null) {
            return $this->normalize($this->inMemorySettings);
        }

        if (function_exists('cache')) {
            try {
                /** @var array{timezone?: string, preset?: string}|null $cached */
                $cached = cache()->get(self::CACHE_KEY);
                if (is_array($cached)) {
                    return $this->normalize($cached);
                }
            } catch (\Throwable) {
            }
        }

        $stored = [];
        try {
            $raw = WpOption::get(self::OPTION_KEY, []);
            if (is_array($raw)) {
                $stored = $raw;
            }
        } catch (\Throwable) {
        }

        $normalized = $this->normalize(array_merge($this->defaultSettings(), $stored));

        if (function_exists('cache')) {
            try {
                cache()->forever(self::CACHE_KEY, $normalized);
            } catch (\Throwable) {
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{timezone: string, preset: string}
     */
    public function save(array $input): array
    {
        $normalized = $this->normalize([
            self::KEY_TIMEZONE => (string) ($input[self::KEY_TIMEZONE] ?? self::DEFAULT_TIMEZONE),
            self::KEY_PRESET => (string) ($input[self::KEY_PRESET] ?? self::DEFAULT_PRESET),
        ]);

        WpOption::set(self::OPTION_KEY, $normalized);
        $this->inMemorySettings = $normalized;

        if (function_exists('cache')) {
            try {
                cache()->forever(self::CACHE_KEY, $normalized);
            } catch (\Throwable) {
            }
        }

        // Keep SEO service cache coherent when both exist.
        $seoClass = 'Omnichannel\\Addons\\Seo\\Services\\SeoDateTimeSettingsService';
        if (class_exists($seoClass)) {
            try {
                $seo = app($seoClass);
                if (method_exists($seo, 'invalidateCache')) {
                    $seo->invalidateCache();
                }
            } catch (\Throwable) {
            }
        }

        return $normalized;
    }

    public static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    public static function isValidPreset(string $preset): bool
    {
        return in_array($preset, [self::PRESET_VI, self::PRESET_EN], true);
    }

    /**
     * @return array<string, string>
     */
    public static function timezoneSelectOptions(): array
    {
        $out = [];
        foreach (DateTimeZone::listIdentifiers() as $id) {
            $out[$id] = $id;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{timezone: string, preset: string}
     */
    private function normalize(array $data): array
    {
        $tz = trim((string) ($data[self::KEY_TIMEZONE] ?? self::DEFAULT_TIMEZONE));
        $preset = strtolower(trim((string) ($data[self::KEY_PRESET] ?? self::DEFAULT_PRESET)));

        if (! self::isValidTimezone($tz)) {
            $tz = self::DEFAULT_TIMEZONE;
        }
        if (! self::isValidPreset($preset)) {
            $preset = self::DEFAULT_PRESET;
        }

        return [
            self::KEY_TIMEZONE => $tz,
            self::KEY_PRESET => $preset,
        ];
    }
}
