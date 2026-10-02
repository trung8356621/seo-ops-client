<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\IndustryContextProfileResource;
use App\Filament\Support\ValidatesIndustryContextJson;
use App\Http\Controllers\Admin\IndustryContextPromptDownloadController;
use App\IndustryContext\IndustryContextSchema;
use App\IndustryContext\IndustryMarketOptions;
use App\Models\User;
use ReflectionMethod;
use Tests\TestCase;

final class IndustryContextAdminResourceContractTest extends TestCase
{
    public function test_create_only_creates_a_new_core_context(): void
    {
        $resource = $this->source('app/Filament/Resources/IndustryContextProfileResource.php');
        $create = $this->source('app/Filament/Resources/IndustryContextProfileResource/Pages/CreateIndustryContextProfile.php');

        self::assertStringNotContainsString("make('prompt_type')", $resource);
        self::assertStringNotContainsString("make('group_selector')", $resource);
        self::assertStringNotContainsString("make('type')", $resource);
        self::assertStringContainsString('->readOnly()->dehydrated()', $resource);
        self::assertStringContainsString('afterStateUpdated', $resource);
        self::assertStringContainsString("Action::make('generate_context')->label('Gen Core')", $create);
        self::assertStringContainsString('->generateForType(', $create);
        self::assertStringContainsString("Action::make('download_prompt')->label('T\u{1EA3}i Prompt')", $create);
        self::assertStringContainsString("route('admin.industry-context.prompt.create'", $create);
        self::assertStringContainsString('createInitial(', $create);
        self::assertStringNotContainsString('createRevision(', $create);
        self::assertStringNotContainsString('createAuxiliaryRevision(', $create);
        foreach (["make('language')", "make('market')", "make('expiry_preset')", "make('notes')"] as $field) {
            self::assertStringContainsString($field, $resource);
        }
        self::assertSame(['vi' => 'Tiếng Việt', 'en' => 'English'], IndustryContextProfileResource::languageOptions());
        self::assertSame('VN', IndustryMarketOptions::default(null, 'vi'));
    }

    public function test_create_slug_and_schema_are_fixed_to_core(): void
    {
        $resource = $this->source('app/Filament/Resources/IndustryContextProfileResource.php');

        self::assertStringContainsString("\$set('key', self::keyFromName((string) \$state))", $resource);
        self::assertStringContainsString("make('key')->label('Key (slug)')", $resource);
        self::assertGreaterThanOrEqual(2, substr_count($resource, '->columnSpanFull()'));
        self::assertStringContainsString('$record?->type ?? IndustryContextProfile::TYPE_CORE', $resource);
        self::assertStringContainsString('IndustryAuxiliarySchema::validatedOutput($type, $decoded)', $resource);
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

        foreach (['TYPE_CORE', 'TYPE_DISCOVERY', 'TYPE_BREAKOUT', 'TYPE_MATCH'] as $type) {
            self::assertStringContainsString($type, $page);
        }
        self::assertStringContainsString('public string $selectedType = IndustryContextProfile::TYPE_CORE', $page);
        self::assertStringContainsString('->active(', $page);
        self::assertStringContainsString('->revisions(', $page);
        self::assertStringContainsString('->isStale(', $page);
        self::assertStringContainsString('->activate(', $page);
        self::assertStringContainsString('createRevision(', $page);
        self::assertStringContainsString('createAuxiliaryRevision(', $page);
        self::assertStringContainsString('saveManualRevision', $page);
        self::assertStringContainsString('handleExpiryPresetUpdated', $page);
        self::assertStringContainsString('handleExpiresAtCustomUpdated', $page);
        self::assertStringContainsString('canonicalTemplate', $page);
        self::assertStringContainsString("->label('Lưu')", $page);
        self::assertStringContainsString('resolveBranchRevision', $page);
        self::assertStringContainsString('revisionUrl', $page);
        self::assertStringContainsString('resolveSelectedType', $page);
        self::assertStringContainsString('tabUrl', $page);
        self::assertStringNotContainsString('selectType', $page);
        self::assertStringNotContainsString("Action::make('download_prompt')", $page);
        self::assertStringContainsString("route('admin.industry-context.prompt.download'", $view);
        self::assertStringContainsString("route('admin.industry-context.json.download'", $view);
        self::assertStringContainsString('$this->tabUrl($type)', $view);
        self::assertStringContainsString('$this->revisionUrl($selectedType', $view);
        self::assertStringNotContainsString('wire:click="selectType', $view);
        self::assertStringContainsString('saveManualRevision', $view);
        self::assertStringContainsString('Tải Prompt tạo JSON', $view);
        self::assertStringContainsString('Tải JSON', $view);
        self::assertStringContainsString('<x-filament::button disabled', $view);
        self::assertStringContainsString('Chưa có {{ $tabs[$selectedType] }} context.', $view);
        self::assertStringNotContainsString('History (latest 3)', $view);
        self::assertStringNotContainsString('Inspect JSON', $view);
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
        self::assertStringContainsString("@vite(['resources/js/admin/code-editor/index.js'], 'build-code-editor')", $editor);
        self::assertStringContainsString('data-language="json"', $editor);
        self::assertStringContainsString('data-code-editor-format', $editor);
        self::assertStringContainsString('$wire.entangle(@js($statePath))', $editor);
        self::assertStringNotContainsString('<textarea', $editor);
        self::assertStringNotContainsString('<pre', $editor);
        self::assertStringNotContainsString('highlight(', $editor);
        self::assertStringContainsString('wire:key="industry-context-json-editor-{{ $schemaType }}"', $editor);
    }

