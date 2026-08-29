<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Current-page-centric page tokens for Filament + Livewire pagination views.
 *
 * Desktop default is ±2 around the current page, always including first/last.
 * Ellipsis only when a real gap of 2+ pages exists.
 */
final class PaginationWindow
{
    public const ELLIPSIS = '…';

    public const DESKTOP_SIDE = 2;

    public const MOBILE_SIDE = 1;

    private function __construct() {}

    /**
     * @return list<int|string>
     */
    public static function tokens(int $currentPage, int $lastPage, int $side = self::DESKTOP_SIDE): array
    {
        if ($lastPage < 1) {
            return [];
        }

        $lastPage = max(1, $lastPage);
        $currentPage = max(1, min($currentPage, $lastPage));
        $side = max(0, $side);

        if ($lastPage === 1) {
            return [1];
        }

        $start = $currentPage - $side;
        $end = $currentPage + $side;

        if ($start < 1) {
            $end += 1 - $start;
            $start = 1;
        }

        if ($end > $lastPage) {
            $start -= $end - $lastPage;
            $end = $lastPage;
        }

        $start = max(1, $start);
        $end = min($lastPage, $end);

        // When +side lands exactly on last, keep one extra page on the inner side
        // so "page 24 / 26" shows 21–26 (spec) instead of a 5-page end cluster.
        if ($end === $lastPage && $currentPage === $lastPage - $side && $start > 2) {
            $start--;
        }

        $selected = [
            1 => true,
            $lastPage => true,
        ];

        for ($page = $start; $page <= $end; $page++) {
            $selected[$page] = true;
        }

        ksort($selected);

        /** @var list<int> $pages */
        $pages = array_map(static fn (int|string $page): int => (int) $page, array_keys($selected));

        return self::withEllipsis($pages);
    }

    /**
     * @param  list<int>  $pages
     * @return list<int|string>
     */
    private static function withEllipsis(array $pages): array
    {
        $out = [];
        $previous = null;

        foreach ($pages as $page) {
            if ($previous === null) {
                $out[] = $page;
                $previous = $page;

                continue;
            }

            $gap = $page - $previous;

            if ($gap === 2) {
                $out[] = $previous + 1;
            } elseif ($gap > 2) {
                $out[] = self::ELLIPSIS;
            }

            $out[] = $page;
            $previous = $page;
        }

        return $out;
    }
}
