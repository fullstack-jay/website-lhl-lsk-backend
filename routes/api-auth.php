<?php

use App\Http\Controllers\Api\Auth;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Public routes
    Route::post('login', [Auth\AuthController::class, 'login']);

    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [Auth\AuthController::class, 'logout']);
        Route::get('me', [Auth\AuthController::class, 'me']);
        Route::post('update-password', [Auth\AuthController::class, 'updatePassword']);
    });
});
