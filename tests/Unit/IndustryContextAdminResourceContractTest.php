<?php

declare(strict_types=1);

namespace Tests\Unit;

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

    public function test_create_and_existing_copy_actions_use_same_exact_preview_path(): void
    {
        $resource = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource.php');
        $create = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource/Pages/CreateIndustryContextProfile.php');
        $edit = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource/Pages/EditIndustryContextProfile.php');

        foreach ([$resource, $create, $edit] as $source) {
            self::assertStringContainsString('->compilePrompt(', (string) $source);
            self::assertStringContainsString("view('filament.components.industry-context-prompt-preview'", (string) $source);
            self::assertStringContainsString('->modalSubmitAction(false)', (string) $source);
            self::assertStringNotContainsString('navigator.clipboard.writeText', (string) $source);
            self::assertStringNotContainsString('->copyPrompt(', (string) $source);
        }
    }

    public function test_preview_modal_renders_full_selectable_prompt_and_direct_clipboard_fallback(): void
    {
        $prompt = "OPERATOR EDITED PROMPT\n\nCanonical Industry Context JSON Schema:\n{".str_repeat('x', 20000).'}';
        $html = view('filament.components.industry-context-prompt-preview', ['prompt' => $prompt])->render();

        self::assertStringContainsString('OPERATOR EDITED PROMPT', $html);
        self::assertStringContainsString(str_repeat('x', 20000), $html);
        self::assertStringContainsString('readonly', $html);
        self::assertStringContainsString('x-on:click="copyPrompt()"', $html);
        self::assertStringContainsString('navigator.clipboard.writeText(text)', $html);
        self::assertStringContainsString("document.execCommand('copy')", $html);
        self::assertStringContainsString("document.createElement('textarea')", $html);
        self::assertStringContainsString('textarea.remove()', $html);
        self::assertStringContainsString('Đã copy prompt', $html);
        self::assertStringContainsString('Không thể copy prompt', $html);
        self::assertStringNotContainsString('undefined', $html);
    }

    public function test_create_copy_and_quick_generate_use_current_form_seed_and_guard_blank_name(): void
    {
        $page = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource/Pages/CreateIndustryContextProfile.php');

        self::assertStringContainsString('$this->form->getRawState()', (string) $page);
        self::assertStringContainsString("->modalHidden(fn (): bool => \$this->generationSeed()['name'] === '')", (string) $page);
        self::assertSame(1, substr_count((string) $page, "if (\$seed['name'] === '')"));
        self::assertSame(2, substr_count((string) $page, 'Vui lòng nhập tên Ngữ cảnh ngành trước.'));
        self::assertStringContainsString("'language' => \$language !== '' ? \$language : 'vi'", (string) $page);
        self::assertStringContainsString("'market' => \$market !== '' ? \$market : null", (string) $page);
        self::assertStringContainsString("'notes' => \$notes !== '' ? \$notes : null", (string) $page);
        $copyStart = strpos((string) $page, "Action::make('copy_prompt')");
        $quickStart = strpos((string) $page, "Action::make('quick_generate')");
        self::assertIsInt($copyStart);
        self::assertIsInt($quickStart);
        $copyBlock = substr((string) $page, $copyStart, $quickStart - $copyStart);
        self::assertStringContainsString('->compilePrompt(', $copyBlock);
        self::assertStringNotContainsString('context_json', $copyBlock);
        self::assertStringNotContainsString('->generate(', $copyBlock);
    }
}
