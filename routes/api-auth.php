<?php

use App\Http\Controllers\Api\Auth;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Peserta Login (Public) - Login dengan No KTP/NIK atau No HP
    Route::post('login', [Auth\AuthController::class, 'login']);

    // Admin Login (Public) - Login dengan username
    Route::post('admin/login', [Auth\AdminAuthController::class, 'login']);

    // Komite Teknis Login (Public)
    Route::post('komite-teknis/login', [Auth\KomiteTeknisAuthController::class, 'login']);

    // Penguji Login (Public)
    Route::post('penguji/login', [Auth\PengujiAuthController::class, 'login']);

    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [Auth\AuthController::class, 'logout']);
        Route::get('me', [Auth\AuthController::class, 'me']);
        Route::post('update-password', [Auth\AuthController::class, 'updatePassword']);

        // Admin protected routes
        Route::get('admin/me', [Auth\AdminAuthController::class, 'me']);

        // Admin ubah kata sandi sendiri (modul password — verifikasi password lama)
        Route::post('admin/ubah-password', [Auth\AdminAuthController::class, 'ubahPassword']);

        // Komite Teknis protected routes
        Route::get('komite-teknis/me', [Auth\KomiteTeknisAuthController::class, 'me']);

        // Penguji protected routes
        Route::get('penguji/me', [Auth\PengujiAuthController::class, 'me']);
    });
});
