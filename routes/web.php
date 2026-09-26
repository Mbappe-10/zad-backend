<?php

use App\Http\Controllers\Api\App\LaunchTrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'ZADSYNC API',
    'status' => 'ok',
]));

Route::get('/m/{code}', [LaunchTrackingController::class, 'redirect'])
    ->where('code', '[A-Za-z0-9_-]+')
    ->middleware('throttle:120,1')
    ->name('launch.redirect');

// Route::prefix('auth')->group(function (): void {
//  Route::post('/login', [AuthController::class, 'login'])
//  ->middleware('throttle:login')
// ->name('auth.login');

// });
