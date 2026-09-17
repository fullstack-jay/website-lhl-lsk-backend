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

    // Fitur Reset Password Otomatis via Email (Public — Peserta, Penguji, Komite Teknis)
    Route::post('lupa-password', [Auth\ForgotPasswordController::class, 'lupaPassword']);
    Route::get('validate-reset-token', [Auth\ForgotPasswordController::class, 'validateResetToken']);
    Route::post('reset-password', [Auth\ForgotPasswordController::class, 'resetPassword']);

    // Penguji Lupa Password (Public, rate-limited) — docs/BACKEND_LUPA_PASSWORD_PENGUJI.md Fitur B
    Route::post('penguji/lupa-password', [Auth\PengujiAuthController::class, 'lupaPassword'])
        ->middleware('throttle:lupa-password');

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

        // Dashboard Komite Teknis (agregasi) — docs/BACKEND_DASHBOARD_KOMITE_TEKNIS.md
        Route::get('komite-teknis/dashboard', [\App\Http\Controllers\Api\KomiteDashboardController::class, 'index']);

        // Ubah Sandi Komite Teknis — docs/BACKEND_UBAHSANDI_KOMTEKNIS.md
        Route::post('komite-teknis/ubah-password', [\App\Http\Controllers\Api\KomitePasswordController::class, 'ubahPassword']);
        Route::get('komite-teknis/profil', [\App\Http\Controllers\Api\KomiteProfilController::class, 'show']);
        Route::post('komite-teknis/profil', [\App\Http\Controllers\Api\KomiteProfilController::class, 'update']);
        Route::get('komite-teknis/hasil-asesmen', [\App\Http\Controllers\Api\KomiteHasilAsesmenController::class, 'index']);
        Route::post('komite-teknis/hasil-asesmen/{id}/rekomendasi', [\App\Http\Controllers\Api\KomiteHasilAsesmenController::class, 'storeRekomendasi']);

        // Penguji protected routes
        Route::get('penguji/me', [Auth\PengujiAuthController::class, 'me']);

        // Dashboard Penguji (agregasi 4 modul penugasan) — docs/BACKEND_DASHBOARD_PENGUJI.md
        Route::get('penguji/dashboard', [\App\Http\Controllers\Api\PengujiDashboardController::class, 'index']);

        // Penguji Ubah Password sendiri — docs/BACKEND_LUPA_PASSWORD_PENGUJI.md Fitur A
        Route::post('penguji/ubah-password', [Auth\PengujiAuthController::class, 'ubahPassword']);

        // Jadwal Uji Kompetensi penguji — docs/BACKEND_PENGUJI_JADWAL_UJI_KOMPETENSI.md
        Route::get('penguji/jadwal-uji', [\App\Http\Controllers\Api\JadwalPengujiController::class, 'index']);
        Route::get('penguji/jadwal-uji/{idJadwal}', [\App\Http\Controllers\Api\JadwalPengujiController::class, 'show']);

        // Jadwal Verifikasi TUK (verifikator) — docs/BACKEND_JADWAL_VERIFIKASI_TUK.md
        Route::get('penguji/verifikasi-tuk', [\App\Http\Controllers\Api\VerifikasiTukPengujiController::class, 'index']);
        Route::get('penguji/verifikasi-tuk/ceklis/{idJadwal}', [\App\Http\Controllers\Api\VerifikasiTukPengujiController::class, 'ceklis']);
        Route::post('penguji/verifikasi-tuk/ceklis/{idJadwal}', [\App\Http\Controllers\Api\VerifikasiTukPengujiController::class, 'simpanCeklis']);

        // Penugasan MKVA (FR.VA) — docs/BACKEND_PENUGASAN_MKVA.md
        Route::get('penguji/mkva', [\App\Http\Controllers\Api\MkvaController::class, 'index']);
        Route::get('penguji/mkva/{idJadwal}', [\App\Http\Controllers\Api\MkvaController::class, 'show']);
        Route::post('penguji/mkva/{idJadwal}', [\App\Http\Controllers\Api\MkvaController::class, 'simpanBagian1']);
        Route::get('penguji/mkva/{idJadwal}/temuan-perbaikan', [\App\Http\Controllers\Api\MkvaController::class, 'bagian2']);
        Route::post('penguji/mkva/{idJadwal}/temuan', [\App\Http\Controllers\Api\MkvaController::class, 'storeTemuan']);
        Route::delete('penguji/mkva/{idJadwal}/temuan/{id}', [\App\Http\Controllers\Api\MkvaController::class, 'destroyTemuan']);
        Route::post('penguji/mkva/{idJadwal}/perbaikan', [\App\Http\Controllers\Api\MkvaController::class, 'storePerbaikan']);
        Route::delete('penguji/mkva/{idJadwal}/perbaikan/{id}', [\App\Http\Controllers\Api\MkvaController::class, 'destroyPerbaikan']);

        // Jadwal Meninjau Instrumen Asesmen (FR.IA.11) — docs/BACKEND_JADWAL_MENINJAU_INSTRUMEN.md
        Route::get('penguji/meninjau-instrumen', [\App\Http\Controllers\Api\MeninjauInstrumenController::class, 'index']);
        Route::get('penguji/tinjau-ia11/{idJadwal}', [\App\Http\Controllers\Api\MeninjauInstrumenController::class, 'peserta']);
        Route::get('penguji/tinjau-ia11/{idJadwal}/asesi/{noPendaftaran}', [\App\Http\Controllers\Api\MeninjauInstrumenController::class, 'form']);
        Route::post('penguji/tinjau-ia11/{idJadwal}/asesi/{noPendaftaran}', [\App\Http\Controllers\Api\MeninjauInstrumenController::class, 'simpan']);

        // Form Penilaian Asesi (4 Instrumen Uji) — docs/BACKEND_FORM_PENILAIAN.md
        Route::get('penguji/jadwal/{id_jadwal}/penilaian', [\App\Http\Controllers\Api\PenilaianAsesiController::class, 'index']);
        Route::get('penguji/jadwal/{id_jadwal}/penilaian/{no_pendaftaran}', [\App\Http\Controllers\Api\PenilaianAsesiController::class, 'show']);
        Route::post('penguji/jadwal/{id_jadwal}/penilaian/{no_pendaftaran}', [\App\Http\Controllers\Api\PenilaianAsesiController::class, 'store']);

        // SMS Notifikasi / Pesan Masuk (read-only, Gammu gateway) — docs/BACKEND_PESAN_MASUK.md
        Route::get('penguji/pesan-masuk', [\App\Http\Controllers\Api\PesanMasukPengujiController::class, 'index']);
    });
});
