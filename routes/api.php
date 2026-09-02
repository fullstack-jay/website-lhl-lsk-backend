<?php

// Master Keahlian Penyusun
Route::get('/keahlian-penyusun', [\App\Http\Controllers\Api\MasterKeahlianController::class, 'index']);
Route::post('/keahlian-penyusun', [\App\Http\Controllers\Api\MasterKeahlianController::class, 'store']);

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
        // Specific routes must come before parameterized routes
        Route::get('/check-duplicate', [\App\Http\Controllers\Api\UnitKompetensiController::class, 'checkDuplicate']);
        Route::get('/{id}', [\App\Http\Controllers\Api\UnitKompetensiController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\UnitKompetensiController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\UnitKompetensiController::class, 'destroy']);
        Route::get('/{id}/statistics', [\App\Http\Controllers\Api\UnitKompetensiController::class, 'statistics']);
    });

    // Elemen Kompetensi routes
    Route::prefix('elemen-kompetensi')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'destroy']);
        Route::get('/unit/{unitId}', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'byUnit']);
    });

    // Kriteria Unjuk Kerja routes
    Route::prefix('kriteria-unjukkerja')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'store']);
        Route::post('/batch', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'batchStore']);
        Route::get('/{id}', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'destroy']);
        Route::get('/elemen/{elemenId}', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'byElemen']);
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
        Route::post('/mapa1c', [\App\Http\Controllers\Api\MapaController::class, 'storeMapa1c']);
        Route::post('/mapa2', [\App\Http\Controllers\Api\MapaController::class, 'storeMapa2']);
    });

    // MAPA direct delete routes
    Route::delete('/mapa1b/{id}', [\App\Http\Controllers\Api\MapaController::class, 'destroyMapa1b']);
    Route::delete('/mapa2/{id}', [\App\Http\Controllers\Api\MapaController::class, 'destroyMapa2']);

    // Penguji (Asesor) routes — sesuai docs/BACKEND_PENGUJI.md
    Route::prefix('penguji')->group(function () {
        // Specific routes BEFORE parameterized {id}
        Route::get('/statistics', [\App\Http\Controllers\Api\PengujiController::class, 'statistics']);

        Route::get('/', [\App\Http\Controllers\Api\PengujiController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\PengujiController::class, 'store']);   // reserved: registrasi baru

        Route::delete('/penugasan-skema/{id}', [\App\Http\Controllers\Api\PengujiController::class, 'destroyPenugasanSkema']);

        Route::get('/{id}', [\App\Http\Controllers\Api\PengujiController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\PengujiController::class, 'update']);
        Route::post('/{id}', [\App\Http\Controllers\Api\PengujiController::class, 'update']);  // FormData compatibility (_method=PUT)
        Route::delete('/{id}', [\App\Http\Controllers\Api\PengujiController::class, 'destroy']);

        Route::post('/{id}/reset-password', [\App\Http\Controllers\Api\PengujiController::class, 'resetPassword']);

        Route::get('/{id}/penugasan-skema/halaman', [\App\Http\Controllers\Api\PengujiController::class, 'halamanPenugasanSkema']);
        Route::get('/{id}/penugasan-skema', [\App\Http\Controllers\Api\PengujiController::class, 'indexPenugasanSkema']);
        Route::post('/{id}/penugasan-skema', [\App\Http\Controllers\Api\PengujiController::class, 'storePenugasanSkema']);
    });

    // Komite Teknis routes — sesuai docs/BACKEND_KOMITETEKNIS.md
    Route::prefix('komite')->group(function () {
        Route::get('/statistics', [\App\Http\Controllers\Api\KomiteController::class, 'statistics']);

        Route::get('/', [\App\Http\Controllers\Api\KomiteController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\KomiteController::class, 'store']);

        Route::get('/{id}', [\App\Http\Controllers\Api\KomiteController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\KomiteController::class, 'update']);
        Route::post('/{id}', [\App\Http\Controllers\Api\KomiteController::class, 'update']);  // FormData compatibility
        Route::delete('/{id}', [\App\Http\Controllers\Api\KomiteController::class, 'destroy']);

        Route::post('/{id}/reset-password', [\App\Http\Controllers\Api\KomiteController::class, 'resetPassword']);
    });

    // Laporan Transaksi Keuangan (inlapkeu) routes — sesuai docs/BACKEND_KEUANGAN.md
    Route::prefix('keuangan')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\KeuTransaksiController::class, 'index']);
        Route::get('/ringkasan', [\App\Http\Controllers\Api\KeuTransaksiController::class, 'ringkasan']);
        Route::get('/kode-akun', [\App\Http\Controllers\Api\KeuTransaksiController::class, 'kodeAkun']);

        Route::post('/', [\App\Http\Controllers\Api\KeuTransaksiController::class, 'store']);

        Route::get('/{id}', [\App\Http\Controllers\Api\KeuTransaksiController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\KeuTransaksiController::class, 'update']);
        Route::post('/{id}', [\App\Http\Controllers\Api\KeuTransaksiController::class, 'update']);  // FormData compatibility
        Route::delete('/{id}', [\App\Http\Controllers\Api\KeuTransaksiController::class, 'destroy']);
    });

    // Pengaturan Dokumen Pokok Peserta (devsyarat) routes — sesuai docs/BACKEND_DEVSYARAT.md
    Route::prefix('devsyarat')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\DevSyaratController::class, 'index']);
        Route::get('/aktif', [\App\Http\Controllers\Api\DevSyaratController::class, 'aktif']);
        Route::get('/kelengkapan/{noPendaftaran}', [\App\Http\Controllers\Api\DevSyaratController::class, 'kelengkapan']);

        // 4 handler toggle (konfigurasi-only — tanpa create/delete)
        Route::put('/{id}/wajibkan', [\App\Http\Controllers\Api\DevSyaratController::class, 'wajibkan']);
        Route::put('/{id}/tidak-wajibkan', [\App\Http\Controllers\Api\DevSyaratController::class, 'tidakWajibkan']);
        Route::put('/{id}/aktifkan', [\App\Http\Controllers\Api\DevSyaratController::class, 'aktifkan']);
        Route::put('/{id}/nonaktifkan', [\App\Http\Controllers\Api\DevSyaratController::class, 'nonaktifkan']);
    });

    // Manajemen Pengguna (users internal) routes — sesuai docs/BACKEND_MANAJEMEN_PENGGUNA.md
    Route::prefix('pengguna')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\UserManagementController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\UserManagementController::class, 'store']);

        Route::get('/{username}', [\App\Http\Controllers\Api\UserManagementController::class, 'show']);
        Route::put('/{username}', [\App\Http\Controllers\Api\UserManagementController::class, 'update']);
        Route::post('/{username}', [\App\Http\Controllers\Api\UserManagementController::class, 'update']);  // FormData compatibility
        Route::delete('/{username}', [\App\Http\Controllers\Api\UserManagementController::class, 'destroy']);

        Route::get('/{username}/hak-akses', [\App\Http\Controllers\Api\UserManagementController::class, 'hakAkses']);
        Route::post('/{username}/hak-akses', [\App\Http\Controllers\Api\UserManagementController::class, 'tambahHakAkses']);
        Route::delete('/{username}/hak-akses/{idModul}', [\App\Http\Controllers\Api\UserManagementController::class, 'hapusHakAkses']);
    });

    // Konten Frontpage (admin) routes — sesuai docs/BACKEND_KONTENFRONTPAGE.md
    Route::prefix('konten')->group(function () {
        Route::get('/kategori', [\App\Http\Controllers\Api\FrontpageController::class, 'kategori']);

        Route::get('/', [\App\Http\Controllers\Api\FrontpageController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\FrontpageController::class, 'store']);

        Route::get('/{id}', [\App\Http\Controllers\Api\FrontpageController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\FrontpageController::class, 'update']);
        Route::post('/{id}', [\App\Http\Controllers\Api\FrontpageController::class, 'update']);  // FormData compatibility
        Route::delete('/{id}', [\App\Http\Controllers\Api\FrontpageController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| Public Frontpage Routes (halaman depan — tanpa auth)
|--------------------------------------------------------------------------
*/
Route::prefix('frontpage')->group(function () {
    Route::get('/{slug}', [\App\Http\Controllers\Api\FrontpageController::class, 'byKategoriSlug']);
});

/*
|--------------------------------------------------------------------------
| Peserta Portal Routes (auth peserta — BUKAN admin/peserta/*)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->prefix('peserta')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Api\PesertaDashboardController::class, 'index']);
    Route::get('/profil', [\App\Http\Controllers\Api\PesertaProfilController::class, 'show']);
    Route::post('/profil', [\App\Http\Controllers\Api\PesertaProfilController::class, 'update']);
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
    Route::get('/jenis', [\App\Http\Controllers\Api\BiayaController::class, 'jenisBiaya']);
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

// LSK routes (public)
Route::prefix('lsp')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\LspController::class, 'index']);
    Route::get('/statistics', [\App\Http\Controllers\Api\LspController::class, 'statistics']);
    Route::get('/options', [\App\Http\Controllers\Api\LspController::class, 'options']);
    Route::get('/{id}', [\App\Http\Controllers\Api\LspController::class, 'show']);
});

// Wilayah routes (public)
Route::prefix('wilayah')->group(function () {
    Route::get('/provinsi', [\App\Http\Controllers\Api\WilayahController::class, 'getProvinsi']);
    Route::get('/kota/{provinsiId}', [\App\Http\Controllers\Api\WilayahController::class, 'getKota']);
    Route::get('/kecamatan/{kotaId}', [\App\Http\Controllers\Api\WilayahController::class, 'getKecamatan']);
    Route::get('/detail/{id}', [\App\Http\Controllers\Api\WilayahController::class, 'getDetail']);
});

// Admin routes
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    // LSK routes (admin only)
    Route::prefix('lsp')->group(function () {
        Route::post('/', [\App\Http\Controllers\Api\LspController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Api\LspController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\LspController::class, 'destroy']);
    });

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
    Route::get('/versi-terakhir', [\App\Http\Controllers\Api\MutudocController::class, 'versiTerakhir']);
    Route::get('/tanpa-berkas', [\App\Http\Controllers\Api\MutudocController::class, 'tanpaBerkas']);
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
    Route::get('/options', [\App\Http\Controllers\Api\SkkniController::class, 'options']);
    Route::get('/{id}', [\App\Http\Controllers\Api\SkkniController::class, 'show']);
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
    Route::get('/{id}/mapa1c/{profil?}', [\App\Http\Controllers\Api\MapaController::class, 'showMapa1c']);
    Route::get('/{id}/mapa2', [\App\Http\Controllers\Api\MapaController::class, 'showMapa2']);

    // Helper routes
    Route::get('/unit-kompetensi/{id}/elemen', [\App\Http\Controllers\Api\ElemenKompetensiController::class, 'byUnit']);
    Route::get('/unit-kompetensi/{id}', [\App\Http\Controllers\Api\UnitKompetensiController::class, 'bySkema']);
    Route::get('/elemen-kompetensi/{id}/kuk', [\App\Http\Controllers\Api\KriteriaUnjukkerjaController::class, 'byElemen']);

    // Kategori kandidat & MUK
    Route::get('/kategori-kandidat', [\App\Http\Controllers\Api\MapaController::class, 'kategoriKandidat']);
    Route::get('/muk', [\App\Http\Controllers\Api\MapaController::class, 'mukOptions']);
});

/*
|--------------------------------------------------------------------------
| Event Routes
|--------------------------------------------------------------------------
*/
Route::prefix('event')->group(function () {
    // Public routes
    Route::get('/', [\App\Http\Controllers\Api\EventController::class, 'index']);
    Route::get('/statistics', [\App\Http\Controllers\Api\EventController::class, 'statistics']);
    Route::get('/{id}', [\App\Http\Controllers\Api\EventController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Jadwal Asesmen Routes
|--------------------------------------------------------------------------
*/
Route::prefix('jadwal')->group(function () {
    // Public routes
    Route::get('/', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'index']);
    Route::get('/statistics', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'statistics']);
    Route::get('/options', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'options']);
    Route::get('/{id}', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| TUK Routes
|--------------------------------------------------------------------------
*/
Route::prefix('tuk')->group(function () {
    // Public routes
    Route::get('/', [\App\Http\Controllers\Api\TukController::class, 'index']);
    Route::get('/statistics', [\App\Http\Controllers\Api\TukController::class, 'statistics']);
    Route::get('/options', [\App\Http\Controllers\Api\TukController::class, 'options']);
    Route::get('/{id}', [\App\Http\Controllers\Api\TukController::class, 'show']);
});

// Admin routes
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    // Event routes (admin only)
    Route::prefix('event')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\EventController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Api\EventController::class, 'statistics']);
        Route::get('/{id}', [\App\Http\Controllers\Api\EventController::class, 'show']);
    });

    // Jadwal Asesmen routes (admin only) - using 'jadwal' prefix
    Route::prefix('jadwal')->group(function () {
        Route::post('/', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'destroy']);
        Route::put('/{id}/status', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'updateStatus']);
    });

    // Jadwal Asesmen routes (admin only) - using 'jadwal-asesmen' prefix (frontend expects this)
    Route::prefix('jadwal-asesmen')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'statistics']);
        Route::get('/options', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'options']);
        Route::get('/{id}', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'destroy']);
        Route::put('/{id}/status', [\App\Http\Controllers\Api\JadwalAsesmenController::class, 'updateStatus']);
    });

    // TUK routes (admin only)
    Route::prefix('tuk')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\TukController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Api\TukController::class, 'statistics']);
        Route::get('/options', [\App\Http\Controllers\Api\TukController::class, 'options']);
        Route::get('/{id}', [\App\Http\Controllers\Api\TukController::class, 'show']);
        Route::get('/{skemaId}/persyaratan', [\App\Http\Controllers\Api\TukController::class, 'persyaratanBySkema']);
        Route::post('/', [\App\Http\Controllers\Api\TukController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Api\TukController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\TukController::class, 'destroy']);
    });

    // Persyaratan TUK routes (admin only)
    Route::prefix('persyaratan-tuk')->group(function () {
        Route::get('/{skemaId}', [\App\Http\Controllers\Api\PersyaratanTukController::class, 'index']);
        Route::get('/detail/{id}', [\App\Http\Controllers\Api\PersyaratanTukController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Api\PersyaratanTukController::class, 'store']);
        Route::post('/batch', [\App\Http\Controllers\Api\PersyaratanTukController::class, 'batchStore']);
        Route::put('/{id}', [\App\Http\Controllers\Api\PersyaratanTukController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\PersyaratanTukController::class, 'destroy']);
    });

    // Peserta/Asesi routes (admin only)
    Route::prefix('peserta')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\AsesiController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Api\AsesiController::class, 'statistics']);
        Route::get('/{noPendaftaran}', [\App\Http\Controllers\Api\AsesiController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Api\AsesiController::class, 'store']);
        Route::put('/{noPendaftaran}', [\App\Http\Controllers\Api\AsesiController::class, 'update']);
        Route::delete('/{noPendaftaran}', [\App\Http\Controllers\Api\AsesiController::class, 'destroy']);
        Route::put('/{id}/blokir', [\App\Http\Controllers\Api\AsesiController::class, 'updateBlokir']);
        Route::put('/{noPendaftaran}/verifikasi', [\App\Http\Controllers\Api\AsesiController::class, 'updateVerifikasi']);
    });

    // Calon Peserta Baru (asesibaru) routes — sesuai docs/BACKEND_CALONPESERTABARU.md
    Route::prefix('asesi-baru')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\AsesiBaruController::class, 'index']);
        Route::put('/{id}/blokir', [\App\Http\Controllers\Api\AsesiBaruController::class, 'updateBlokir']);
        Route::get('/{id}/kontak', [\App\Http\Controllers\Api\AsesiBaruController::class, 'kontak']);
        Route::post('/{id}/sms', [\App\Http\Controllers\Api\AsesiBaruController::class, 'kirimSms']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\AsesiBaruController::class, 'destroy']);
        Route::delete('/{id}/pendaftaran/{idSkema}', [\App\Http\Controllers\Api\AsesiBaruController::class, 'destroyPendaftaran']);
    });
});
