<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\IndustryContextProfileResource;
use App\IndustryContext\IndustryMarketOptions;
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

    public function test_create_and_existing_copy_actions_use_restored_direct_clipboard_path(): void
    {
        $resource = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource.php');
        $create = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource/Pages/CreateIndustryContextProfile.php');
        $edit = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource/Pages/EditIndustryContextProfile.php');

        foreach ([$resource, $create, $edit] as $source) {
            self::assertStringContainsString('->compilePrompt', (string) $source);
            self::assertStringContainsString('IndustryContextClipboard::copyAttributes($prompt)', (string) $source);
            self::assertStringNotContainsString('industry-context-prompt-preview', (string) $source);
            self::assertStringNotContainsString('navigator.clipboard.writeText', (string) $source);
        }
        self::assertFileDoesNotExist(resource_path('views/filament/components/industry-context-prompt-preview.blade.php'));
    }

    public function test_direct_clipboard_helper_has_http_fallback_and_notifications(): void
    {
        $helper = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Support/IndustryContextClipboard.php');
        $browser = file_get_contents(resource_path('views/filament/components/industry-context-clipboard-script.blade.php'));
        self::assertStringContainsString('navigator.clipboard.writeText(text)', (string) $browser);
        self::assertStringContainsString("document.execCommand('copy')", (string) $browser);
        self::assertStringContainsString("document.createElement('textarea')", (string) $browser);
        self::assertStringContainsString('textarea.remove()', (string) $browser);
        self::assertStringContainsString('Đã copy Prompt', (string) $browser);
        self::assertStringContainsString('Không thể copy Prompt', (string) $browser);
        self::assertStringNotContainsString('undefined', (string) $browser);
        self::assertStringContainsString("'data-industry-context-payload' => base64_encode(\$prompt)", (string) $helper);
        self::assertStringContainsString("event.target.closest('[data-industry-context-action]')", (string) $browser);
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
        self::assertStringNotContainsString('->generate(', $copyBlock);
        self::assertStringContainsString('->compilePromptForType(', (string) $page);
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
        self::assertStringContainsString('wire:model.live.debounce.300ms="{{ $statePath }}"', (string) $editor);
        self::assertStringContainsString('JSON.stringify(JSON.parse', (string) $editor);
        self::assertStringContainsString('spellcheck="false"', (string) $editor);
        self::assertStringContainsString('font-mono', (string) $editor);
        self::assertStringContainsString('overflow-auto', (string) $editor);
        self::assertStringContainsString('JSON không hợp lệ', (string) $editor);
        self::assertStringContainsString('errorLocation(error, raw)', (string) $editor);
        self::assertStringContainsString('this.$wire.validateIndustryContextJson()', (string) $editor);
        self::assertStringContainsString('escapeHtml(value)', (string) $editor);
        self::assertStringContainsString("replaceAll('&', '&amp;')", (string) $editor);
        self::assertStringContainsString('x-html="highlight(formatted)"', (string) $editor);
    }

    public function test_generation_ui_has_default_only_language_and_market_selects(): void
    {
        $resource = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource.php');
        $page = file_get_contents(dirname(__DIR__, 2).'/app/Filament/Resources/IndustryContextProfileResource/Pages/CreateIndustryContextProfile.php');

        self::assertStringContainsString("Select::make('language')", (string) $resource);
        self::assertStringContainsString("['vi' => 'Tiếng Việt', 'en' => 'English']", (string) $resource);
        self::assertSame('vi', IndustryContextProfileResource::defaultLanguage());
        self::assertSame('en', IndustryContextProfileResource::defaultLanguage('en'));
        self::assertStringContainsString("Select::make('prompt_type')", (string) $resource);
        self::assertStringContainsString("'core' => 'Core'", (string) $resource);
        self::assertStringContainsString("'discovery' => 'Discovery & Attention'", (string) $resource);
        self::assertStringContainsString("'breakout' => 'Breakout'", (string) $resource);
        self::assertStringContainsString("Select::make('market')", (string) $resource);
        self::assertStringContainsString("->label('Thị trường mục tiêu')", (string) $resource);
        self::assertStringContainsString('IndustryMarketOptions::options()', (string) $resource);
        self::assertSame('VN', IndustryMarketOptions::default(null, 'vi'));
        self::assertSame('US', IndustryMarketOptions::default('US', 'vi'));
        self::assertNull(IndustryMarketOptions::default(null, 'en'));
        self::assertStringContainsString('->dehydrated(false)', (string) $resource);
        self::assertStringContainsString("['prompt_type'] !== 'core'", (string) $page);
        self::assertStringContainsString('Vui lòng tạo hoặc nhập Core Industry Context hợp lệ trước.', (string) $page);
        self::assertStringContainsString('$this->generationPreviewJson', (string) $page);
        self::assertStringContainsString("Select::make('expiry_preset')", (string) $resource);
        self::assertStringContainsString("'6_months'", (string) $resource);
    }

    public function test_expiry_is_revision_metadata_with_presets_and_visible_status(): void
    {
        $model = file_get_contents(dirname(__DIR__, 2).'/app/Models/IndustryContextProfile.php');
        $manager = file_get_contents(dirname(__DIR__, 2).'/app/IndustryContext/IndustryContextProfileManager.php');
        $migration = file_get_contents(database_path('migrations/2026_10_01_120000_add_expires_at_to_industry_context_profiles_table.php'));
        $view = file_get_contents(resource_path('views/filament/resources/industry-context-profile-resource/pages/view-industry-context-profile.blade.php'));

        self::assertStringContainsString("'expires_at'", (string) $model);
        self::assertStringContainsString("'expires_at' => 'datetime'", (string) $model);
        self::assertStringContainsString("timestamp('expires_at')->nullable()", (string) $migration);
        self::assertStringContainsString("'expires_at' => \$expiresAt", (string) $manager);
        self::assertStringContainsString('IndustryContextExpiry::label', (string) $view);
        self::assertStringContainsString('Hạn sử dụng', (string) $view);
        self::assertStringNotContainsString("['expires_at']", (string) file_get_contents(resource_path('schemas/industry-context.v1.schema.json')));
    }
}
