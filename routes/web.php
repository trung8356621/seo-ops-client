<?php

use App\Control\ClientLockGuard;
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
    Route::get('/workspace', \App\Http\Controllers\WorkspaceHubController::class)
        ->name('workspace.hub');
});

Route::get('/client-locked', function (ClientLockGuard $lockGuard) {
    return response()->view('client-locked', [
        'message' => $lockGuard->publicMessage(),
    ]);
})->name('client-locked');

require __DIR__.'/auth.php';

// Google Auth Routes
Route::get('auth/google', [GoogleController::class, 'redirectToGoogle'])->name('google.login');
Route::get('auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);
