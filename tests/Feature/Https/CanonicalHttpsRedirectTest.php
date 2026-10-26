<?php

declare(strict_types=1);

namespace Tests\Feature\Https;

use App\Core\Installation\InstallationState;
use App\Core\Installation\InstallationStateManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class CanonicalHttpsRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $state = new InstallationStateManager(storage_path('framework/testing/installer-https'));
        $state->setStatusForTesting(InstallationState::INSTALLED);
        $this->app->instance(InstallationStateManager::class, $state);
    }

    public function test_http_login_redirects_to_canonical_https_when_enabled(): void
    {
        $this->enableCanonicalHttps('https://seo-ops.test');

        $this->get('http://seo-ops.test/login')
            ->assertRedirect('https://seo-ops.test/login')
            ->assertStatus(301);
    }

    public function test_http_admin_redirects_to_canonical_https_when_enabled(): void
    {
        $this->enableCanonicalHttps('https://seo-ops.test');

        $this->get('http://seo-ops.test/admin')
            ->assertRedirect('https://seo-ops.test/admin')
            ->assertStatus(301);
    }

    public function test_https_request_is_not_redirected_again(): void
    {
        $this->enableCanonicalHttps('https://seo-ops.test');

        $this->get('https://seo-ops.test/login')
            ->assertOk()
            ->assertHeader('Cache-Control');
    }

    public function test_path_and_query_are_preserved(): void
    {
        $this->enableCanonicalHttps('https://seo-ops.test');

        $this->get('http://seo-ops.test/admin/services/social-automation?tab=keys&x=1')
            ->assertRedirect('https://seo-ops.test/admin/services/social-automation?tab=keys&x=1');
    }

    public function test_api_routes_are_not_redirected(): void
    {
        $this->enableCanonicalHttps('https://seo-ops.test');

        // Machine/API callers must not receive surprise browser redirects.
        $response = $this->get('http://seo-ops.test/api/v1/social-automation/client/validate');
        self::assertNotSame(301, $response->getStatusCode());
        self::assertNotSame(308, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
    }

    public function test_http_only_deployment_does_not_redirect(): void
    {
        Config::set('app.url', 'http://seo-ops.test');
        Config::set('app.force_https', false);
        URL::forceScheme('http');

        $this->get('http://seo-ops.test/login')->assertOk();
    }

    public function test_redirect_uses_app_url_host_not_request_host_header(): void
    {
        $this->enableCanonicalHttps('https://seo-ops.test');

        $this->get('http://evil.example/login', [
            'Host' => 'evil.example',
        ])->assertRedirect('https://seo-ops.test/login');
    }

    public function test_generated_urls_use_https_when_forced(): void
    {
        $this->enableCanonicalHttps('https://seo-ops.test');

        self::assertSame('https://seo-ops.test/login', url('/login'));
        self::assertSame('https://seo-ops.test/login', route('login'));
    }

    public function test_forwarded_proto_https_does_not_loop(): void
    {
        $this->enableCanonicalHttps('https://seo-ops.test');

        $this->withServerVariables([
            'HTTPS' => 'off',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('http://seo-ops.test/login')
            ->assertOk();
    }

    private function enableCanonicalHttps(string $appUrl): void
    {
        Config::set('app.url', $appUrl);
        Config::set('app.force_https', true);
        URL::forceScheme('https');
        URL::forceRootUrl(rtrim($appUrl, '/'));
    }
}
