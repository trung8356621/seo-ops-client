<?php

declare(strict_types=1);

namespace App\Api\Access;

use App\Models\Service;
use App\Models\ServiceApiCredential;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cache-backed temporary Service API capability tokens.
 * Pattern inspired by legacy confirmation tokens — no Agent dependency.
 * Raw token never persisted; only HMAC hash + payload in cache.
 */
final class TemporaryServiceAccessManager
{
    public const TTL_SECONDS = 900;

    public const TOKEN_PREFIX = 'access_tmp_';

    public const READ_SCOPE = 'seo:read';

    private const CACHE_PREFIX = 'tmp_service_access:';

    public function issue(
        Service $service,
        ServiceApiCredential $credential,
        int $siteId,
    ): TemporaryServiceAccessIssueResult {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('site_id must be a positive integer.');
        }
        if ((int) $credential->service_id !== (int) $service->id) {
            throw new InvalidArgumentException('Credential does not belong to Service.');
        }

        $lookupId = Str::lower(bin2hex(random_bytes(4)));
        $secret = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
        $rawToken = self::TOKEN_PREFIX.$lookupId.'_'.$secret;
        $issuedAt = new DateTimeImmutable('now');
        $expiresAt = $issuedAt->modify('+'.self::TTL_SECONDS.' seconds');

        $payload = [
            'service_id' => (int) $service->id,
            'credential_id' => (int) $credential->id,
            'site_id' => $siteId,
            'scopes' => [self::READ_SCOPE],
            'issued_at' => $issuedAt->format(DateTimeInterface::ATOM),
            'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
            'token_hash' => $this->hash($rawToken),
            'lookup_id' => $lookupId,
        ];

        Cache::put($this->cacheKey($lookupId), $payload, self::TTL_SECONDS);

        return new TemporaryServiceAccessIssueResult(
            rawToken: $rawToken,
            expiresAt: $expiresAt->format(DateTimeInterface::ATOM),
            siteId: $siteId,
            lookupId: $lookupId,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPayloadByLookup(string $lookupId): ?array
    {
        $lookupId = Str::lower(trim($lookupId));
        if ($lookupId === '' || ! preg_match('/^[a-f0-9]{8}$/', $lookupId)) {
            return null;
        }

        $payload = Cache::get($this->cacheKey($lookupId));
        if (! is_array($payload)) {
            return null;
        }

        return $payload;
    }

    public function verifyRawToken(string $rawToken, array $payload): bool
    {
        $stored = (string) ($payload['token_hash'] ?? '');
        if ($stored === '' || $rawToken === '') {
            return false;
        }

        return hash_equals($stored, $this->hash($rawToken));
    }

    public function extractLookupId(string $rawToken): ?string
    {
        if (preg_match('/^access_tmp_([a-f0-9]{8})_/i', trim($rawToken), $m) !== 1) {
            return null;
        }

        return Str::lower((string) $m[1]);
    }

    public function hash(string $rawToken): string
    {
        $key = (string) config('app.key', '');

        return hash_hmac('sha256', $rawToken, $key !== '' ? $key : 'tmp-service-access-fallback-pepper');
    }

    public function cacheKey(string $lookupId): string
    {
        return self::CACHE_PREFIX.Str::lower($lookupId);
    }
}
