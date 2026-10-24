<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\ServiceWidgetSessionController;
use App\Http\Middleware\EnsureApplicationInstalled;
use App\Models\User;
use App\Policies\ServiceWidgetPolicy;
use App\Services\H1\Exceptions\H1TransportException;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Protocol\SignedHttpTransport;
use App\Services\H1\Widget\WidgetTokenExchangeClient;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ServiceWidgetSessionTest extends TestCase
{
    private function fakeExchangeClient(callable $exchange): WidgetTokenExchangeClient
    {
        return new class(
            new InstallationStateRepository,
            new SignedHttpTransport,
            $exchange
        ) extends WidgetTokenExchangeClient
        {
            public function __construct(
                InstallationStateRepository $installations,
                SignedHttpTransport $http,
                private readonly \Closure $handler,
            ) {
                parent::__construct($installations, $http);
            }

            public function exchange(): array
            {
                return ($this->handler)();
            }
        };
    }

    private function userWithRole(string $role): User
    {
        $user = new User;
        $user->id = 1;
        $user->status = User::STATUS_NORMAL;
        $user->role = $role;

        return $user;
    }

    private function successfulSessionPayload(): array
    {
        return [
            'access_token' => 'widget.token.value',
            'expires_at' => '2026-10-23T16:00:00.000Z',
            'installation_id' => 'ins_widget_session_01',
            'scopes' => ['widget:status', 'widget:renew'],
            'api_base_url' => 'https://ops.example.test',
            'bundle_url' => 'https://seo-ops.test/vendor/ops-service-widget/ops-service-widget.js',
            'api_timeout_ms' => 5000,
        ];
    }

    #[Test]
    public function guests_cannot_create_widget_session_via_http(): void
    {
        $response = $this->postJson(route('service-widget.session'));
        self::assertTrue(
            in_array($response->status(), [401, 302, 307], true),
            'Unauthenticated session must be rejected (got '.$response->status().')'
        );
    }

    #[Test]
    public function owner_can_create_widget_session_via_http(): void
    {
        $this->withoutMiddleware(EnsureApplicationInstalled::class);
        $this->app->instance(
            WidgetTokenExchangeClient::class,
            $this->fakeExchangeClient(fn (): array => $this->successfulSessionPayload())
        );

        $this->actingAs($this->userWithRole(User::ROLE_OWNER));

        $response = $this->postJson(route('service-widget.session'));
        $response->assertOk();
        $response->assertJsonPath('installationId', 'ins_widget_session_01');
        $response->assertJsonPath('accessToken', 'widget.token.value');
        $response->assertJsonPath('useMock', false);
        self::assertArrayNotHasKey('secret', $response->json());
    }

    #[Test]
    #[DataProvider('nonOwnerRolesProvider')]
    public function non_owner_roles_receive_forbidden_via_http(string $role): void
    {
        $this->withoutMiddleware(EnsureApplicationInstalled::class);

        $exchangeCalled = false;
        $this->app->instance(
            WidgetTokenExchangeClient::class,
            $this->fakeExchangeClient(static function () use (&$exchangeCalled): array {
                $exchangeCalled = true;

                return [];
            })
        );

        $this->actingAs($this->userWithRole($role));

        $response = $this->postJson(route('service-widget.session'));
        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'forbidden');
        self::assertFalse($exchangeCalled, 'Widget token must not be minted for non-Owner');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonOwnerRolesProvider(): array
    {
        return [
            'staff' => [User::ROLE_STAFF],
            'manager' => [User::ROLE_MANAGER],
            'admin' => [User::ROLE_ADMIN],
        ];
    }

    #[Test]
    public function session_uses_enrolled_installation_and_returns_widget_token_only(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_OWNER));

        $controller = new ServiceWidgetSessionController(
            $this->fakeExchangeClient(fn (): array => $this->successfulSessionPayload())
        );

        $response = $controller->store(Request::create('/api/service-widget/session', 'POST', [
            'installationId' => 'ins_attacker',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $json = $response->getData(true);
        self::assertSame('ins_widget_session_01', $json['installationId']);
        self::assertSame('widget.token.value', $json['accessToken']);
        self::assertFalse($json['useMock']);
        self::assertArrayNotHasKey('secret', $json);
        self::assertArrayNotHasKey('credential', $json);
        self::assertStringNotContainsString('secret', strtolower((string) json_encode($json)));
    }

    #[Test]
    public function offline_ops_server_returns_retryable_error(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_OWNER));

        $controller = new ServiceWidgetSessionController(
            $this->fakeExchangeClient(static function (): array {
                throw new H1TransportException('server_offline', 'OPS-SERVER license endpoint is unreachable.');
            })
        );

        $response = $controller->store(Request::create('/api/service-widget/session', 'POST'));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('server_offline', $response->getData(true)['error']['code'] ?? null);
    }

    #[Test]
    public function not_enrolled_installation_is_rejected(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_OWNER));

        $controller = new ServiceWidgetSessionController(
            $this->fakeExchangeClient(static function (): array {
                throw new H1TransportException('not_enrolled', 'Installation is not enrolled.');
            })
        );

        $response = $controller->store(Request::create('/api/service-widget/session', 'POST'));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('not_enrolled', $response->getData(true)['error']['code'] ?? null);
    }

    #[Test]
    public function header_and_controller_share_the_same_policy_entry(): void
    {
        $provider = (string) file_get_contents(app_path('Core/ClientCoreServiceProvider.php'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/ServiceWidgetSessionController.php'));

        self::assertStringContainsString('ServiceWidgetPolicy::allows(auth()->user())', $provider);
        self::assertStringContainsString('ServiceWidgetPolicy::allows(auth()->user())', $controller);

        $owner = $this->userWithRole(User::ROLE_OWNER);
        $staff = $this->userWithRole(User::ROLE_STAFF);
        self::assertTrue(ServiceWidgetPolicy::allows($owner));
        self::assertFalse(ServiceWidgetPolicy::allows($staff));
    }
}
