<?php

use App\Control\ClientLockGuard;
use App\Http\Controllers\Auth\GoogleController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/seo');

Route::get('/client-locked', function (ClientLockGuard $lockGuard) {
    return response()->view('client-locked', [
        'message' => $lockGuard->publicMessage(),
    ]);
})->name('client-locked');

require __DIR__.'/auth.php';

// Google Auth Routes
Route::get('auth/google', [GoogleController::class, 'redirectToGoogle'])->name('google.login');
Route::get('auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);
