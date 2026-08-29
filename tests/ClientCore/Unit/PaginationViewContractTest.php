<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class PaginationViewContractTest extends TestCase
{
    public function test_filament_and_livewire_views_use_shared_window(): void
    {
        $root = ProjectRoot::path();
        $filament = (string) file_get_contents($root.'/resources/views/vendor/filament/components/pagination/index.blade.php');
        $livewire = (string) file_get_contents($root.'/resources/views/vendor/livewire/tailwind.blade.php');
        $numbers = (string) file_get_contents($root.'/resources/views/pagination/livewire-numbers.blade.php');
        $helper = (string) file_get_contents($root.'/app/Core/Support/PaginationWindow.php');

        self::assertStringContainsString('PaginationWindow::tokens', $filament);
        self::assertStringContainsString('PaginationWindow::DESKTOP_SIDE', $filament);
        self::assertStringContainsString("gotoPage(' . \$token . '", $filament);
        self::assertStringNotContainsString("offsetGet('elements')", $filament);

        self::assertStringContainsString('PaginationWindow::tokens', $livewire);
        self::assertStringContainsString('PaginationWindow::DESKTOP_SIDE', $livewire);
        self::assertStringContainsString('PaginationWindow::MOBILE_SIDE', $livewire);
        self::assertStringContainsString('pagination.livewire-numbers', $livewire);
        self::assertStringContainsString("gotoPage({{ \$token }}", $numbers);
        self::assertStringContainsString('previousPage', $filament);
        self::assertStringContainsString('nextPage', $filament);
        self::assertStringContainsString('disabled', $filament);

        self::assertStringContainsString('const DESKTOP_SIDE = 2', $helper);
        self::assertStringContainsString('const MOBILE_SIDE = 1', $helper);
    }

    public function test_target_lists_still_reuse_shared_paginator_not_local_html(): void
    {
        $addons = ProjectRoot::addonsPath();
        $compat = $addons.'/seo-content-ai-compat/resources/views';

        $article = (string) file_get_contents($compat.'/filament/resources/article-resource/pages/list-articles.blade.php');
        $keywords = (string) file_get_contents($compat.'/filament/resources/keywords/pages/list-keywords.blade.php');
        $projects = (string) file_get_contents($compat.'/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php');
        $archive = (string) file_get_contents($compat.'/filament/resources/seo-project-resource/pages/content-project-archive.blade.php');
        $ops = (string) file_get_contents($compat.'/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php');
        $queue = (string) file_get_contents($compat.'/filament/resources/seo-project-resource/pages/content-project-publishing-queue.blade.php');
        $shell = (string) file_get_contents($compat.'/components/list-table-loading-shell.blade.php');

        self::assertStringContainsString('$this->table', $article);
        self::assertStringContainsString('$this->table', $keywords);
        self::assertStringContainsString('$this->table', $projects);
        self::assertStringContainsString('$archives->links()', $archive);
        self::assertStringContainsString('$paginator->links()', $ops);
        self::assertStringContainsString('$rows->links()', $queue);

        self::assertStringNotContainsString('fi-pagination-items', $article);
        self::assertStringNotContainsString('fi-pagination-items', $keywords);
        self::assertStringNotContainsString('fi-pagination-items', $projects);

        self::assertStringContainsString("Livewire.hook('commit'", $shell);
        self::assertStringContainsString('gotoPage', $shell);
        self::assertStringContainsString('previousPage', $shell);
        self::assertStringContainsString('nextPage', $shell);
    }
}
