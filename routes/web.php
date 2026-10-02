<?php

use App\Http\Controllers\Admin\IndustryContextPromptDownloadController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/seo');

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])
    ->middleware('guest')
    ->name('login.store');

// Legacy panel login URLs → canonical /login (bookmarks / Filament leftovers).
Route::redirect('/admin/login', '/login', 302);
Route::redirect('/seeding/login', '/login', 302);
Route::redirect('/tools/login', '/login', 302);
Route::redirect('/seo/login', '/login', 302);
Route::get('/seo/{connection_hash}/login', static function (): \Illuminate\Http\RedirectResponse {
    return redirect('/login', 302);
})->where(['connection_hash' => '[a-zA-Z0-9]{32,64}']);

Route::middleware(['web', 'auth'])->group(function (): void {
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

    Route::prefix('api/support-tickets')->group(function (): void {
        Route::get('/', [\App\Http\Controllers\SupportTicketController::class, 'index'])
            ->name('support-tickets.index');
        Route::post('/', [\App\Http\Controllers\SupportTicketController::class, 'store'])
            ->name('support-tickets.store');
    });
});

require __DIR__.'/auth.php';

// Google Auth Routes
Route::get('auth/google', [GoogleController::class, 'redirectToGoogle'])->name('google.login');
Route::get('auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);
