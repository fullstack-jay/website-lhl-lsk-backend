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

    // Skema Sertifikasi routes
    Route::prefix('skema')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\SkemaKkniController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Api\SkemaKkniController::class, 'statistics']);
        Route::post('/', [\App\Http\Controllers\Api\SkemaKkniController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Api\SkemaKkniController::class, 'update']);
        Route::put('/{id}/toggle', [\App\Http\Controllers\Api\SkemaKkniController::class, 'toggleActive']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\SkemaKkniController::class, 'destroy']);
    });

    // Unit Kompetensi routes
    Route::prefix('unit-kompetensi')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\UnitKompetensiController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\UnitKompetensiController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Api\UnitKompetensiController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\UnitKompetensiController::class, 'destroy']);
    });

    // Elemen Kompetensi routes
    Route::prefix('elemen-kompetensi')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'destroy']);
    });

    // Kriteria Unjuk Kerja routes
    Route::prefix('kriteria-unjukkerja')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'store']);
        Route::post('/batch', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'batchStore']);
        Route::put('/{id}', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'destroy']);
    });

    // Persyaratan Peserta routes
    Route::prefix('persyaratan')->group(function () {
        Route::put('/{id}', [\App\Http\Controllers\Api\PersyaratanController::class, 'updatePeserta']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\PersyaratanController::class, 'destroyPeserta']);
    });

    // Persyaratan TUK routes
    Route::prefix('persyaratan-tuk')->group(function () {
        Route::put('/{id}', [\App\Http\Controllers\Api\PersyaratanController::class, 'updateTuk']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\PersyaratanController::class, 'destroyTuk']);
    });

    // Skema-specific routes
    Route::prefix('skema/{id}')->group(function () {
        // Persyaratan Peserta
        Route::post('/persyaratan', [\App\Http\Controllers\Api\PersyaratanController::class, 'storePeserta']);

        // Persyaratan TUK
        Route::post('/persyaratan-tuk', [\App\Http\Controllers\Api\PersyaratanController::class, 'storeTuk']);
        Route::post('/persyaratan-tuk/batch', [\App\Http\Controllers\Api\PersyaratanController::class, 'batchStoreTuk']);

        // MAPA routes
        Route::post('/mapa1a', [\App\Http\Controllers\Api\MapaController::class, 'storeMapa1a']);
        Route::delete('/mapa1a/{profil}', [\App\Http\Controllers\Api\MapaController::class, 'destroyMapa1a']);
        Route::post('/mapa1b', [\App\Http\Controllers\Api\MapaController::class, 'storeMapa1b']);
        Route::post('/mapa2', [\App\Http\Controllers\Api\MapaController::class, 'storeMapa2']);
    });

    // MAPA direct delete routes
    Route::delete('/mapa1b/{id}', [\App\Http\Controllers\Api\MapaController::class, 'destroyMapa1b']);
    Route::delete('/mapa2/{id}', [\App\Http\Controllers\Api\MapaController::class, 'destroyMapa2']);
});

/*
|--------------------------------------------------------------------------
| Biaya Routes
|--------------------------------------------------------------------------
*/
// Public routes
Route::prefix('biaya')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\BiayaController::class, 'index']);
    Route::get('/statistics', [\App\Http\Controllers\Api\BiayaController::class, 'statistics']);
    Route::get('/skema/{id}', [\App\Http\Controllers\Api\BiayaController::class, 'bySkema']);
    Route::get('/{id}', [\App\Http\Controllers\Api\BiayaController::class, 'show']);
});

Route::prefix('rekening')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\BiayaController::class, 'rekeningIndex']);
    Route::get('/bank-options', [\App\Http\Controllers\Api\BiayaController::class, 'bankOptions']);
    Route::get('/lsp/{id}', [\App\Http\Controllers\Api\BiayaController::class, 'rekeningByLsp']);
    Route::get('/{id}', [\App\Http\Controllers\Api\BiayaController::class, 'rekeningShow']);
});

Route::get('/lsp/options', [\App\Http\Controllers\Api\BiayaController::class, 'lspOptions']);

// Admin routes
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    // Biaya Sertifikasi routes
    Route::prefix('biaya')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\BiayaController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Api\BiayaController::class, 'statistics']);
        Route::post('/', [\App\Http\Controllers\Api\BiayaController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Api\BiayaController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\BiayaController::class, 'destroy']);
    });

    // Jenis Biaya routes
    Route::prefix('biaya/jenis')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\BiayaController::class, 'jenisBiaya']);
        Route::post('/', [\App\Http\Controllers\Api\BiayaController::class, 'storeJenisBiaya']);
        Route::put('/{id}', [\App\Http\Controllers\Api\BiayaController::class, 'updateJenisBiaya']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\BiayaController::class, 'destroyJenisBiaya']);
    });

    // Rekening Bank routes
    Route::prefix('rekening')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\BiayaController::class, 'rekeningIndex']);
        Route::post('/', [\App\Http\Controllers\Api\BiayaController::class, 'storeRekening']);
        Route::put('/{id}', [\App\Http\Controllers\Api\BiayaController::class, 'updateRekening']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\BiayaController::class, 'destroyRekening']);
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

/*
|--------------------------------------------------------------------------
| Skema Sertifikasi Routes
|--------------------------------------------------------------------------
*/
Route::prefix('skema')->group(function () {
    // Public routes untuk view skema
    Route::get('/', [\App\Http\Controllers\Api\SkemaKkniController::class, 'index']);
    Route::get('/options', [\App\Http\Controllers\Api\SkemaKkniController::class, 'options']);
    Route::get('/statistics', [\App\Http\Controllers\Api\SkemaKkniController::class, 'statistics']);
    Route::get('/{id}', [\App\Http\Controllers\Api\SkemaKkniController::class, 'show']);

    // Unit Kompetensi routes
    Route::prefix('unit-kompetensi')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\UnitKompetensiController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\UnitKompetensiController::class, 'show']);
    });

    // Elemen Kompetensi routes
    Route::prefix('elemen-kompetensi')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'show']);
    });

    // Kriteria Unjuk Kerja routes
    Route::prefix('kriteria-unjukkerja')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'show']);
    });

    // Persyaratan Peserta routes
    Route::get('/{id}/persyaratan', [\App\Http\Controllers\Api\PersyaratanController::class, 'indexPeserta']);

    // Persyaratan TUK routes
    Route::get('/{id}/persyaratan-tuk', [\App\Http\Controllers\Api\PersyaratanController::class, 'indexTuk']);

    // MAPA routes
    Route::get('/{id}/mapa1a/{profil?}', [\App\Http\Controllers\Api\MapaController::class, 'showMapa1a']);
    Route::get('/{id}/mapa1b', [\App\Http\Controllers\Api\MapaController::class, 'showMapa1b']);
    Route::get('/{id}/mapa2', [\App\Http\Controllers\Api\MapaController::class, 'showMapa2']);

    // Helper routes
    Route::get('/unit-kompetensi/{id}/elemen', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'byUnit']);
    Route::get('/unit-kompetensi/{id}', [\App\Http\Controllers\Api\UnitKompetensiController::class, 'bySkema']);
    Route::get('/elemen-kompetensi/{id}/kuk', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'byElemen']);

    // Kategori kandidat & MUK
    Route::get('/kategori-kandidat', [\App\Http\Controllers\Api\MapaController::class, 'kategoriKandidat']);
    Route::get('/muk', [\App\Http\Controllers\Api\MapaController::class, 'mukOptions']);
});
