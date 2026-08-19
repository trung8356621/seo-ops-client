<?php

namespace App\Http\Controllers\Auth;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\GoogleLoginUserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirectToGoogle(): RedirectResponse
    {
        $returnUrl = request()->query('return_url');
        if (is_string($returnUrl) && str_starts_with($returnUrl, '/') && ! str_starts_with($returnUrl, '//')) {
            session(['url.intended' => $returnUrl]);
        }

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

            return redirect($this->resolveFallbackUrl($user));
        } catch (\Exception $e) {
            Log::error('Google Login Error: '.$e->getMessage());

            return redirect($this->resolveLoginPath($this->resolveFallbackUrl()));
        }
    }

    private function resolveFallbackUrl(?User $user = null): string
    {
        $user ??= auth()->user();

        $intended = session('url.intended');
        $hasSafeIntended = is_string($intended)
            && str_starts_with($intended, '/')
            && ! str_starts_with($intended, '//');

        if ($user instanceof User && ($user->isStaff() || $user->isManager())) {
            if ($hasSafeIntended && str_starts_with($intended, '/seo')) {
                return $intended;
            }

            return '/';
        }

        if ($hasSafeIntended) {
            return $intended;
        }

        return '/admin';
    }

    private function resolveLoginPath(string $fallbackUrl): string
    {
        if (! str_starts_with($fallbackUrl, '/seo')) {
            return '/admin/login';
        }

        $hash = app(SeoDatabaseConnectionService::class)->resolveRedirectHash(auth()->user());

        return $hash !== null
            ? '/seo/'.$hash.'/login'
            : '/seo';
    }
}
