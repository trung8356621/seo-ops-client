<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

/**
 * Shared x-select: select element has no arrow background; wrapper owns exactly one chevron.
 */
final class XSelectChevronContractTest extends TestCase
{
    public function test_select_has_no_background_arrow_and_wrapper_owns_one_chevron(): void
    {
        $blade = (string) file_get_contents(
            ProjectRoot::path().'/resources/views/components/select.blade.php',
        );

        self::assertStringContainsString('<span class="x-select-chevron" aria-hidden="true"></span>', $blade);
        self::assertStringContainsString('.x-select-wrap > select.x-select', $blade);
        self::assertStringContainsString('background-image: none !important', $blade);
        self::assertStringContainsString('appearance: none !important', $blade);
        self::assertStringContainsString('.x-select-wrap > .x-select-chevron', $blade);
        self::assertStringContainsString('background-repeat: no-repeat !important', $blade);
        self::assertStringContainsString('background-position: center !important', $blade);
        self::assertStringContainsString('.x-select-wrap > .x-select-chevron ~ .x-select-chevron', $blade);
        self::assertStringContainsString('display: none !important', $blade);
    }

    public function test_seo_select_css_matches_same_invariant(): void
    {
        $css = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/css/seo-select.css',
        );

        self::assertStringContainsString('.seo-select-wrap > select.seo-select', $css);
        self::assertStringContainsString('background-image: none !important', $css);
        self::assertStringContainsString('appearance: none !important', $css);
        self::assertStringContainsString('.seo-select-wrap > .seo-select-chevron', $css);
        self::assertStringContainsString('.seo-select-wrap > .seo-select-chevron ~ .seo-select-chevron', $css);
    }
}
