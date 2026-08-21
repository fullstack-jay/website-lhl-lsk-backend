<?php

use App\Http\Controllers\Api\Auth;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Peserta Login (Public)
    Route::post('login', [Auth\AuthController::class, 'login']);

    // Komite Teknis Login (Public)
    Route::post('komite-teknis/login', [Auth\KomiteTeknisAuthController::class, 'login']);

    // Penguji Login (Public)
    Route::post('penguji/login', [Auth\PengujiAuthController::class, 'login']);

    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [Auth\AuthController::class, 'logout']);
        Route::get('me', [Auth\AuthController::class, 'me']);
        Route::post('update-password', [Auth\AuthController::class, 'updatePassword']);

        // Komite Teknis protected routes
        Route::get('komite-teknis/me', [Auth\KomiteTeknisAuthController::class, 'me']);

        // Penguji protected routes
        Route::get('penguji/me', [Auth\PengujiAuthController::class, 'me']);
    });
});
