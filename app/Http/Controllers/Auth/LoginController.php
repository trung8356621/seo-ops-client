<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\PostLoginRedirector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Canonical browser login — one User, one web session, one flow.
 */
final class LoginController extends Controller
{
    public function __construct(
        private readonly PostLoginRedirector $postLoginRedirector,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            /** @var User $user */
            $user = Auth::user();

            return redirect()->to($this->postLoginRedirector->urlFor($user));
        }

        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse|Response
    {
        $request->authenticate();
        $request->session()->regenerate();

        // Forget any leftover intended / return URL from legacy flows.
        $request->session()->forget('url.intended');

        /** @var User $user */
        $user = Auth::user();

        if ($request->expectsJson() && ! $request->header('X-Livewire')) {
            return response()->noContent();
        }

        return redirect()->to($this->postLoginRedirector->urlFor($user));
    }
}
