<?php

declare(strict_types=1);

namespace Tests\Unit\Control;

use App\Control\Signing\ControlRequestSigner;
use PHPUnit\Framework\TestCase;

final class ControlRequestSignerTest extends TestCase
{
    public function test_signature_covers_canonical_sorted_fields(): void
    {
        $signer = new ControlRequestSigner;
        $payload = ['b' => 2, 'a' => ['z' => 1, 'y' => 0]];

        $one = $signer->sign('secret', 'inst', 'cmd', '2026-08-18T00:00:00Z', 'services.apply', $payload);
        $two = $signer->sign('secret', 'inst', 'cmd', '2026-08-18T00:00:00Z', 'services.apply', [
            'a' => ['y' => 0, 'z' => 1],
            'b' => 2,
        ]);

        $this->assertSame($one, $two);
        $this->assertTrue($signer->matches('secret', $one, 'sha256='.$two));
        $this->assertFalse($signer->matches('secret', $one, $signer->sign('other', 'inst', 'cmd', '2026-08-18T00:00:00Z', 'services.apply', $payload)));
    }

    public function test_canonical_json_includes_signed_fields_only(): void
    {
        $signer = new ControlRequestSigner;
        $canonical = $signer->canonicalize('i', 'c', 't', 'client.lock', ['x' => 1]);

        $this->assertStringContainsString('"command":"client.lock"', $canonical);
        $this->assertStringContainsString('"command_id":"c"', $canonical);
        $this->assertStringContainsString('"installation_id":"i"', $canonical);
        $this->assertStringContainsString('"issued_at":"t"', $canonical);
        $this->assertStringContainsString('"payload":{"x":1}', $canonical);
        $this->assertStringNotContainsString('signature', $canonical);
    }
}
