<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Support\IndustryContextClipboard;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBindingRunner;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextGenerationService;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextPromptCompiler;
use Tests\TestCase;

final class IndustryContextAdminResourceContractTest extends TestCase
{
    public function test_admin_uses_logical_list_and_exposes_owned_actions(): void
    {
        $resource = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource.php');
        self::assertStringContainsString('logicalRepresentatives()', (string) $resource);
        foreach (['quick_generate', 'ViewAction', 'copy_prompt', 'EditAction'] as $action) {
            self::assertStringContainsString($action, (string) $resource);
        }
        self::assertStringContainsString("where('key', \$record->key)->delete()", (string) $resource);
    }

    public function test_create_generates_before_persistence_and_initial_save_uses_manager(): void
    {
        $page = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource/Pages/CreateIndustryContextProfile.php');
        self::assertStringContainsString("\$state['context_json'] = json_encode", (string) $page);
        self::assertStringContainsString('$this->form->fill($state)', (string) $page);
        self::assertStringContainsString('createInitial(', (string) $page);
    }

    public function test_create_and_existing_copy_actions_use_shared_fallback_clipboard_helper(): void
    {
        $resource = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource.php');
        $create = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource/Pages/CreateIndustryContextProfile.php');
        $edit = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource/Pages/EditIndustryContextProfile.php');

        foreach ([$resource, $create, $edit] as $source) {
            self::assertStringContainsString('IndustryContextClipboard::copyScript($prompt)', (string) $source);
            self::assertStringNotContainsString('navigator.clipboard.writeText', (string) $source);
        }
    }

    public function test_clipboard_helper_falls_back_notifies_and_preserves_complete_prompt(): void
    {
        $prompt = IndustryContextPromptCompiler::runnablePrompt('Balo & túi xách', 'vi', 'VN', 'Temporary note');
        $script = IndustryContextClipboard::copyScript($prompt);

        self::assertStringContainsString('navigator.clipboard.writeText(text)', $script);
        self::assertStringContainsString("document.execCommand('copy')", $script);
        self::assertStringContainsString("document.createElement('textarea')", $script);
        self::assertStringContainsString('Đã copy prompt', $script);
        self::assertStringContainsString('Không thể copy prompt', $script);
        self::assertStringContainsString('Balo & túi xách', $prompt);
        self::assertStringContainsString('in "vi" for market "VN"', $prompt);
        self::assertStringContainsString('Temporary note', $prompt);
        self::assertStringContainsString('Canonical Industry Context JSON Schema', $prompt);
        self::assertStringNotContainsString('CURRENT_CONTEXT_SENTINEL', $prompt);
        self::assertStringNotContainsString('IndustryContextProfile::', $script);
    }

    public function test_copy_prompt_performs_no_database_write_or_ai_execution(): void
    {
        $runner = new class implements PromptHookBindingRunner
        {
            public bool $called = false;

            public function execute(SeoPrompt $prompt, array $variables = [], array $contextExtras = [], array $previousOutputs = []): array
            {
                $this->called = true;

                return [];
            }
        };
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $prompt = (new IndustryContextGenerationService($runner))->copyPrompt('Bags', 'en', 'US', 'Note');

        self::assertFalse($runner->called);
        self::assertSame([], $queries);
        self::assertStringContainsString('Canonical Industry Context JSON Schema', $prompt);
    }

    public function test_create_copy_and_quick_generate_use_current_form_seed_and_guard_blank_name(): void
    {
        $page = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource/Pages/CreateIndustryContextProfile.php');

        self::assertStringContainsString('$this->form->getRawState()', (string) $page);
        self::assertSame(2, substr_count((string) $page, "if (\$seed['name'] === '')"));
        self::assertSame(2, substr_count((string) $page, 'Vui lòng nhập tên Ngữ cảnh ngành trước.'));
        self::assertStringContainsString("'language' => \$language !== '' ? \$language : 'vi'", (string) $page);
        self::assertStringContainsString("'market' => \$market !== '' ? \$market : null", (string) $page);
        self::assertStringContainsString("'notes' => \$notes !== '' ? \$notes : null", (string) $page);
        self::assertStringContainsString("if (trim(\$prompt) === '')", (string) $page);
        self::assertStringContainsString('Không thể tạo prompt.', (string) $page);

        $copyGuard = strpos((string) $page, "if (\$seed['name'] === '')");
        $copyCall = strpos((string) $page, '->copyPrompt(');
        $clipboardCall = strpos((string) $page, 'IndustryContextClipboard::copyScript($prompt)');
        self::assertIsInt($copyGuard);
        self::assertIsInt($copyCall);
        self::assertIsInt($clipboardCall);
        self::assertLessThan($copyCall, $copyGuard);
        self::assertLessThan($clipboardCall, $copyCall);
        self::assertStringNotContainsString("['context_json']", substr((string) $page, $copyGuard, $clipboardCall - $copyGuard));
    }
}
