<?php

declare(strict_types=1);

namespace App\Help;

use Illuminate\Support\Facades\Http;

/**
 * Thin GitHub client for public Help repo.
 * READ: raw.githubusercontent.com (no token).
 * WRITE: Contents API with server-side token only.
 */
final class HelpGitHubClient
{
    public function __construct(
        private readonly ?string $owner = null,
        private readonly ?string $repo = null,
        private readonly ?string $branch = null,
        private readonly ?string $token = null,
        private readonly ?string $userAgent = null,
    ) {}

    public function isConfiguredForRead(): bool
    {
        return $this->owner() !== '' && $this->repo() !== '';
    }

    public function isConfiguredForWrite(): bool
    {
        return $this->isConfiguredForRead() && $this->token() !== '';
    }

    public function fetchVersion(): ?string
    {
        $body = $this->fetchRawFile('VERSION');
        if ($body === null) {
            return null;
        }

        $version = trim(str_replace("\r\n", "\n", $body));
        $version = explode("\n", $version)[0] ?? '';

        return $version !== '' ? $version : null;
    }

    public function fetchRawFile(string $path): ?string
    {
        if (! $this->isConfiguredForRead()) {
            return null;
        }

        $url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/%s',
            rawurlencode($this->owner()),
            rawurlencode($this->repo()),
            rawurlencode($this->branch()),
            $this->encodePath($path),
        );

        $response = Http::withHeaders($this->publicHeaders())
            ->timeout(20)
            ->get($url);

        if (! $response->successful()) {
            return null;
        }

        return (string) $response->body();
    }

    /**
     * List markdown files under docs/ via Contents API (public repo works without token).
     *
     * @return list<array{path: string, sha: string, type: string}>
     */
    public function listDocsTree(): array
    {
        if (! $this->isConfiguredForRead()) {
            return [];
        }

        return $this->walkContents('docs');
    }

    /**
     * @return array{ok: bool, sha?: string, error?: string}
     */
    public function putFile(string $path, string $content, string $message, ?string $sha = null): array
    {
        if (! $this->isConfiguredForWrite()) {
            return ['ok' => false, 'error' => 'help_github_write_not_configured'];
        }

        $payload = [
            'message' => $message,
            'content' => base64_encode($content),
            'branch' => $this->branch(),
        ];
        if ($sha !== null && $sha !== '') {
            $payload['sha'] = $sha;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/contents/%s',
            rawurlencode($this->owner()),
            rawurlencode($this->repo()),
            $this->encodePath($path),
        );

        $response = Http::withHeaders($this->authHeaders())
            ->timeout(30)
            ->put($url, $payload);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => 'help_github_put_failed:'.$response->status(),
            ];
        }

        $shaOut = (string) data_get($response->json(), 'content.sha', '');

        return ['ok' => true, 'sha' => $shaOut];
    }

    /**
     * @return array{ok: bool, sha?: string|null, content?: string, error?: string}
     */
    public function getContentsFile(string $path): array
    {
        if (! $this->isConfiguredForRead()) {
            return ['ok' => false, 'error' => 'help_github_not_configured'];
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/contents/%s',
            rawurlencode($this->owner()),
            rawurlencode($this->repo()),
            $this->encodePath($path),
        );

        $query = ['ref' => $this->branch()];
        $headers = $this->token() !== '' ? $this->authHeaders() : $this->publicHeaders();

        $response = Http::withHeaders($headers)
            ->timeout(20)
            ->get($url, $query);

        if ($response->status() === 404) {
            return ['ok' => true, 'sha' => null, 'content' => null];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'help_github_get_failed:'.$response->status()];
        }

        $json = $response->json();
        $encoded = (string) ($json['content'] ?? '');
        $encoding = (string) ($json['encoding'] ?? '');
        $content = $encoding === 'base64'
            ? (string) base64_decode(str_replace("\n", '', $encoded), true)
            : $encoded;

        return [
            'ok' => true,
            'sha' => isset($json['sha']) ? (string) $json['sha'] : null,
            'content' => $content,
        ];
    }

    /**
     * @return list<array{path: string, sha: string, type: string}>
     */
    private function walkContents(string $path): array
    {
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/contents/%s',
            rawurlencode($this->owner()),
            rawurlencode($this->repo()),
            $this->encodePath($path),
        );

        $headers = $this->token() !== '' ? $this->authHeaders() : $this->publicHeaders();
        $response = Http::withHeaders($headers)
            ->timeout(20)
            ->get($url, ['ref' => $this->branch()]);

        if (! $response->successful()) {
            return [];
        }

        $items = $response->json();
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            $itemPath = (string) ($item['path'] ?? '');
            $sha = (string) ($item['sha'] ?? '');
            if ($type === 'dir' && $itemPath !== '') {
                foreach ($this->walkContents($itemPath) as $child) {
                    $out[] = $child;
                }
                continue;
            }
            if ($type === 'file' && str_ends_with(strtolower($itemPath), '.md')) {
                $out[] = ['path' => $itemPath, 'sha' => $sha, 'type' => 'file'];
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function publicHeaders(): array
    {
        return [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => $this->userAgent(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => $this->userAgent(),
            'Authorization' => 'Bearer '.$this->token(),
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
    }

    private function encodePath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $segments = array_filter(explode('/', $path), static fn (string $s): bool => $s !== '');

        return implode('/', array_map('rawurlencode', $segments));
    }

    private function owner(): string
    {
        return trim((string) ($this->owner ?? config('help.github.owner', '')));
    }

    private function repo(): string
    {
        return trim((string) ($this->repo ?? config('help.github.repo', 'seo-ops-help')));
    }

    private function branch(): string
    {
        $branch = trim((string) ($this->branch ?? config('help.github.branch', 'main')));

        return $branch !== '' ? $branch : 'main';
    }

    private function token(): string
    {
        return trim((string) ($this->token ?? config('help.github.token', '')));
    }

    private function userAgent(): string
    {
        $ua = trim((string) ($this->userAgent ?? config('help.github.user_agent', 'seo-ops-client-help')));

        return $ua !== '' ? $ua : 'seo-ops-client-help';
    }
}
