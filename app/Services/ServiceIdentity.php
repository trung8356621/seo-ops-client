<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Facades\Schema;

/**
 * Public Admin/product slugs vs catalog rows (legacy seo-content-ai).
 */
final class ServiceIdentity
{
    public const PUBLIC_SEO = 'seo';

    public const PUBLIC_SEEDING = 'seeding';

    /** @return list<string> */
    public static function knownPublicSlugs(): array
    {
        return [self::PUBLIC_SEO, self::PUBLIC_SEEDING];
    }

    /**
     * @return list<string>
     */
    public static function catalogSlugsFor(string $publicSlug): array
    {
        return match ($publicSlug) {
            self::PUBLIC_SEO => ['seo-content-ai', 'seo'],
            self::PUBLIC_SEEDING => ['seeding'],
            default => [$publicSlug],
        };
    }

    public static function publicSlugForCatalog(string $catalogSlug): string
    {
        return match ($catalogSlug) {
            'seo-content-ai', 'seo' => self::PUBLIC_SEO,
            'seeding' => self::PUBLIC_SEEDING,
            default => $catalogSlug,
        };
    }

    public static function displayName(string $publicSlug): string
    {
        return match ($publicSlug) {
            self::PUBLIC_SEO => 'SEO',
            self::PUBLIC_SEEDING => 'Seeding',
            default => ucfirst($publicSlug),
        };
    }

    public static function openUrl(string $publicSlug): string
    {
        return match ($publicSlug) {
            self::PUBLIC_SEO => url('/seo'),
            self::PUBLIC_SEEDING => url('/seeding'),
            default => url('/'),
        };
    }

    public static function defaultLogicalConnection(string $publicSlug): string
    {
        return match ($publicSlug) {
            self::PUBLIC_SEO => 'omi_seo_ai',
            self::PUBLIC_SEEDING => 'omi_seeding',
            default => 'mysql',
        };
    }

    public static function findService(string $publicSlug): ?Service
    {
        if (! Schema::hasTable('services')) {
            return null;
        }

        foreach (self::catalogSlugsFor($publicSlug) as $slug) {
            $row = Service::query()->where('slug', $slug)->first();
            if ($row instanceof Service) {
                return $row;
            }
        }

        return null;
    }
}
