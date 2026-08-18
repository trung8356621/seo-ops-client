<?php

use App\Http\Controllers\Control\ControlCommandController;
use Illuminate\Support\Facades\Route;

Route::post('/commands', [ControlCommandController::class, 'store'])
    ->name('control.v1.commands');
