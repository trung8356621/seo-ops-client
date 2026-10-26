<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the deployment explicitly enables HTTPS (APP_URL https:// and/or FORCE_HTTPS),
 * send browser traffic to the canonical HTTPS origin from config('app.url').
 *
 * - Target host comes from APP_URL — never from the raw Host header.
 * - API / machine routes are not redirected (RPA / service API keep working; use HTTPS base).
 * - Already-secure requests (incl. trusted X-Forwarded-Proto) are left alone (no loop).
 */
final class EnsureCanonicalHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('app.force_https')) {
            return $next($request);
        }

        if ($request->secure()) {
            return $next($request);
        }

        if ($this->shouldSkipRedirect($request)) {
            return $next($request);
        }

        $target = $this->canonicalHttpsUrl($request);
        if ($target === null) {
            return $next($request);
        }

        $status = in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 301 : 308;

        return redirect()->to($target, $status);
    }

    private function shouldSkipRedirect(Request $request): bool
    {
        $path = trim($request->path(), '/');

        // Health probe and machine APIs — no surprise redirects for RPA / integrators.
        if ($path === 'up' || $path === 'api' || str_starts_with($path, 'api/')) {
            return true;
        }

        return false;
    }

    private function canonicalHttpsUrl(Request $request): ?string
    {
        $canonical = rtrim((string) config('app.url'), '/');
        $parts = parse_url($canonical);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (! is_string($host) || $host === '') {
            return null;
        }

        $port = is_array($parts) && isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return 'https://'.$host.$port.$request->getRequestUri();
    }
}
