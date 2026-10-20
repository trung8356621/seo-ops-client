<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Performance\ActiveUserPresence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Throttled presence touch for authenticated web traffic.
 * Multiple tabs → same user id → one active user.
 */
final class TouchActiveUserPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $userId = (int) ($request->user()?->getAuthIdentifier() ?? 0);
        if ($userId > 0) {
            try {
                app(ActiveUserPresence::class)->touch($userId);
            } catch (\Throwable) {
            }
        }

        return $response;
    }
}
