<?php

declare(strict_types=1);

namespace App\Services\H1\Sites;

use InvalidArgumentException;

/**
 * Normalizes and validates public website hostnames for Admin Sites / Domain Anchor.
 */
final class SiteDomainNormalizer
{
    public const DOMAIN_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i';

    public function normalize(string $raw): string
    {
        $value = strtolower(trim($raw));
        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $parts = parse_url($value);
        if ($parts === false || ! isset($parts['host'])) {
            throw new InvalidArgumentException('Tên miền không đúng định dạng (Ví dụ: example.com).');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $host = preg_replace('#^www\.#', '', $host) ?? $host;

        return rtrim($host, '.');
    }

    /**
     * @throws InvalidArgumentException
     */
    public function normalizeAndValidate(string $raw): string
    {
        $domain = $this->normalize($raw);
        if ($domain === '') {
            throw new InvalidArgumentException('Vui lòng nhập tên miền website.');
        }

        if (! preg_match(self::DOMAIN_PATTERN, $domain)) {
            throw new InvalidArgumentException('Tên miền không đúng định dạng (Ví dụ: example.com).');
        }

        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('Không chấp nhận địa chỉ IP thô làm Website chính. Vui lòng dùng tên miền công khai.');
        }

        return $domain;
    }

    public function suggestedFromAppUrl(?string $appUrl = null): ?string
    {
        $url = trim((string) ($appUrl ?? config('app.url', '')));
        if ($url === '') {
            return null;
        }

        try {
            $domain = $this->normalize($url);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($domain === '' || in_array($domain, ['localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }

        if (! preg_match(self::DOMAIN_PATTERN, $domain)) {
            return null;
        }

        return $domain;
    }
}
