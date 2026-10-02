<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\IndustryContextProfileResource;
use App\IndustryContext\IndustryMarketOptions;
use Tests\TestCase;

final class IndustryContextAdminResourceContractTest extends TestCase
{
    public function test_create_is_core_only_and_preserves_generation_inputs(): void
    {
        $resource = $this->source('app/Filament/Resources/IndustryContextProfileResource.php');
        $create = $this->source('app/Filament/Resources/IndustryContextProfileResource/Pages/CreateIndustryContextProfile.php');

        self::assertStringNotContainsString("make('prompt_type')", $resource);
        self::assertStringContainsString("Action::make('generate_core')->label('Gen Core')", $create);
        self::assertStringContainsString('->generate(', $create);
        self::assertStringContainsString('->compilePrompt(', $create);
        self::assertStringContainsString('createInitial(', $create);
        self::assertStringNotContainsString('createAuxiliaryRevision', $create);
        foreach (["make('language')", "make('market')", "make('expiry_preset')", "make('notes')"] as $field) {
            self::assertStringContainsString($field, $resource);
        }
        self::assertSame(['vi' => 'Tiếng Việt', 'en' => 'English'], IndustryContextProfileResource::languageOptions());
        self::assertSame('VN', IndustryMarketOptions::default(null, 'vi'));
    }

    public function test_list_is_logical_and_only_exposes_edit_and_delete(): void
    {
        $resource = $this->source('app/Filament/Resources/IndustryContextProfileResource.php');

        self::assertStringContainsString('logicalRepresentatives()', $resource);
        self::assertStringContainsString('EditAction::make()', $resource);
        self::assertStringContainsString('DeleteAction::make()', $resource);
        self::assertStringNotContainsString('ViewAction::make()', $resource);
        self::assertStringNotContainsString("Action::make('copy_prompt')", $resource);
        self::assertStringNotContainsString("Action::make('quick_generate')", $resource);
    }

    public function test_edit_is_a_type_scoped_workspace_using_backend_lifecycle(): void
    {
        $page = $this->source('app/Filament/Resources/IndustryContextProfileResource/Pages/EditIndustryContextProfile.php');
        $view = $this->source('resources/views/filament/resources/industry-context-profile-resource/pages/edit-industry-context-profile.blade.php');

        foreach (['TYPE_CORE', 'TYPE_DISCOVERY', 'TYPE_BREAKOUT'] as $type) {
            self::assertStringContainsString($type, $page);
        }
        self::assertStringContainsString('public string $selectedType = IndustryContextProfile::TYPE_CORE', $page);
        self::assertStringContainsString('->active(', $page);
        self::assertStringContainsString('->revisions(', $page);
        self::assertStringContainsString('->isStale(', $page);
        self::assertStringContainsString('->activate(', $page);
        self::assertStringContainsString('createRevision(', $page);
        self::assertStringContainsString('createAuxiliaryRevision(', $page);
        self::assertStringContainsString('compilePromptForType(', $page);
        self::assertStringContainsString('IndustryContextClipboard::copyAttributes', $page);
        self::assertStringContainsString('Chưa có {{ $tabs[$selectedType] }} context.', $view);
        self::assertStringContainsString('History (latest 3)', $view);
        self::assertStringContainsString('Dùng bản này', $view);
        self::assertStringContainsString('Core đã thay đổi', $view);
        self::assertStringContainsString('IndustryContextExpiry::label', $view);
    }

    public function test_auxiliary_ids_resolve_and_old_view_redirects_to_core_workspace(): void
    {
        $resource = $this->source('app/Filament/Resources/IndustryContextProfileResource.php');
        $viewPage = $this->source('app/Filament/Resources/IndustryContextProfileResource/Pages/ViewIndustryContextProfile.php');

        self::assertStringContainsString('resolveRecordRouteBinding', $resource);
        self::assertStringContainsString('IndustryContextProfile::query()->find($key)', $resource);
        self::assertStringContainsString("getUrl('edit', ['record' => \$core])", $viewPage);
        self::assertFileDoesNotExist(resource_path('views/filament/resources/industry-context-profile-resource/pages/view-industry-context-profile.blade.php'));
    }

    public function test_json_editor_separates_syntax_and_schema_without_stale_state_race(): void
    {
        $resource = $this->source('app/Filament/Resources/IndustryContextProfileResource.php');
        $component = $this->source('app/Filament/Forms/Components/JsonCodeEditor.php');
        $validator = $this->source('app/Filament/Support/ValidatesIndustryContextJson.php');
        $editor = $this->source('resources/views/filament/forms/components/json-code-editor.blade.php');

        self::assertStringContainsString('->schemaType(', $resource);
        self::assertStringContainsString('IndustryAuxiliarySchema::validatedOutput', $resource);
        self::assertStringContainsString('getSchemaType()', $component);
        self::assertStringContainsString('IndustryContextSchema::validate', $validator);
        self::assertStringContainsString('IndustryAuxiliarySchema::validatedOutput', $validator);
        foreach (['syntax_error', 'syntax_validating_schema', 'schema_valid', 'schema_invalid'] as $state) {
            self::assertStringContainsString($state, $editor);
        }
        self::assertStringContainsString('validateIndustryContextJson(raw, this.schemaType)', $editor);
        self::assertStringContainsString('raw !== this.$refs.editor.value', $editor);
        self::assertStringNotContainsString('x-data="{', $editor);
        self::assertStringContainsString('Alpine.data(', $editor);
        self::assertStringContainsString('x-html="highlight(formatted)"', $editor);
    }

    public function test_prompt_copy_keeps_base64_delegated_clipboard_transport(): void
    {
        $helper = $this->source('app/Filament/Support/IndustryContextClipboard.php');
        $browser = $this->source('resources/views/filament/components/industry-context-clipboard-script.blade.php');

        self::assertStringContainsString("'data-industry-context-payload' => base64_encode(\$prompt)", $helper);
        self::assertStringContainsString("event.target.closest('[data-industry-context-action]')", $browser);
        self::assertStringContainsString('navigator.clipboard.writeText(text)', $browser);
        self::assertStringContainsString("document.execCommand('copy')", $browser);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }
}
