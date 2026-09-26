<?php

declare(strict_types=1);

namespace App\Api\Auth;

use App\Models\ServiceApiCredential;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve a raw bearer token to a credential via indexed prefix + hash verify.
 */
final class ApiKeyResolver
{
    public function __construct(
        private readonly ApiKeyGenerator $generator,
        private readonly ApiKeyHasher $hasher,
    ) {}

    public function resolve(string $rawKey): ?ServiceApiCredential
    {
        $rawKey = trim($rawKey);
        if ($rawKey === '') {
            return null;
        }

        if (! Schema::hasTable('service_api_credentials')) {
            return null;
        }

        $prefix = $this->generator->extractPrefix($rawKey);
        if ($prefix === null) {
            return null;
        }

        $candidates = ServiceApiCredential::query()
            ->where('key_prefix', $prefix)
            ->limit(25)
            ->get();

        foreach ($candidates as $credential) {
            if (! $credential instanceof ServiceApiCredential) {
                continue;
            }
            if ($this->hasher->verify($rawKey, (string) $credential->key_hash)) {
                return $credential;
            }
        }

        return null;
    }
}
