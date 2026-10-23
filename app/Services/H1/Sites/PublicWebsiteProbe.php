<?php

declare(strict_types=1);

namespace App\Services\H1\Sites;

use App\Services\H1\Endpoint\ServerEndpointValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Confirms a public website is reachable over HTTP(S) without SSRF to private networks.
 */
final class PublicWebsiteProbe
{
    private const MAX_REDIRECTS = 5;

    private const TIMEOUT_SECONDS = 8;

    /** @var (callable(string): list<string>|false)|null */
    private $dnsLookup;

    /**
     * @param  (callable(string): list<string>|false)|null  $dnsLookup
     */
    public function __construct(?callable $dnsLookup = null)
    {
        $this->dnsLookup = $dnsLookup;
    }

    /**
     * @throws InvalidArgumentException when offline, 404, or SSRF/private target
     */
    public function assertPublicWebsiteOnline(string $domain): void
    {
        $domain = strtolower(trim($domain));
        $this->assertPublicHost($domain);

        $urls = [
            'https://'.$domain.'/',
            'http://'.$domain.'/',
        ];

        $lastError = 'Không thể kết nối tới website.';

        foreach ($urls as $url) {
            try {
                $this->probeUrl($url);

                return;
            } catch (InvalidArgumentException $e) {
                $lastError = $e->getMessage();
                // Hard rejects: never fall through to the alternate scheme.
                if (
                    str_contains($lastError, '404')
                    || str_contains($lastError, 'SSRF')
                    || str_contains($lastError, 'nội bộ')
                    || str_contains($lastError, 'localhost')
                ) {
                    throw $e;
                }
                // Connection / TLS failures on HTTPS may retry plain HTTP once.
            }
        }

        throw new InvalidArgumentException($lastError);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function probeUrl(string $startUrl): void
    {
        $url = $startUrl;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->assertPublicUrl($url);

            try {
                $response = $this->client()->withOptions(['allow_redirects' => false])->get($url);
            } catch (ConnectionException) {
                throw new InvalidArgumentException('Website offline hoặc không thể kết nối.');
            } catch (\Throwable) {
                throw new InvalidArgumentException('Website offline hoặc không thể kết nối.');
            }

            $status = $response->status();
            if ($status === 404) {
                throw new InvalidArgumentException('Website trả về HTTP 404. Không thể đăng ký làm Website chính.');
            }

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = (string) $response->header('Location');
                if ($location === '') {
                    throw new InvalidArgumentException('Website chuyển hướng không hợp lệ.');
                }
                $url = $this->resolveRedirect($url, $location);
                continue;
            }

            if ($status >= 200 && $status < 400) {
                return;
            }

            // 4xx/5xx other than 404 still prove the host is online.
            if ($status !== 404 && $status >= 400) {
                return;
            }

            throw new InvalidArgumentException('Website offline hoặc phản hồi không hợp lệ (HTTP '.$status.').');
        }

        throw new InvalidArgumentException('Website chuyển hướng quá nhiều lần.');
    }

    private function client(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders([
                'User-Agent' => 'SEO-OPS-Installer/1.0',
                'Accept' => 'text/html,application/xhtml+xml,*/*',
            ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertPublicUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('URL website không hợp lệ.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Chỉ chấp nhận URL HTTP(S) công khai.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('URL website không được chứa thông tin xác thực.');
        }

        $this->assertPublicHost(strtolower(trim((string) $parts['host'], '[]')));
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertPublicHost(string $host): void
    {
        if ($host === '' || ServerEndpointValidator::isLoopbackHost($host)) {
            throw new InvalidArgumentException('Không được dùng localhost làm Website chính.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (ServerEndpointValidator::isPrivateOrReservedIp($host)) {
                throw new InvalidArgumentException("Không được phép kết nối tới IP nội bộ/hạn chế ({$host}). Chặn SSRF.");
            }

            return;
        }

        $lower = strtolower($host);
        foreach (['.internal', '.corp', '.lan', '.home', '.local'] as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                throw new InvalidArgumentException("Tên miền nội bộ '{$host}' không được phép.");
            }
        }

        $resolvedIps = $this->resolveHostIps($host);
        if (! is_array($resolvedIps) || $resolvedIps === []) {
            throw new InvalidArgumentException("Không phân giải được DNS cho '{$host}'.");
        }

        foreach ($resolvedIps as $ip) {
            if (ServerEndpointValidator::isLoopbackHost($ip) || ServerEndpointValidator::isPrivateOrReservedIp($ip)) {
                throw new InvalidArgumentException("Tên miền '{$host}' trỏ tới IP nội bộ ({$ip}). Yêu cầu bị chặn để chống SSRF.");
            }
        }
    }

    /**
     * @return list<string>|false
     */
    private function resolveHostIps(string $host): array|false
    {
        if ($this->dnsLookup !== null) {
            $resolved = ($this->dnsLookup)($host);

            return is_array($resolved) ? array_values($resolved) : false;
        }

        $resolved = @gethostbynamel($host);

        return is_array($resolved) ? array_values($resolved) : false;
    }

    private function resolveRedirect(string $currentUrl, string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($currentUrl);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$host.$port.$location;
        }

        $basePath = (string) ($parts['path'] ?? '/');
        $dir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
        if ($dir === '.' || $dir === '') {
            $dir = '';
        }

        return $scheme.'://'.$host.$port.$dir.'/'.$location;
    }
}