    public function test_match_json_uses_match_schema_and_not_core_schema(): void
    {
        $entity = ['canonical' => 'implant', 'aliases' => ['dental implant']];
        $payload = ['schema_version' => '1.0',
            'taxonomy' => array_fill_keys(['products', 'product_families', 'materials', 'services', 'audiences', 'use_cases', 'features', 'adjacent_products'], [$entity]),
            'topic_rules' => ['generic_cores' => [$entity], 'service_intent_terms' => [$entity]],
            'aliases' => [], 'ambiguities' => []];
        $validator = new class
        {
            use ValidatesIndustryContextJson;
        };

        self::assertTrue($validator->validateIndustryContextJson(json_encode($payload, JSON_THROW_ON_ERROR), 'match')['valid']);
        self::assertNotSame([], IndustryContextSchema::validate($payload));
    }

    public function test_create_uses_core_expiry_default(): void
    {
        $resource = $this->source('app/Filament/Resources/IndustryContextProfileResource.php');

        self::assertStringContainsString("? '6_months'", $resource);
        self::assertStringNotContainsString("make('expiry_defaulted_for_match')", $resource);
    }

    public function test_name_slug_contract_and_download_transport_keep_prompts_out_of_dom(): void
    {
        self::assertSame('may-balo-tui-xach', IndustryContextProfileResource::keyFromName("May balo & t\u{00FA}i x\u{00E1}ch"));
        self::assertSame('xuong-may-balo', IndustryContextProfileResource::keyFromName("  -- X\u{01B0}\u{1EDF}ng   may / balo !!! "));
        self::assertSame('tui-xach-balo', IndustryContextProfileResource::keyFromName("T\u{00FA}i x\u{00E1}ch---balo"));

        self::assertFileDoesNotExist(app_path('Filament/Support/IndustryContextClipboard.php'));
        self::assertFileDoesNotExist(resource_path('views/filament/components/industry-context-clipboard-script.blade.php'));
        $provider = $this->source('app/Providers/Filament/AdminPanelProvider.php');
        self::assertStringNotContainsString('industry-context-clipboard-script', $provider);
        self::assertStringNotContainsString('base64_encode($prompt)', $this->source('app/Filament/Resources/IndustryContextProfileResource/Pages/CreateIndustryContextProfile.php'));
    }

    public function test_download_routes_are_authorized_streamed_and_type_aware(): void
    {
        $controller = $this->source('app/Http/Controllers/Admin/IndustryContextPromptDownloadController.php');
        $routes = $this->source('routes/web.php');

        self::assertStringContainsString("middleware(['web', 'auth'])", $routes);
        self::assertStringContainsString('/admin/industry-context-prompts/create', $routes);
        self::assertStringContainsString('/admin/industry-context-profiles/{key}/prompt/{type}', $routes);
        self::assertStringContainsString('/admin/industry-context-profiles/{profile}/json', $routes);
        self::assertStringContainsString("->where('type', 'core|discovery|breakout|match')", $routes);
        self::assertStringContainsString('User::ROLE_OWNER', $controller);
        self::assertStringContainsString('User::ROLE_ADMIN', $controller);
        self::assertStringContainsString('->active($key, IndustryContextProfile::TYPE_CORE)', $controller);
        self::assertStringContainsString('compilePromptForType(', $controller);
        self::assertStringContainsString('$type === IndustryContextProfile::TYPE_CORE ? null : (array) $core->context_json', $controller);
        self::assertStringContainsString('abort_if($core === null, 422', $controller);
        self::assertStringContainsString("['Content-Type' => 'text/plain; charset=UTF-8']", $controller);
        self::assertStringContainsString('streamDownload(', $controller);
        self::assertStringContainsString("\$key.'-'.\$type.'-prompt.txt'", $controller);
        self::assertStringContainsString("\$profile->key.'-'.\$profile->type.'.json'", $controller);
        self::assertStringContainsString("['Content-Type' => 'application/json; charset=UTF-8']", $controller);
        self::assertStringNotContainsString('Storage::', $controller);
        self::assertStringNotContainsString('->generateForType(', $controller);
    }

    public function test_download_response_is_a_utf8_text_attachment_without_a_temp_file(): void
    {
        $method = new ReflectionMethod(IndustryContextPromptDownloadController::class, 'download');
        $response = $method->invoke(new IndustryContextPromptDownloadController, 'compiled prompt', 'bags-core-prompt.txt');

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        self::assertSame('compiled prompt', $content);
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('bags-core-prompt.txt', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_download_routes_require_login_and_admin_role(): void
    {
        $this->get(route('admin.industry-context.prompt.create'))->assertRedirect('/login');

        $staff = (new User)->forceFill(['id' => 999, 'role' => User::ROLE_STAFF]);
        $this->actingAs($staff)
            ->get(route('admin.industry-context.prompt.create', ['name' => 'Bags']))
            ->assertForbidden();
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }
}
