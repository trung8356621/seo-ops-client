<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Support\PaginationWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaginationWindowTest extends TestCase
{
    /**
     * @param  list<int|string>  $expected
     */
    #[DataProvider('desktopTotalPagesProvider')]
    public function test_desktop_window_for_small_totals(int $lastPage, int $currentPage, array $expected): void
    {
        $this->assertWindow($expected, $currentPage, $lastPage, PaginationWindow::DESKTOP_SIDE);
    }

    /**
     * @return array<string, array{int, int, list<int|string>}>
     */
    public static function desktopTotalPagesProvider(): array
    {
        $e = PaginationWindow::ELLIPSIS;

        return [
            '1 page' => [1, 1, [1]],
            '2 pages p1' => [2, 1, [1, 2]],
            '2 pages p2' => [2, 2, [1, 2]],
            '3 pages p1' => [3, 1, [1, 2, 3]],
            '3 pages p2' => [3, 2, [1, 2, 3]],
            '3 pages p3' => [3, 3, [1, 2, 3]],
            '5 pages p1' => [5, 1, [1, 2, 3, 4, 5]],
            '5 pages p3' => [5, 3, [1, 2, 3, 4, 5]],
            '5 pages p5' => [5, 5, [1, 2, 3, 4, 5]],
            '6 pages p1' => [6, 1, [1, 2, 3, 4, 5, 6]],
            '6 pages p4' => [6, 4, [1, 2, 3, 4, 5, 6]],
            '6 pages p6' => [6, 6, [1, 2, 3, 4, 5, 6]],
            '10 pages p1' => [10, 1, [1, 2, 3, 4, 5, $e, 10]],
            '10 pages p5' => [10, 5, [1, 2, 3, 4, 5, 6, 7, $e, 10]],
            '10 pages p10' => [10, 10, [1, $e, 6, 7, 8, 9, 10]],
        ];
    }

    /**
     * @param  list<int|string>  $expected
     */
    #[DataProvider('desktop26Provider')]
    public function test_desktop_window_total_26(int $currentPage, array $expected): void
    {
        $this->assertWindow($expected, $currentPage, 26, PaginationWindow::DESKTOP_SIDE);
    }

    /**
     * @return array<string, array{int, list<int|string>}>
     */
    public static function desktop26Provider(): array
    {
        $e = PaginationWindow::ELLIPSIS;

        return [
            'page 1' => [1, [1, 2, 3, 4, 5, $e, 26]],
            'page 2' => [2, [1, 2, 3, 4, 5, $e, 26]],
            'page 3' => [3, [1, 2, 3, 4, 5, $e, 26]],
            'page 4' => [4, [1, 2, 3, 4, 5, 6, $e, 26]],
            'page 5' => [5, [1, 2, 3, 4, 5, 6, 7, $e, 26]],
            'page 10' => [10, [1, $e, 8, 9, 10, 11, 12, $e, 26]],
            'page 15' => [15, [1, $e, 13, 14, 15, 16, 17, $e, 26]],
            'page 24' => [24, [1, $e, 21, 22, 23, 24, 25, 26]],
            'page 25' => [25, [1, $e, 22, 23, 24, 25, 26]],
            'page 26' => [26, [1, $e, 22, 23, 24, 25, 26]],
        ];
    }

    public function test_mobile_window_uses_plus_minus_one(): void
    {
        $e = PaginationWindow::ELLIPSIS;

        $this->assertWindow(
            [1, $e, 9, 10, 11, $e, 26],
            10,
            26,
            PaginationWindow::MOBILE_SIDE,
        );
        $this->assertWindow(
            [1, 2, 3, $e, 26],
            1,
            26,
            PaginationWindow::MOBILE_SIDE,
        );
        $this->assertWindow(
            [1, $e, 24, 25, 26],
            26,
            26,
            PaginationWindow::MOBILE_SIDE,
        );
    }

    public function test_does_not_render_ellipsis_next_to_adjacent_page(): void
    {
        $tokens = PaginationWindow::tokens(2, 4, PaginationWindow::DESKTOP_SIDE);

        $this->assertSame([1, 2, 3, 4], $tokens);
        $this->assertNotContains(PaginationWindow::ELLIPSIS, $tokens);
    }

    public function test_invariants_for_all_pages_of_26(): void
    {
        for ($page = 1; $page <= 26; $page++) {
            $this->assertInvariants(
                PaginationWindow::tokens($page, 26),
                $page,
                26,
            );
        }
    }

    /**
     * @param  list<int|string>  $expected
     */
    private function assertWindow(array $expected, int $currentPage, int $lastPage, int $side): void
    {
        $tokens = PaginationWindow::tokens($currentPage, $lastPage, $side);

        $this->assertSame(
            $expected,
            $tokens,
            sprintf('current=%d last=%d side=%d', $currentPage, $lastPage, $side),
        );
        $this->assertInvariants($tokens, $currentPage, $lastPage);
    }

    /**
     * @param  list<int|string>  $tokens
     */
    private function assertInvariants(array $tokens, int $currentPage, int $lastPage): void
    {
        $pages = [];
        $previousWasEllipsis = false;

        foreach ($tokens as $token) {
            if ($token === PaginationWindow::ELLIPSIS) {
                $this->assertFalse($previousWasEllipsis, 'duplicate ellipsis');
                $previousWasEllipsis = true;

                continue;
            }

            $this->assertIsInt($token);
            $this->assertGreaterThanOrEqual(1, $token);
            $this->assertLessThanOrEqual($lastPage, $token);
            $this->assertNotContains($token, $pages, 'duplicate page '.$token);
            $pages[] = $token;
            $previousWasEllipsis = false;
        }

        $this->assertContains($currentPage, $pages, 'current page missing from window');
        $this->assertSame(1, $pages[0] ?? null);
        $this->assertSame($lastPage, $pages[array_key_last($pages)] ?? null);
        $this->assertSame($pages, array_values(array_unique($pages)));
    }
}
