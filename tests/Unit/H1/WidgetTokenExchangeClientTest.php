<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WidgetTokenExchangeClientTest extends TestCase
{
    #[Test]
    public function h1_license_config_defines_widget_exchange_path_and_bundle(): void
    {
        $config = config('h1-license.widget');
        self::assertIsArray($config);
        self::assertSame(
            '/api/v1/installations/{installation_id}/widget/token',
            $config['path_template'] ?? null
        );
        self::assertStringContainsString('ops-service-widget.js', (string) ($config['bundle_url'] ?? ''));
        self::assertGreaterThanOrEqual(1000, (int) ($config['api_timeout_ms'] ?? 0));
    }

    #[Test]
    public function exchange_client_source_never_returns_h1_secret_fields(): void
    {
        $source = (string) file_get_contents(app_path('Services/H1/Widget/WidgetTokenExchangeClient.php'));
        self::assertStringContainsString('access_token', $source);
        self::assertStringContainsString('purpose', $source);
        self::assertStringContainsString('service_widget', $source);
        // Secret may be passed into SignedHttpTransport for request signing, never into the returned array.
        self::assertStringContainsString("'access_token' => \$accessToken", $source);
        self::assertDoesNotMatchRegularExpression(
            "/return \\[[\\s\\S]*secretBase64Url[\\s\\S]*\\];/",
            $source
        );
        // Same-origin widget IIFE must stay relative unless an absolute CDN override is configured.
        self::assertStringNotContainsString('url($bundleUrl)', $source);
    }
}
