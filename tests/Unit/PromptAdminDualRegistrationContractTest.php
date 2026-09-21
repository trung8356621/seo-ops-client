<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages\CreatePrompt;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages\EditPrompt;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages\ListPrompts;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages\TestPrompt;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ViewArticlePrompts;
use ReflectionClass;
use Tests\TestCase;

/**
 * STEP 2B.1 — dual-register existing PromptResource on Admin without cloning UI.
 */
final class PromptAdminDualRegistrationContractTest extends TestCase
{
    public function test_admin_panel_provider_registers_prompt_resource(): void
    {
        $provider = (string) file_get_contents(
            (new ReflectionClass(AdminPanelProvider::class))->getFileName()
        );
        self::assertStringContainsString('PromptResource::class', $provider);
        self::assertStringContainsString('Dual-register existing Prompt management', $provider);

        $panel = Filament::getPanel('admin');
        self::assertContains(PromptResource::class, $panel->getResources());
    }

    public function test_seo_panels_still_register_same_prompt_resource_class(): void
    {
        foreach (['seo-main', 'seo'] as $panelId) {
            try {
                $panel = Filament::getPanel($panelId);
            } catch (\Throwable) {
                continue;
            }
            self::assertContains(
                PromptResource::class,
                $panel->getResources(),
                "Panel {$panelId} must still discover PromptResource"
            );
        }
    }

    public function test_admin_and_seo_use_exact_same_prompt_resource_class(): void
    {
        $admin = Filament::getPanel('admin')->getResources();
        self::assertContains(PromptResource::class, $admin);

        $seoMain = Filament::getPanel('seo-main')->getResources();
        self::assertContains(PromptResource::class, $seoMain);

        $adminEntry = array_values(array_filter(
            $admin,
            static fn (string $c): bool => $c === PromptResource::class
        ));
        $seoEntry = array_values(array_filter(
            $seoMain,
            static fn (string $c): bool => $c === PromptResource::class
        ));
        self::assertSame($adminEntry[0] ?? null, $seoEntry[0] ?? null);
        self::assertSame(PromptResource::class, $adminEntry[0] ?? null);
    }

    public function test_no_cloned_admin_prompt_resource_or_page_classes(): void
    {
        $clientFilament = app_path('Filament');
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($clientFilament, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $name = $file->getFilename();
            if (preg_match('/Prompt(Resource|Page|List|Edit|Create|Test)/i', $name) === 1) {
                $hits[] = $file->getPathname();
            }
        }
        self::assertSame([], $hits, 'Client must not introduce a cloned Prompt Filament UI');
    }

    public function test_existing_prompt_pages_unchanged_paths(): void
    {
        foreach ([
            ListPrompts::class,
            CreatePrompt::class,
            EditPrompt::class,
            TestPrompt::class,
        ] as $page) {
            $path = (string) (new ReflectionClass($page))->getFileName();
            self::assertStringContainsString(
                'ai-prompt'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Filament'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'PromptResource',
                $path
            );
        }
    }

    public function test_domain_article_ai_history_unchanged(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ViewArticlePrompts::class))->getFileName()
        );
        self::assertStringContainsString('ArticleAiHistoryApplicationService', $src);
        self::assertStringContainsString('ai_calls', $src);
        self::assertStringNotContainsString('AdminPanelProvider', $src);
    }

    public function test_admin_prompt_urls_default_to_admin_panel(): void
    {
        self::assertSame('admin', PromptResource::panelId());

        $defaultIndex = PromptResource::getUrl('index');
        $adminIndex = PromptResource::getUrl('index', panel: 'admin');
        $adminCreate = PromptResource::getUrl('create', panel: 'admin');
        $adminEdit = PromptResource::getUrl('edit', ['record' => 26], panel: 'admin');
        $adminTest = PromptResource::getUrl('test', ['record' => 26], panel: 'admin');

        self::assertSame($defaultIndex, $adminIndex);
        self::assertStringContainsString('/admin/prompts', $adminIndex);
        self::assertStringContainsString('/admin/prompts/create', $adminCreate);
        self::assertStringContainsString('/admin/prompts/26/edit', $adminEdit);
        self::assertStringContainsString('/admin/prompts/26/test', $adminTest);

        $seoIndex = PromptResource::getUrl('index', panel: 'seo-main');
        self::assertStringContainsString('/seo/prompts', $seoIndex);
        self::assertStringNotContainsString('/admin/prompts', $seoIndex);
    }
}
