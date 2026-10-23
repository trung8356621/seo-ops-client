<?php

declare(strict_types=1);

use App\Http\Controllers\Setup\SetupWizardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->group(function (): void {
    Route::get('/setup', [SetupWizardController::class, 'index'])->name('setup.index');

    Route::prefix('setup/api')->group(function (): void {
        Route::get('/status', [SetupWizardController::class, 'status'])->name('setup.api.status');
        Route::post('/verify-token', [SetupWizardController::class, 'verifyToken'])->name('setup.api.verify_token');
        Route::post('/database/test-single', [SetupWizardController::class, 'testSingleDatabase'])->name('setup.api.database.test_single');
        Route::post('/database', [SetupWizardController::class, 'configureDatabase'])->name('setup.api.database');
        Route::post('/initialize', [SetupWizardController::class, 'initializeApp'])->name('setup.api.initialize');
        Route::post('/admin', [SetupWizardController::class, 'createAdmin'])->name('setup.api.admin');
        Route::post('/primary-site', [SetupWizardController::class, 'registerPrimarySite'])->name('setup.api.primary_site');
        Route::post('/finish', [SetupWizardController::class, 'finish'])->name('setup.api.finish');
    });
});
