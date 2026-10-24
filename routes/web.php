<?php

use App\Http\Controllers\Admin\IndustryContextPromptDownloadController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', static function (\App\Core\Installation\InstallationStateManager $stateManager) {
    if ($stateManager->currentStatus() === \App\Core\Installation\InstallationState::DATABASE_UNAVAILABLE) {
        return response()->view('setup.maintenance', [
            'title' => 'Bảo trì hệ thống — Database Unavailable',
            'message' => 'Không thể kết nối đến cơ sở dữ liệu. Hệ thống đang tạm thời ngoại tuyến để bảo trì.',
        ], 503);
    }

    if ($stateManager->currentStatus() !== \App\Core\Installation\InstallationState::INSTALLED) {
        return redirect('/setup');
    }

    return redirect('/seo');
});

require __DIR__.'/setup.php';

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])
    ->middleware('guest')
    ->name('login.store');

// Local browser verification only — never register outside local/testing.
if (app()->environment(['local', 'testing'])) {
    Route::get('/__local/login-as/{userId}', static function (int $userId): \Illuminate\Http\RedirectResponse {
        abort_unless(app()->environment(['local', 'testing']), 404);
        $user = \App\Models\User::query()->findOrFail($userId);
        auth()->login($user, true);

        return redirect('/admin');
    })->whereNumber('userId')->middleware('web');
}

// Legacy panel login URLs → canonical /login (bookmarks / Filament leftovers).
Route::redirect('/admin/login', '/login', 302);
Route::redirect('/seeding/login', '/login', 302);
Route::redirect('/tools/login', '/login', 302);
Route::redirect('/seo/login', '/login', 302);
Route::get('/seo/{connection_hash}/login', static function (): \Illuminate\Http\RedirectResponse {
    return redirect('/login', 302);
})->where(['connection_hash' => '[a-zA-Z0-9]{32,64}']);

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::post('/performance/presence', \App\Http\Controllers\PerformancePresenceController::class)
        ->name('performance.presence');

    Route::get('/admin/industry-context-prompts/create', [IndustryContextPromptDownloadController::class, 'create'])
        ->name('admin.industry-context.prompt.create');
    Route::get('/admin/industry-context-profiles/{key}/prompt/{type}', [IndustryContextPromptDownloadController::class, 'existing'])
        ->where('key', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->where('type', 'core|discovery|breakout|match')
        ->name('admin.industry-context.prompt.download');
    Route::get('/admin/industry-context-profiles/{profile}/json', [IndustryContextPromptDownloadController::class, 'json'])
        ->whereNumber('profile')
        ->name('admin.industry-context.json.download');

    Route::get('/workspace', \App\Http\Controllers\WorkspaceHubController::class)
        ->name('workspace.hub');

    Route::post('/api/service-widget/session', [\App\Http\Controllers\ServiceWidgetSessionController::class, 'store'])
        ->name('service-widget.session');

    Route::post('/api/support-widget/session', [\App\Http\Controllers\SupportWidgetSessionController::class, 'store'])
        ->name('support-widget.session');
});


require __DIR__.'/auth.php';

// Google Auth Routes
Route::get('auth/google', [GoogleController::class, 'redirectToGoogle'])->name('google.login');
Route::get('auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);
