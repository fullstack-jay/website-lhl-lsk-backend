<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Pendaftaran Routes
|--------------------------------------------------------------------------
*/
Route::prefix('pendaftaran')->group(function () {
    // Public route untuk submit form
    Route::post('/', [\App\Http\Controllers\Api\PendaftaranController::class, 'store']);

    // Cek status pendaftaran (public)
    Route::get('/{no_pendaftaran}', [\App\Http\Controllers\Api\PendaftaranController::class, 'show']);

    // Admin routes (perlu authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\PendaftaranController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Api\PendaftaranController::class, 'statistics']);
        Route::put('/{id}/status', [\App\Http\Controllers\Api\PendaftaranController::class, 'updateStatus']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\PendaftaranController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| Pengaduan Routes
|--------------------------------------------------------------------------
*/
Route::prefix('pengaduan')->group(function () {
    // Public route untuk submit pengaduan
    Route::post('/', [\App\Http\Controllers\Api\PengaduanController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Admin Routes (require authentication & admin role)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    // Pengaduan routes
    Route::prefix('pengaduan')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\PengaduanController::class, 'index']);
        Route::get('/counts', [\App\Http\Controllers\Api\PengaduanController::class, 'counts']);
        Route::get('/{id}', [\App\Http\Controllers\Api\PengaduanController::class, 'show']);
        Route::post('/{id}/respon', [\App\Http\Controllers\Api\PengaduanController::class, 'respon']);
        Route::put('/{id}/status', [\App\Http\Controllers\Api\PengaduanController::class, 'updateStatus']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\PengaduanController::class, 'destroy']);
    });

    // Mutudoc routes (Dokumen Mutu)
    Route::prefix('mutudoc')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\MutudocController::class, 'index']);
        Route::get('/grouped', [\App\Http\Controllers\Api\MutudocController::class, 'grouped']);
        Route::post('/', [\App\Http\Controllers\Api\MutudocController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Api\MutudocController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\MutudocController::class, 'destroy']);
    });

    // SKKNI routes (Standar Kompetensi)
    Route::prefix('skkni')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\SkkniController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\SkkniController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Api\SkkniController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\SkkniController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| Public Dokumen Mutu Routes
|--------------------------------------------------------------------------
*/
Route::prefix('mutudoc')->group(function () {
    // Public routes untuk view dokumen
    Route::get('/', [\App\Http\Controllers\Api\MutudocController::class, 'index']);
    Route::get('/grouped', [\App\Http\Controllers\Api\MutudocController::class, 'grouped']);
    Route::get('/jenis', [\App\Http\Controllers\Api\MutudocController::class, 'jenisList']);
    Route::get('/kategori', [\App\Http\Controllers\Api\MutudocController::class, 'kategoriList']);
    Route::get('/{id}', [\App\Http\Controllers\Api\MutudocController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Public SKKNI Routes
|--------------------------------------------------------------------------
*/
Route::prefix('skkni')->group(function () {
    // Public routes untuk view standar kompetensi
    Route::get('/', [\App\Http\Controllers\Api\SkkniController::class, 'index']);
    Route::get('/jenis', [\App\Http\Controllers\Api\SkkniController::class, 'jenisList']);
    Route::get('/{id}', [\App\Http\Controllers\Api\SkkniController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Public Dokumen Mutu Routes
|--------------------------------------------------------------------------
*/
Route::prefix('mutudoc')->group(function () {
    // Public routes untuk view dokumen
    Route::get('/', [\App\Http\Controllers\Api\MutudocController::class, 'index']);
    Route::get('/grouped', [\App\Http\Controllers\Api\MutudocController::class, 'grouped']);
    Route::get('/jenis', [\App\Http\Controllers\Api\MutudocController::class, 'jenisList']);
    Route::get('/kategori', [\App\Http\Controllers\Api\MutudocController::class, 'kategoriList']);
    Route::get('/{id}', [\App\Http\Controllers\Api\MutudocController::class, 'show']);
});
