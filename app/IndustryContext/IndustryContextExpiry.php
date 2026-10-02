<?php

declare(strict_types=1);

namespace App\IndustryContext;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class IndustryContextExpiry
{
    /** @return array<string, string> */
    public static function presets(): array
    {
        return ['never' => 'Không hết hạn', '1_month' => '1 tháng', '3_months' => '3 tháng', '6_months' => '6 tháng', '12_months' => '12 tháng', 'custom' => 'Tùy chọn ngày'];
    }

    public static function resolve(string $preset = '6_months', mixed $custom = null, ?CarbonInterface $now = null): ?CarbonImmutable
    {
        $base = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now();

        return match ($preset) {
            'never' => null,
            '1_month' => $base->addMonthNoOverflow(),
            '3_months' => $base->addMonthsNoOverflow(3),
            '12_months' => $base->addMonthsNoOverflow(12),
            'custom' => filled($custom) ? CarbonImmutable::parse($custom) : null,
            default => $base->addMonthsNoOverflow(6),
        };
    }

    public static function status(?CarbonInterface $expiresAt, ?CarbonInterface $now = null): string
    {
        if ($expiresAt === null) {
            return 'never';
        }
        $now ??= CarbonImmutable::now();
        if ($expiresAt->isPast()) {
            return 'expired';
        }

        return $expiresAt->lessThanOrEqualTo($now->copy()->addDays(30)) ? 'expiring' : 'valid';
    }

    public static function label(?CarbonInterface $expiresAt): string
    {
        return match (self::status($expiresAt)) {
            'expired' => 'Đã hết hạn', 'expiring' => 'Sắp hết hạn', 'valid' => 'Còn hạn', default => 'Không hết hạn',
        };
    }
}
