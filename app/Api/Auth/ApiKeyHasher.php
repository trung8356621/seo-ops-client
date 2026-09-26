<?php

declare(strict_types=1);

namespace App\Api\Auth;

/**
 * One-way HMAC-SHA256 for service API keys (not reversible encryption).
 */
final class ApiKeyHasher
{
    public function hash(string $rawKey): string
    {
        return hash_hmac('sha256', $rawKey, $this->pepper());
    }

    public function verify(string $rawKey, string $storedHash): bool
    {
        if ($rawKey === '' || $storedHash === '') {
            return false;
        }

        return hash_equals($storedHash, $this->hash($rawKey));
    }

    private function pepper(): string
    {
        $key = (string) config('app.key', '');

        return $key !== '' ? $key : 'service-api-fallback-pepper';
    }
}
