<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\ServiceWidgetSessionController;
use App\Services\H1\Exceptions\H1TransportException;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Protocol\SignedHttpTransport;
use App\Services\H1\Widget\WidgetTokenExchangeClient;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
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
    public function session_uses_enrolled_installation_and_returns_widget_token_only(): void
    {
        $this->actingAs(new GenericUser(['id' => 1, 'name' => 'Admin']));

        $controller = new ServiceWidgetSessionController(
            $this->fakeExchangeClient(static fn (): array => [
                'access_token' => 'widget.token.value',
                'expires_at' => '2026-10-23T16:00:00.000Z',
                'installation_id' => 'ins_widget_session_01',
                'scopes' => ['widget:status', 'widget:renew'],
                'api_base_url' => 'https://ops.example.test',
                'bundle_url' => 'https://seo-ops.test/vendor/ops-service-widget/ops-service-widget.js',
                'api_timeout_ms' => 5000,
            ])
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
        $this->actingAs(new GenericUser(['id' => 1, 'name' => 'Admin']));

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
        $this->actingAs(new GenericUser(['id' => 1, 'name' => 'Admin']));

        $controller = new ServiceWidgetSessionController(
            $this->fakeExchangeClient(static function (): array {
                throw new H1TransportException('not_enrolled', 'Installation is not enrolled.');
            })
        );

        $response = $controller->store(Request::create('/api/service-widget/session', 'POST'));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('not_enrolled', $response->getData(true)['error']['code'] ?? null);
    }
}
