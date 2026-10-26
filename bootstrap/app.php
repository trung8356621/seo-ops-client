<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
            Route::prefix('api/system/v1')
                ->middleware('throttle:120,1')
                ->group(base_path('routes/system.php'));

            Route::prefix('api/v1/services')
                ->group(base_path('routes/api-services.php'));

            Route::prefix('api/v1')
                ->group(base_path('routes/api-access.php'));

            $temporaryAccessRoutes = base_path('addons/seo/routes/api-access-temporary.php');
            if (is_file($temporaryAccessRoutes)) {
                Route::prefix('api/v1/access')
                    ->group($temporaryAccessRoutes);
            }
        },
    )
    ->withCommands([
        // Phase 3C3: luôn discover — không phụ thuộc AddonServiceProvider boot order.
        \App\Addons\SeoContentAi\Console\DiagnoseContentProjectCommand::class,
        \App\Addons\SeoContentAi\Console\RepairContentProjectCommand::class,
        \App\Console\Commands\CleanupMisplacedTablesCommand::class,
        \App\Console\Commands\MigrateAutomationToCoreCommand::class,
        \App\Console\Commands\SimulateServiceCommand::class,
        \App\Console\Commands\ResetClientImportLabCommand::class,
        \App\Console\Commands\TestDoctorCommand::class,
        \App\Console\Commands\CheckAiProviderBalancesCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(static fn (): string => route('login'));

        // Behind Herd/nginx/Cloudflare TLS termination — required so secure() is true
        // and EnsureCanonicalHttps does not loop. Override via TRUSTED_PROXIES.
        $trustedProxies = env('TRUSTED_PROXIES', '*');
        $middleware->trustProxies(
            at: $trustedProxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', (string) $trustedProxies)))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'seo.planner' => \App\Addons\SeoContentAi\Http\Middleware\SeoPlannerPermissionMiddleware::class,
            'service.api' => \App\Api\Middleware\AuthenticateServiceApi::class,
            'service.api.scope' => \App\Api\Middleware\RequireServiceApiScope::class,
            'service.access.temporary' => \App\Api\Middleware\ResolveTemporaryServiceAccess::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'setup/api/*',
            'admin/wp-headless/connect/*', // Cho phép các route này bỏ qua CSRF
        ]);

        $middleware->statefulApi(); // Đảm bảo session được giữ cho các request API/Livewire

        // Last prepend runs first: HTTPS before install gate / session cookies on wrong scheme.
        $middleware->prepend(\App\Http\Middleware\EnsureApplicationInstalled::class);
        $middleware->prepend(\App\Http\Middleware\EnsureCanonicalHttps::class);
        $middleware->prependToPriorityList(
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \App\Http\Middleware\EnsureApplicationInstalled::class,
        );
        $middleware->append(\App\Http\Middleware\LogLocalSlowHttpMiddleware::class);
        $middleware->web(
            prepend: [
                \App\Http\Middleware\EnsureApplicationInstalled::class,
            ],
            append: [
                \App\Http\Middleware\TouchActiveUserPresence::class,
            ],
        );
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
