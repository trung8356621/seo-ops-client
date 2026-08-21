<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Control\ClientLockGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureClientIsNotLocked
{
    public function __construct(
        private readonly ClientLockGuard $lockGuard,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->lockGuard->isLocked()) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        if ($this->isAllowedWhileLocked($request)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $message = $this->lockGuard->publicMessage();

        if ($this->wantsJson($request)) {
            return response()->json([
                'message' => $message,
            ], 423);
        }

        return response()->view('client-locked', [
            'message' => $message,
        ], 423);
    }

    private function isAllowedWhileLocked(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');
        if ($path === '/') {
            $path = '/';
        }

        foreach ($this->allowedPrefixes() as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        if ($request->is('livewire/*')) {
            return $this->isAllowedLivewire($request);
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allowedPrefixes(): array
    {
        return [
            '/up',
            '/api/control',
            '/client-locked',
            '/admin/login',
            '/admin/logout',
            '/admin/password-reset',
            '/admin/password-reset/request',
            '/admin/email-verification',
            '/admin/email-verification/prompt',
            '/admin/control-server',
            '/login',
            '/logout',
            '/seo/login',
            '/forgot-password',
            '/reset-password',
            '/auth/google',
            '/livewire/livewire.js',
            '/livewire/livewire.min.js',
            '/css',
            '/js',
            '/build',
            '/fonts',
            '/storage',
            '/images',
            '/favicon.ico',
            '/filament',
        ];
    }

    private function isAllowedLivewire(Request $request): bool
    {
        $referer = (string) $request->headers->get('referer', '');
        $refererPath = parse_url($referer, PHP_URL_PATH);
        if (is_string($refererPath) && $refererPath !== '') {
            $normalized = '/'.ltrim($refererPath, '/');
            foreach ($this->allowedPrefixes() as $prefix) {
                if ($normalized === $prefix || str_starts_with($normalized, $prefix.'/')) {
                    return true;
                }
            }
        }

        foreach ($this->livewireComponentNames($request) as $name) {
            if (
                str_contains($name, 'ControlServer')
                || str_contains($name, 'CustomLogin')
                || str_contains($name, 'Pages\\Auth\\Login')
                || str_contains($name, 'Pages\\Auth\\PasswordReset')
                || str_contains($name, 'Pages\\Auth\\EmailVerification')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function livewireComponentNames(Request $request): array
    {
        $names = [];
        $components = $request->input('components');
        if (! is_array($components)) {
            return $names;
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $snapshot = $component['snapshot'] ?? null;
            if (is_string($snapshot)) {
                $decoded = json_decode($snapshot, true);
                if (is_array($decoded)) {
                    $name = $decoded['memo']['name'] ?? null;
                    if (is_string($name) && $name !== '') {
                        $names[] = $name;
                    }
                }
            }
        }

        return $names;
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson()
            || $request->is('api/*')
            || $request->is('livewire/*');
    }
}
