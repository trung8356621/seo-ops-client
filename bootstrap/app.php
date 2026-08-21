<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

// Before vendor ModelNotFoundException autoloads: safe setModel for nested-array ids
// (Livewire ImplicitlyBoundMethod / route binding). Prevents ErrorException mask.
if (! class_exists(\Illuminate\Database\Eloquent\ModelNotFoundException::class, false)) {
    require __DIR__.'/../app/Support/Patches/IlluminateModelNotFoundException.php';
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::prefix('api/control/v1')
                ->middleware('throttle:60,1')
                ->group(base_path('routes/control.php'));
        },
    )
    ->withCommands([
        // Phase 3C3: luôn discover — không phụ thuộc AddonServiceProvider boot order.
        \App\Addons\SeoContentAi\Console\DiagnoseContentProjectCommand::class,
        \App\Addons\SeoContentAi\Console\RepairContentProjectCommand::class,
        \App\Console\Commands\CleanupMisplacedTablesCommand::class,
        \App\Console\Commands\MigrateAutomationToCoreCommand::class,
        \App\Console\Commands\TestDoctorCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(static function (): string {
            $request = request();
            $path = trim((string) $request->path(), '/');

            $isSeoPath = $path === 'seo' || str_starts_with($path, 'seo/');
            $isSeoLivewire = false;
            if ($request->is('livewire/*')) {
                $referer = (string) $request->headers->get('referer', '');
                $isSeoLivewire = $referer !== ''
                    && preg_match('#/seo(?:/|$)#', parse_url($referer, PHP_URL_PATH) ?? '') === 1;
            }

            // Chỉ guest SEO (path hoặc Livewire từ trang SEO) → login SEO.
            // Không bắt mọi livewire/* — sẽ phá admin login.
            if ($isSeoPath || $isSeoLivewire) {
                if ($path === 'seo/login' || preg_match('#^seo/[a-zA-Z0-9]{32,64}/login$#', $path) === 1) {
                    return url('/'.$path);
                }

                // Explicit hash path → hash login; short Main path → /seo/login.
                if (preg_match('#^seo/([a-zA-Z0-9]{32,64})(?:/|$)#', $path, $matches) === 1
                    && Route::has('filament.seo.auth.login')
                ) {
                    return route('filament.seo.auth.login', ['connection_hash' => $matches[1]]);
                }

                if (Route::has('filament.seo-main.auth.login')) {
                    return route('filament.seo-main.auth.login');
                }

                if (Route::has('seo.auth.login')) {
                    return route('seo.auth.login');
                }

                return url('/seo/login');
            }

            if (Route::has('filament.admin.auth.login')) {
                return route('filament.admin.auth.login');
            }

            return '/admin/login';
        });

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class, // Thay bằng class middleware của bạn
            'seo.planner' => \App\Addons\SeoContentAi\Http\Middleware\SeoPlannerPermissionMiddleware::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            // Tạm thời để trống hoặc thêm các route nếu cần
        ]);

        $middleware->statefulApi(); // Đảm bảo session được giữ cho các request API/Livewire

        $middleware->append(\App\Http\Middleware\LogLocalSlowHttpMiddleware::class);
        $middleware->append(\App\Http\Middleware\EnsureClientIsNotLocked::class);
        $middleware->web(append: [
            \App\Http\Middleware\EnsureClientIsNotLocked::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\EnsureClientIsNotLocked::class,
        ]);

    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'admin/wp-headless/connect/*', // Cho phép các route này bỏ qua CSRF
            'api/control/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // HTTP → web_app only. Returning false skips default channel (laravel.log),
        // which may be root-owned on production while PHP-FPM runs as www.
        // CLI/cron/queue keep framework default reporting unchanged.
        $exceptions->report(function (\Throwable $e): ?bool {
            $path = '';
            try {
                $path = '/'.ltrim((string) request()->path(), '/');
            } catch (\Throwable) {
                $path = '';
            }

            if (str_contains($path, 'editor-sessions')) {
                @file_put_contents(
                    storage_path('logs/editor-session-debug.log'),
                    date('c').' FRAMEWORK '.$e::class.' '.$e->getMessage().' path='.$path."\n",
                    FILE_APPEND,
                );
            }

            if (app()->runningInConsole()) {
                return null;
            }

            \App\Support\RuntimeLogger::report($e);

            return false;
        });
    })->create();
