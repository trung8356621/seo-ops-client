<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\GoogleLoginUserService;
use App\Services\Auth\PostLoginRedirector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function __construct(
        private readonly PostLoginRedirector $postLoginRedirector,
    ) {}

    public function redirectToGoogle(): RedirectResponse
    {
        // Canonical auth: do not preserve return_url / intended across OAuth.
        session()->forget('url.intended');

        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(): RedirectResponse
    {
        try {
            $client = new \GuzzleHttp\Client(['verify' => false]);

            $gUser = Socialite::driver('google')
                ->setHttpClient($client)
                ->stateless()
                ->user();

            $user = app(GoogleLoginUserService::class)->provisionFromGoogleUser($gUser);

            Auth::login($user, true);
            request()->session()->regenerate();
            request()->session()->forget('url.intended');

            return redirect($this->postLoginRedirector->urlFor($user));
        } catch (\Exception $e) {
            Log::error('Google Login Error: '.$e->getMessage());

            return redirect()->route('login');
        }
    }
}
