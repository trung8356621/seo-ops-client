<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Filament\Pages\ServiceConfigure;
use App\Models\Service;
use App\Models\ServiceApiCredential;
use App\Models\User;
use App\Services\ServiceIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\Api\UsesServiceApiCredentialSchema;

/**
 * Admin Create API Key form defaults / expires UX — no auth plane changes.
 */
final class ServiceApiCredentialCreateFormTest extends TestCase
{
    use UsesServiceApiCredentialSchema;

    private User $owner;

    private Service $seo;

    private Service $seeding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootServiceApiCredentialSchema();

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->string('role')->default(User::ROLE_OWNER);
                $table->string('status')->default(User::STATUS_NORMAL);
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->timestamps();
            });
        }

        $this->owner = User::query()->create([
            'name' => 'Owner API Cred',
            'email' => 'owner-api-cred-'.uniqid('', true).'@example.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $this->seo = Service::query()->create([
            'name' => 'SEO',
            'slug' => 'seo-content-ai',
            'addon_namespace' => 'App\\Addons\\SeoContentAi\\SeoContentAiServiceProvider',
            'db_connection' => 'omi_seo_ai',
            'is_active' => true,
            'config' => [],
            'service_key' => 'provisioned-internal-key',
        ]);

        $this->seeding = Service::query()->create([
            'name' => 'Seeding',
            'slug' => 'seeding',
            'addon_namespace' => 'App\\Addons\\Seeding\\SeedingServiceProvider',
            'db_connection' => 'omi_seeding',
            'is_active' => true,
            'config' => [],
            'service_key' => 'provisioned-seeding-key',
        ]);
    }

    public function test_seo_default_scopes_include_agent_set_without_wildcard(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(ServiceConfigure::class, ['service' => ServiceIdentity::PUBLIC_SEO]);
        $defaults = $component->instance()->defaultApiCredentialScopes();

        self::assertSame(
            ['service:read', 'seo:read', 'content-projects:draft:write'],
            $defaults,
        );
        self::assertNotContains('*', $defaults);
    }

    public function test_unrelated_service_default_scopes_remain_service_read_only(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(ServiceConfigure::class, ['service' => ServiceIdentity::PUBLIC_SEEDING]);
        $defaults = $component->instance()->defaultApiCredentialScopes();

        self::assertSame(['service:read'], $defaults);
        self::assertNotContains('seo:read', $defaults);
        self::assertNotContains('content-projects:draft:write', $defaults);
        self::assertNotContains('*', $defaults);
    }

    public function test_empty_expires_persists_as_null(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ServiceConfigure::class, ['service' => ServiceIdentity::PUBLIC_SEO])
            ->call('createApiCredential', [
                'name' => 'No Expiry Key',
                'scopes' => ['service:read'],
                'expires_at' => null,
            ])
            ->assertSet('revealedApiKeyName', 'No Expiry Key');

        $credential = ServiceApiCredential::query()
            ->where('service_id', $this->seo->id)
            ->where('name', 'No Expiry Key')
            ->first();

        self::assertInstanceOf(ServiceApiCredential::class, $credential);
        self::assertNull($credential->expires_at);
        self::assertNull(
            DB::table('service_api_credentials')->where('id', $credential->id)->value('expires_at'),
        );
    }

    public function test_empty_string_expires_persists_as_null(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ServiceConfigure::class, ['service' => ServiceIdentity::PUBLIC_SEO])
            ->call('createApiCredential', [
                'name' => 'Blank Expiry',
                'scopes' => ['service:read'],
                'expires_at' => '',
            ]);

        $credential = ServiceApiCredential::query()
            ->where('name', 'Blank Expiry')
            ->first();

        self::assertInstanceOf(ServiceApiCredential::class, $credential);
        self::assertNull($credential->expires_at);
    }

    public function test_custom_scopes_override_defaults(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ServiceConfigure::class, ['service' => ServiceIdentity::PUBLIC_SEO])
            ->call('createApiCredential', [
                'name' => 'Custom Scopes',
                'scopes' => ['service:read', 'mcp:read'],
                'expires_at' => null,
            ]);

        $credential = ServiceApiCredential::query()
            ->where('name', 'Custom Scopes')
            ->first();

        self::assertInstanceOf(ServiceApiCredential::class, $credential);
        self::assertEqualsCanonicalizing(['service:read', 'mcp:read'], $credential->scopeList());
        self::assertTrue($credential->hasScope('service:read'));
        self::assertTrue($credential->hasScope('mcp:read'));
        self::assertFalse($credential->hasScope('content-projects:draft:write'));
        self::assertNotContains('*', $credential->scopeList());
    }

    public function test_existing_credentials_unaffected_by_form_defaults(): void
    {
        $this->actingAs($this->owner);

        $existing = ServiceApiCredential::query()->create([
            'service_id' => $this->seo->id,
            'name' => 'Legacy Key',
            'key_prefix' => 'svc_live_legacy',
            'key_hash' => hash('sha256', 'legacy-hash-placeholder'),
            'scopes' => ['service:read'],
            'expires_at' => null,
            'revoked_at' => null,
            'created_by' => $this->owner->id,
        ]);

        $component = Livewire::test(ServiceConfigure::class, ['service' => ServiceIdentity::PUBLIC_SEO]);
        self::assertSame(
            ['service:read', 'seo:read', 'content-projects:draft:write'],
            $component->instance()->defaultApiCredentialScopes(),
        );

        $fresh = $existing->fresh();
        self::assertInstanceOf(ServiceApiCredential::class, $fresh);
        self::assertSame(['service:read'], $fresh->scopeList());
        self::assertSame('Legacy Key', $fresh->name);
    }

    public function test_raw_key_revealed_once_then_dismissed(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(ServiceConfigure::class, ['service' => ServiceIdentity::PUBLIC_SEO])
            ->call('createApiCredential', [
                'name' => 'One Time',
                'scopes' => $this->seoDefaultsViaPage(),
                'expires_at' => null,
            ]);

        $raw = $component->get('revealedApiKey');
        self::assertIsString($raw);
        self::assertStringStartsWith('svc_live_', $raw);

        $row = DB::table('service_api_credentials')->where('name', 'One Time')->first();
        self::assertNotNull($row);
        self::assertNotSame($raw, $row->key_hash);
        self::assertStringNotContainsString($raw, (string) json_encode($row));

        $component->call('dismissRevealedApiKey')
            ->assertSet('revealedApiKey', null)
            ->assertSet('revealedApiKeyName', null);
    }

    public function test_expires_and_scope_i18n_helpers_exist(): void
    {
        self::assertSame(
            'Optional. Leave empty for no expiration.',
            trans('site-service.api_access_expires_helper', [], 'en'),
        );
        self::assertSame(
            'Không bắt buộc. Để trống = không hết hạn.',
            trans('site-service.api_access_expires_helper', [], 'vi'),
        );
        self::assertStringContainsString('seo:read', trans('site-service.api_access_scopes_helper_seo', [], 'en'));
        self::assertStringContainsString(
            'content-projects:draft:write',
            trans('site-service.api_access_scopes_helper_seo', [], 'en'),
        );
        self::assertStringContainsString(
            'will not be shown again',
            strtolower(trans('site-service.api_access_copy_now_body', ['name' => 'x'], 'en')),
        );
    }

    public function test_seo_suggestions_include_known_scopes_and_wildcard_as_option_only(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(ServiceConfigure::class, ['service' => ServiceIdentity::PUBLIC_SEO]);
        $suggestions = $component->instance()->suggestedApiCredentialScopes();

        self::assertContains('service:read', $suggestions);
        self::assertContains('seo:read', $suggestions);
        self::assertContains('content-projects:draft:write', $suggestions);
        self::assertContains('*', $suggestions);
        self::assertNotContains('*', $component->instance()->defaultApiCredentialScopes());
    }

    /**
     * @return list<string>
     */
    private function seoDefaultsViaPage(): array
    {
        $page = new ServiceConfigure;
        $page->service = ServiceIdentity::PUBLIC_SEO;

        return $page->defaultApiCredentialScopes();
    }
}
