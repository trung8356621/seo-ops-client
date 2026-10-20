<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * System Admin concurrency presets (game-graphics style — manual choice only).
 */
enum PerformancePreset: string
{
    case Low = 'low';
    case Mid = 'mid';
    case High = 'high';

    public static function default(): self
    {
        return self::Mid;
    }

    public static function tryFromMixed(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $raw = strtolower(trim((string) $value));

        return self::tryFrom($raw) ?? self::default();
    }

    public function maxHeavyConcurrent(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Mid => 3,
            self::High => 4,
        };
    }

    public function backgroundUserThreshold(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Mid => 3,
            self::High => 6,
        };
    }

    /**
     * Low always restricts optional background heavy work.
     * Mid/High are adaptive based on active-user count.
     */
    public function backgroundMode(): string
    {
        return match ($this) {
            self::Low => 'restricted',
            self::Mid, self::High => 'adaptive',
        };
    }

    public function labelKey(): string
    {
        return 'performance.preset_'.$this->value;
    }

    public function descriptionKey(): string
    {
        return 'performance.preset_'.$this->value.'_help';
    }
}
