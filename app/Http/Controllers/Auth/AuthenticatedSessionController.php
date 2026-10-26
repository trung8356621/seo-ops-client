<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): Response
    {
        $request->authenticate();

        $request->session()->regenerate();

        return response()->noContent();
    }

    /**
     * Destroy an authenticated session.
     *
     * Browser logout must land on a fresh GET /login (new CSRF). A bare 204
     * leaves a stale Filament/HTML UI that keeps POSTing expired tokens → 419.
     */
    public function destroy(Request $request): RedirectResponse|Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        if ($request->expectsJson() && ! $request->header('X-Livewire')) {
            return response()->noContent();
        }

        return redirect()->to(route('login'));
    }
}
