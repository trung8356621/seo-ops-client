<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Support\IndustryContextClipboard;
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

    public function test_create_and_existing_copy_actions_use_same_direct_exact_compile_path(): void
    {
        $resource = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource.php');
        $create = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource/Pages/CreateIndustryContextProfile.php');
        $edit = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource/Pages/EditIndustryContextProfile.php');

        foreach ([$resource, $create, $edit] as $source) {
            self::assertStringContainsString('->compilePrompt(', (string) $source);
            self::assertStringContainsString('IndustryContextClipboard::copyAttributes($prompt)', (string) $source);
            self::assertStringNotContainsString("'x-on:click'", (string) $source);
            self::assertStringNotContainsString("'onclick'", (string) $source);
            self::assertStringNotContainsString('->modalContent(', (string) $source);
            self::assertStringNotContainsString('->modalHeading(', (string) $source);
            self::assertStringNotContainsString('navigator.clipboard.writeText', (string) $source);
        }
        self::assertFileDoesNotExist(resource_path('views/filament/components/industry-context-prompt-preview.blade.php'));
    }

    public function test_direct_clipboard_helper_has_http_fallback_and_notifications(): void
    {
        $helper = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Support/IndustryContextClipboard.php');
        $browser = file_get_contents(resource_path('views/filament/components/industry-context-clipboard-script.blade.php'));
        self::assertStringContainsString('base64_encode($prompt)', (string) $helper);
        self::assertStringContainsString('base64_encode($message)', (string) $helper);
        self::assertStringContainsString('copyIndustryContextPromptFromBase64', (string) $browser);
        self::assertStringContainsString('notifyIndustryContextClipboardWarningFromBase64', (string) $browser);
        self::assertStringContainsString("'data-industry-context-action' => 'copy'", (string) $helper);
        self::assertStringContainsString("'data-industry-context-action' => 'warning'", (string) $helper);
        self::assertStringContainsString("'data-industry-context-payload'", (string) $helper);
        self::assertStringNotContainsString('Js::from', (string) $helper);
        self::assertStringContainsString('new TextDecoder().decode(bytes)', (string) $browser);
        self::assertStringContainsString("document.addEventListener('click'", (string) $browser);
        self::assertStringContainsString("event.target.closest('[data-industry-context-action]')", (string) $browser);
        self::assertStringContainsString('event.stopImmediatePropagation()', (string) $browser);
        self::assertStringContainsString('trigger.dataset.industryContextPayload', (string) $browser);
        self::assertStringContainsString('navigator.clipboard.writeText(text)', (string) $browser);
        self::assertStringContainsString("document.execCommand('copy')", (string) $browser);
        self::assertStringContainsString("document.createElement('textarea')", (string) $browser);
        self::assertStringContainsString('textarea.remove()', (string) $browser);
        self::assertStringContainsString('Đã copy Prompt', (string) $browser);
        self::assertStringContainsString('Không thể copy Prompt', (string) $browser);
        self::assertStringNotContainsString('undefined', (string) $browser);

        $warning = IndustryContextClipboard::warningAttributes('Vui lòng nhập tên Ngữ cảnh ngành trước.');
        self::assertSame(base64_encode('Vui lòng nhập tên Ngữ cảnh ngành trước.'), $warning['data-industry-context-payload']);
        self::assertSame('warning', $warning['data-industry-context-action']);
        self::assertArrayNotHasKey('onclick', $warning);
        self::assertArrayNotHasKey('x-on:click', $warning);
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
        $copyStart = strpos((string) $page, "Action::make('copy_prompt')");
        $quickStart = strpos((string) $page, "Action::make('quick_generate')");
        self::assertIsInt($copyStart);
        self::assertIsInt($quickStart);
        $copyBlock = substr((string) $page, $copyStart, $quickStart - $copyStart);
        self::assertStringContainsString('$this->copyPromptAttributes()', $copyBlock);
        self::assertStringNotContainsString('context_json', $copyBlock);
        self::assertStringNotContainsString('->generate(', $copyBlock);
        self::assertStringNotContainsString('modal', strtolower($copyBlock));
        self::assertStringContainsString('->compilePrompt(', (string) $page);
        self::assertStringContainsString('IndustryContextClipboard::copyAttributes($prompt)', (string) $page);
    }

    public function test_context_json_uses_editable_json_code_surface_with_existing_validation_contract(): void
    {
        $resource = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource.php');
        $editor = file_get_contents(resource_path('views/filament/forms/components/json-code-editor.blade.php'));

        self::assertStringContainsString("JsonCodeEditor::make('context_json')", (string) $resource);
        self::assertStringNotContainsString("Textarea::make('context_json')", (string) $resource);
        self::assertStringContainsString('JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES', (string) $resource);
        self::assertStringContainsString('IndustryContextSchema::validate($decoded)', (string) $resource);
        self::assertStringContainsString('->dehydrateStateUsing(fn (string $state): array => json_decode', (string) $resource);
        self::assertStringContainsString('wire:model="{{ $statePath }}"', (string) $editor);
        self::assertStringContainsString('JSON.stringify(JSON.parse', (string) $editor);
        self::assertStringContainsString('spellcheck="false"', (string) $editor);
        self::assertStringContainsString('font-mono', (string) $editor);
        self::assertStringContainsString('overflow-auto', (string) $editor);
    }
}
