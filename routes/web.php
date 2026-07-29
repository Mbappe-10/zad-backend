<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'ZAD API',
    'status' => 'ok',
]));

// Route::prefix('auth')->group(function (): void {
//  Route::post('/login', [AuthController::class, 'login'])
//  ->middleware('throttle:login')
// ->name('auth.login');

// });
