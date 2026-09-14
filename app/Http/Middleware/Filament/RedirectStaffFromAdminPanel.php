<?php

declare(strict_types=1);

namespace App\Http\Middleware\Filament;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff may hit /admin (panel home) only to be redirected to the Access Hub.
 * Other /admin/* routes fall through to Filament Authenticate + canAccessPanel (403).
 * Owner/Admin behavior is unchanged.
 */
final class RedirectStaffFromAdminPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->isOwner() || (string) $user->role === User::ROLE_ADMIN) {
            return $next($request);
        }

        $path = '/'.trim($request->path(), '/');
        if ($path === '/admin') {
            return redirect('/workspace');
        }

        return $next($request);
    }
}
