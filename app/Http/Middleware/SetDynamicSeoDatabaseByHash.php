<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class SetDynamicSeoDatabaseByHash
{
    public function __construct(
        private readonly SeoDatabaseConnectionService $databaseConnection,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkipHashBootstrap($request)) {
            return $next($request);
        }

        $hashId = $this->resolveHashId($request);

        if ($hashId === null) {
            return redirect()->to('/seo', $request->isMethodSafe() ? 301 : 302);
        }

        if (! SeoConnectionContext::isValidHashFormat($hashId)) {
            return redirect()->to('/seo', $request->isMethodSafe() ? 301 : 302);
        }

        try {
            $connection = $this->databaseConnection->bootstrapByHash($hashId);
        } catch (RuntimeException) {
            return redirect()->to('/seo', $request->isMethodSafe() ? 301 : 302);
        }

        SeoConnectionContext::applyUrlDefaults($hashId);
        SeoConnectionContext::rememberHash($hashId);

        $user = auth()->user();
        if ($user !== null && ! $this->databaseConnection->userCanAccessConnection($user, $connection)) {
            abort(403, 'Tài khoản của bạn không có quyền truy cập vào không gian lưu trữ SEO này.');
        }

        if ($this->shouldCanonicalizeHashUrlToShort($request)) {
            return redirect()->to($this->shortUrlFromHashRequest($request), 302);
        }

        return $next($request);
    }

    /**
     * Browser navigation on /seo/{hash}/... → /seo/... (session keeps connection).
     * Skips Livewire/API/non-HTML and hash-only OAuth connect paths.
     */
    private function shouldCanonicalizeHashUrlToShort(Request $request): bool
    {
        if (! $request->isMethodSafe()) {
            return false;
        }

        if ($request->ajax() || $request->expectsJson()) {
            return false;
        }

        if ($request->headers->has('X-Livewire') || $request->headers->has('X-Livewire-Component')) {
            return false;
        }

        $path = trim($request->path(), '/');

        if ($path === 'seo' || ! preg_match('#^seo/[a-zA-Z0-9]{32,64}(/|$)#', $path)) {
            return false;
        }

        if (preg_match('#^seo/[a-zA-Z0-9]{32,64}/login$#', $path) === 1) {
            return false;
        }

        // GSC OAuth connect is registered on the hash panel only.
        if (str_contains($path, '/settings/api/google-search-console/') && str_ends_with($path, '/connect')) {
            return false;
        }

        return true;
    }

    private function shortUrlFromHashRequest(Request $request): string
    {
        $path = trim($request->path(), '/');
        $shortPath = (string) preg_replace('#^seo/[a-zA-Z0-9]{32,64}#', 'seo', $path, 1);
        $query = $request->getQueryString();

        return $query !== null && $query !== ''
            ? '/'.$shortPath.'?'.$query
            : '/'.$shortPath;
    }

    private function shouldSkipHashBootstrap(Request $request): bool
    {
        if ($request->routeIs([
            'seo.auth.login',
            'seo.auth.login.store',
            'seo.auth.login.hash.store',
            'filament.seo.auth.login',
            'filament.seo-main.auth.login',
        ])) {
            return true;
        }

        $path = trim($request->path(), '/');

        return $path === 'seo/login'
            || (bool) preg_match('#^seo/[a-zA-Z0-9]{32,64}/login$#', $path);
    }

    private function resolveHashId(Request $request): ?string
    {
        $routeHash = $request->route('connection_hash');
        if (is_string($routeHash) && $routeHash !== '') {
            return $routeHash;
        }

        $sessionHash = session('seo_current_connection_hash');
        if (is_string($sessionHash) && $sessionHash !== '') {
            return $sessionHash;
        }

        $headerHash = trim((string) $request->header('X-SEO-Connection', ''));

        return $headerHash !== '' ? $headerHash : null;
    }
}
