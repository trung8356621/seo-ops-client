<?php

declare(strict_types=1);

namespace App\IndustryContext;

final class IndustryMarketOptions
{
    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            'VN' => 'Việt Nam', 'US' => 'Hoa Kỳ', 'SG' => 'Singapore', 'TH' => 'Thái Lan',
            'MY' => 'Malaysia', 'ID' => 'Indonesia', 'PH' => 'Philippines', 'JP' => 'Nhật Bản',
            'KR' => 'Hàn Quốc', 'AU' => 'Úc', 'CA' => 'Canada', 'GB' => 'Vương quốc Anh',
            'SEA' => 'Đông Nam Á', 'EU' => 'Liên minh Châu Âu', 'GLOBAL' => 'Toàn cầu',
        ];
    }

    public static function default(?string $explicit = null, ?string $language = null): ?string
    {
        $explicit = strtoupper(trim((string) $explicit));
        if ($explicit !== '' && array_key_exists($explicit, self::options())) {
            return $explicit;
        }

        return strtolower(trim((string) $language)) === 'vi' ? 'VN' : null;
    }
}
