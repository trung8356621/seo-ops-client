<?php

declare(strict_types=1);

namespace App\Services\H1\Endpoint;

use InvalidArgumentException;

/**
 * Validates OPS-SERVER endpoint URLs safely:
 * - Allows HTTP loopback for local development only (localhost, 127.0.0.0/8, ::1).
 * - Enforces HTTPS for all non-loopback endpoints.
 * - Prevents SSRF, DNS rebinding, and requests to arbitrary private/reserved IP networks.
 * - Disallows embedded credentials, query parameters, fragments, or arbitrary paths.
 */
final class ServerEndpointValidator
{
    public static function normalize(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    public static function isLoopbackHost(string $host): bool
    {
        $normalized = strtolower(trim($host, '[]'));

        if ($normalized === 'localhost' || $normalized === '::1') {
            return true;
        }

        if (filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($normalized, '127.');
        }

        return false;
    }

    public static function isPrivateOrReservedIp(string $ip): bool
    {
        $clean = trim($ip, '[]');

        if (! filter_var($clean, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (str_starts_with($clean, '127.') || $clean === '::1') {
            return true;
        }

        return filter_var($clean, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function validate(string $rawUrl, bool $requireHttps = true, bool $allowPath = false): string
    {
        $url = self::normalize($rawUrl);

        if ($url === '') {
            throw new InvalidArgumentException('Địa chỉ máy chủ OPS-SERVER không được để trống.');
        }

        if (strlen($url) > 2048) {
            throw new InvalidArgumentException('Địa chỉ máy chủ OPS-SERVER quá dài.');
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Địa chỉ máy chủ OPS-SERVER không đúng định dạng URL hợp lệ.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("Giao thức '{$scheme}' không được hỗ trợ. Chỉ chấp nhận HTTP hoặc HTTPS.");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Địa chỉ máy chủ không được chứa thông tin xác thực người dùng (username/password).');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Địa chỉ máy chủ không được chứa tham số truy vấn (query) hoặc phân đoạn (fragment).');
        }

        if (! $allowPath && isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            throw new InvalidArgumentException('Địa chỉ máy chủ không được chứa đường dẫn con (path). Vui lòng chỉ nhập domain/host và cổng.');
        }

        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException('Cổng kết nối máy chủ (port) không hợp lệ (1-65535).');
            }
        }

        $host = strtolower(trim($parts['host'], '[]'));

        // Rule 8: Allow HTTP loopback for local development only. Require HTTPS for non-loopback endpoints.
        $isLoopback = self::isLoopbackHost($host);
        if ($requireHttps && $scheme === 'http' && ! $isLoopback) {
            throw new InvalidArgumentException('Địa chỉ máy chủ ngoài máy cục bộ (non-loopback) bắt buộc phải sử dụng giao thức bảo mật HTTPS.');
        }

        // Rule 9: Prevent SSRF and DNS rebinding to arbitrary private/internal hosts outside explicitly trusted local development.
        if (! $isLoopback) {
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                if (self::isPrivateOrReservedIp($host)) {
                    throw new InvalidArgumentException("Không được phép kết nối tới địa chỉ IP mạng nội bộ hoặc dải IP hạn chế ({$host}).");
                }
            } else {
                $lower = strtolower($host);
                foreach (['.internal', '.corp', '.lan', '.home'] as $internalSuffix) {
                    if (str_ends_with($lower, $internalSuffix)) {
                        throw new InvalidArgumentException("Tên miền nội bộ '{$host}' không được phép kết nối qua internet.");
                    }
                }

                $resolvedIps = @gethostbynamel($host);
                if (is_array($resolvedIps) && ! empty($resolvedIps)) {
                    foreach ($resolvedIps as $ip) {
                        if (self::isLoopbackHost($ip) || self::isPrivateOrReservedIp($ip)) {
                            throw new InvalidArgumentException("Tên miền '{$host}' trỏ tới địa chỉ IP nội bộ ({$ip}). Yêu cầu bị chặn để chống SSRF / DNS rebinding.");
                        }
                    }
                }
            }
        }

        return $url;
    }
}
