<?php

declare(strict_types=1);

namespace App\Http\Middleware\Filament;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client /admin setup is owner or legacy admin. Other roles are denied.
 */
final class RedirectStaffFromAdminPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User && ! $user->isOwner() && (string) $user->role !== User::ROLE_ADMIN) {
            return redirect('/seo');
        }

        return $next($request);
    }
}
