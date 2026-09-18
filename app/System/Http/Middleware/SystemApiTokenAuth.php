<?php

declare(strict_types=1);

namespace App\System\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service-to-service auth for System API remote transport.
 * Accepts Authorization: Bearer <SYSTEM_API_TOKEN> with constant-time compare.
 * Does not log the token.
 */
final class SystemApiTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = trim((string) config('system.http.service_token', ''));
        if ($configured === '') {
            return response()->json([
                'ok' => false,
                'error' => [
                    'code' => 'service_auth_not_configured',
                    'message' => 'SYSTEM_API_TOKEN is not configured.',
                ],
            ], 401);
        }

        $header = (string) $request->header('Authorization', '');
        $token = '';
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) === 1) {
            $token = (string) $m[1];
        }

        if ($token === '' || ! hash_equals($configured, $token)) {
            return response()->json([
                'ok' => false,
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Invalid or missing System API service token.',
                ],
            ], 401);
        }

        return $next($request);
    }
}
