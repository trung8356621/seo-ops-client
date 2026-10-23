<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Services\H1\Exceptions\H1TransportException;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Protocol\SignedHttpTransport;
use App\Services\H1\Widget\WidgetTokenExchangeClient;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WidgetTokenExchangeParseTest extends TestCase
{
    private function client(): WidgetTokenExchangeClient
    {
        return new WidgetTokenExchangeClient(
            new InstallationStateRepository,
            new SignedHttpTransport,
        );
    }

    #[Test]
    public function accepts_complete_success_contract(): void
    {
        $parsed = $this->client()->parseSuccessResponse([
            'status' => 200,
            'body' => '{}',
            'headers' => ['x-ops-signature' => 'v1=abc'],
            'decoded' => [
                'protocol' => 'seo-ops-license/1',
                'installation_id' => 'ins_1',
                'customer_id' => 'cust_1',
                'access_token' => 'tok.payload.sig',
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
                'scopes' => ['widget:status', 'widget:renew'],
            ],
        ], 'ins_1', 'http://localhost:3000');

        self::assertSame('tok.payload.sig', $parsed['access_token']);
        self::assertSame('ins_1', $parsed['installation_id']);
        self::assertContains('widget:status', $parsed['scopes']);
        self::assertArrayNotHasKey('secret', $parsed);
    }

    #[Test]
    public function rejects_ops_error_body_instead_of_treating_as_token(): void
    {
        try {
            $this->client()->parseSuccessResponse([
                'status' => 503,
                'body' => '{"code":"secret_unconfigured","error":"missing"}',
                'headers' => [],
                'decoded' => [
                    'code' => 'secret_unconfigured',
                    'error' => 'OPS_SERVICE_WIDGET_TOKEN_SECRET is missing',
                ],
            ], 'ins_1', 'http://localhost:3000');
            self::fail('Expected H1TransportException');
        } catch (H1TransportException $e) {
            self::assertSame('secret_unconfigured', $e->errorCode);
            self::assertStringNotContainsString('incomplete', strtolower($e->getMessage()));
            self::assertStringNotContainsString('OPS_SERVICE_WIDGET_TOKEN_SECRET', $e->getMessage());
        }
    }

    #[Test]
    public function rejects_missing_access_token_with_clear_code(): void
    {
        $this->expectException(H1TransportException::class);
        $this->expectExceptionMessage('access_token');
        $this->client()->parseSuccessResponse([
            'status' => 200,
            'body' => '{}',
            'headers' => ['x-ops-signature' => 'v1=abc'],
            'decoded' => [
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
                'scopes' => ['widget:status', 'widget:renew'],
                'installation_id' => 'ins_1',
            ],
        ], 'ins_1', 'http://localhost:3000');
    }

    #[Test]
    public function rejects_cross_installation_token(): void
    {
        try {
            $this->client()->parseSuccessResponse([
                'status' => 200,
                'body' => '{}',
                'headers' => ['x-ops-signature' => 'v1=abc'],
                'decoded' => [
                    'access_token' => 'tok',
                    'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
                    'scopes' => ['widget:status', 'widget:renew'],
                    'installation_id' => 'ins_other',
                ],
            ], 'ins_1', 'http://localhost:3000');
            self::fail('Expected scope_mismatch');
        } catch (H1TransportException $e) {
            self::assertSame('scope_mismatch', $e->errorCode);
        }
    }

    #[Test]
    public function rejects_missing_required_scopes(): void
    {
        try {
            $this->client()->parseSuccessResponse([
                'status' => 200,
                'body' => '{}',
                'headers' => ['x-ops-signature' => 'v1=abc'],
                'decoded' => [
                    'access_token' => 'tok',
                    'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
                    'scopes' => ['widget:status'],
                    'installation_id' => 'ins_1',
                ],
            ], 'ins_1', 'http://localhost:3000');
            self::fail('Expected invalid_scope');
        } catch (H1TransportException $e) {
            self::assertSame('invalid_scope', $e->errorCode);
        }
    }
}
