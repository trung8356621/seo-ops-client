<?php

declare(strict_types=1);

namespace App\Http\Responses\Auth;

use Filament\Http\Responses\Auth\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Http\RedirectResponse;

/**
 * Filament panel logout → canonical /login (panels no longer own login pages).
 */
final class CanonicalLogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()->to(route('login'));
    }
}
