<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Installation\InstallationState;
use App\Core\Installation\InstallationStateManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

final class EnsureApplicationInstalled
{
    public function __construct(
        private readonly InstallationStateManager $stateManager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->stateManager->currentStatus();
        $isSetupRoute = $this->isSetupRoute($request);

        // 1. Database outage: never show setup, return 503 maintenance page
        if ($status === InstallationState::DATABASE_UNAVAILABLE) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'database_unavailable',
                    'message' => 'Database connection unavailable. System is temporarily offline for maintenance.',
                ], 503);
            }

            return response()->view('setup.maintenance', [
                'title' => 'Bảo trì hệ thống — Database Unavailable',
                'message' => 'Không thể kết nối đến cơ sở dữ liệu. Hệ thống đang tạm thời ngoại tuyến để bảo trì.',
            ], 503);
        }

        // 2. Uninstalled / In setup: redirect everything to /setup
        if (in_array($status, [
            InstallationState::UNCONFIGURED,
            InstallationState::CONFIGURING,
            InstallationState::RECOVERABLE_FAILURE,
        ], true)) {
            // Ensure session driver is file-based so database is not required for setup sessions
            if (Config::get('session.driver') === 'database') {
                Config::set('session.driver', 'file');
            }

            if (! $isSetupRoute && ! $this->isAssetRoute($request)) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'error' => 'uninstalled',
                        'redirect' => url('/setup'),
                    ], 307);
                }

                return redirect()->guest('/setup');
            }

            return $next($request);
        }

        // 3. Installed: lock /setup routes
        if ($status === InstallationState::INSTALLED && $isSetupRoute) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'already_installed',
                    'message' => 'Software installation is already completed. Setup wizard is locked.',
                    'redirect' => url('/admin'),
                ], 403);
            }

            return redirect('/admin');
        }

        return $next($request);
    }

    private function isSetupRoute(Request $request): bool
    {
        return $request->is('setup') || $request->is('setup/*') || $request->is('api/setup/*');
    }

    private function isAssetRoute(Request $request): bool
    {
        return $request->is('images/*')
            || $request->is('css/*')
            || $request->is('js/*')
            || $request->is('build/*')
            || $request->is('assets/*')
            || $request->is('favicon.*');
    }
}
