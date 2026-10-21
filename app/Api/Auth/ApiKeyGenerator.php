<?php

declare(strict_types=1);

namespace App\Api\Auth;

use Illuminate\Support\Str;

/**
 * High-entropy service API keys.
 * - User API Access Gate: svc_live_{lookupId}_{secret}
 * - Service Gate managed:  svc_gate_{lookupId}_{secret}
 * Lookup id (key_prefix) narrows DB candidates; secret is never encoded with business state.
 */
final class ApiKeyGenerator
{
    public const PREFIX_SCHEME = 'svc_live_';

    public const PREFIX_MANAGED = 'svc_gate_';

    public function __construct(
        private readonly ApiKeyHasher $hasher,
    ) {}

    /**
     * @return array{raw_key: string, key_prefix: string, key_hash: string}
     */
    public function generate(string $scheme = self::PREFIX_SCHEME): array
    {
        if (! in_array($scheme, [self::PREFIX_SCHEME, self::PREFIX_MANAGED], true)) {
            $scheme = self::PREFIX_SCHEME;
        }

        $lookupId = Str::lower(bin2hex(random_bytes(4))); // 8 hex chars
        $secret = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
        $rawKey = $scheme.$lookupId.'_'.$secret;
        $keyPrefix = $scheme.$lookupId;

        return [
            'raw_key' => $rawKey,
            'key_prefix' => $keyPrefix,
            'key_hash' => $this->hasher->hash($rawKey),
        ];
    }

    public function extractPrefix(string $rawKey): ?string
    {
        if (preg_match('/^(svc_(?:live|gate)_[a-f0-9]{8})_/i', $rawKey, $m) !== 1) {
            return null;
        }

        return Str::lower((string) $m[1]);
    }
}
