<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Installation\InstallationStateManager;
use App\Services\H1\Persistence\InstallationStateRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RedirectFreshUnlicensedToQuickSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Only applies to genuinely fresh unlicensed client installations
        $installer = app(InstallationStateManager::class);
        if (! $installer->isFreshUnlicensed()) {
            return $next($request);
        }

        // Live double-check: if H1 enrollment already occurred, allow normal navigation
        try {
            if (class_exists(InstallationStateRepository::class)) {
                $installations = app(InstallationStateRepository::class);
                if ($installations->isEnrolled()) {
                    return $next($request);
                }
            }
        } catch (\Throwable) {
        }

        $path = trim($request->path(), '/');

        // Allow Livewire component requests
        if ($request->hasHeader('X-Livewire') || str_starts_with($path, 'livewire')) {
            return $next($request);
        }

        // Allow Filament asset requests
        if (str_starts_with($path, 'filament')
            || str_starts_with($path, 'css')
            || str_starts_with($path, 'js')
            || str_starts_with($path, 'build')
            || str_starts_with($path, 'images')
        ) {
            return $next($request);
        }

        // Allow essential onboarding & administrative endpoints
        $exemptions = [
            'admin/quick-setup',
            'admin/services',
            'admin/logout',
            'admin/login',
            'admin/help-topics',
        ];

        foreach ($exemptions as $exempt) {
            if ($path === $exempt || str_starts_with($path, $exempt . '/')) {
                return $next($request);
            }
        }

        // Redirect to Step 1 of Quick Setup
        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'unlicensed',
                'redirect' => url('/admin/quick-setup?step=1'),
            ], 307);
        }

        return redirect()->to(url('/admin/quick-setup?step=1'));
    }
}
