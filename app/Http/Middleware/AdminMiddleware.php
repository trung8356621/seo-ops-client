<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Auth;
use Closure;
use Illuminate\Http\Request;

/**
 * @deprecated Client setup uses owner role + RedirectStaffFromAdminPanel.
 * Kept as alias-compatible stub; prefer owner checks.
 */
class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && Auth::user()?->role === 'owner') {
            return $next($request);
        }

        return redirect('/seo')->with('error', 'Bạn không có quyền truy cập vùng này.');
    }
}
