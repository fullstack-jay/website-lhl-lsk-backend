<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Komite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Ubah Sandi Sendiri (Portal KOMITE TEKNIS)
 * POST /api/v1/komite-teknis/ubah-password  (auth:sanctum, level komite-teknis)
 * Alias: POST /api/v1/auth/komite-teknis/ubah-password
 * Sesuai docs/BACKEND_UBAHSANDI_KOMTEKNIS.md — mirror penguji
 * (PengujiAuthController::ubahPassword, docs/API_LUPA_PASSWORD_PENGUJI.md Fitur A).
 *
 * Alur: guard → validasi legacy fail-fast (kosong → min 8 → konfirmasi) →
 * verifikasi password lama (bcrypt + fallback md5/md5-md5 legacy) →
 * DB::transaction DUAL-WRITE users + komite (users.password = komite.password
 * selalu — anti partial-save, idem resetPassword admin) → revoke SEMUA token
 * sanctum (logout paksa) → SMS notifikasi via outbox (opsional, non-blocking).
 *
 * Adaptasi kolom legacy: `users.password` varchar(60) cukup utk bcrypt(60);
 * `komite.password` text. Akun legacy md5 otomatis di-upgrade ke bcrypt.
 */
class KomitePasswordController extends Controller
{
    public function ubahPassword(Request $request): JsonResponse
    {
        // ── Guard (pola user('sanctum') ?? user() — idem dashboard komite) ──
        $user = $request->user('sanctum') ?? $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }
        if (!$user->isKomiteTeknis()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus Komite Teknis.',
            ], 403);
        }

        // ── Validasi fail-fast — pesan legacy (idem penguji) ──
        $validator = Validator::make($request->all(), [
            'password_lama' => 'required',
            'password_baru' => 'required|min:8',
            'password_ulangi' => 'required',
        ], [
            'password_lama.required' => 'Anda harus mengisikan semua data',
            'password_baru.required' => 'Anda harus mengisikan semua data',
            'password_baru.min' => 'Password minimal 8 karakter',
            'password_ulangi.required' => 'Anda harus mengisikan semua data',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => collect($validator->errors()->all())->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // ── Konfirmasi cocok? ──
        if ($request->password_baru !== $request->password_ulangi) {
            return response()->json([
                'success' => false,
                'message' => 'Password baru belum cocok',
            ], 422);
        }

        // ── Verifikasi password lama (bcrypt + fallback md5 legacy, idem login) ──
        $passwordLamaValid = Hash::check($request->password_lama, $user->password) ||
            md5(md5($request->password_lama)) === $user->password ||
            md5($request->password_lama) === $user->password;

        if (!$passwordLamaValid) {
            return response()->json([
                'success' => false,
                'message' => 'Anda salah memasukkan Password Lama',
            ], 422);
        }

        try {
            // bcrypt SELALU (upgrade otomatis dari md5 legacy)
            $bcrypt = Hash::make($request->password_baru);

            // Resolusi mirror komite (idem login 3-opsi)
            $komite = Komite::where(function ($q) use ($user) {
                $q->where('no_ktp', $user->username)
                    ->orWhere('no_hp', $user->username)
                    ->orWhere('no_induk', $user->username);
                if (!empty($user->no_ktp) && $user->no_ktp !== $user->username) {
                    $q->orWhere('no_ktp', $user->no_ktp);
                }
            })->first();

            DB::transaction(function () use ($user, $komite, $bcrypt) {
                // Dual-write: login verifikasi users, mirror komite wajib sinkron
                // (preseden resetPassword admin: users.password = komite.password)
                $user->password = $bcrypt;
                $user->save();

                if ($komite) {
                    $komite->password = $bcrypt;
                    $komite->save();
                }
            });

            if (!$komite) {
                // Bila mirror tidak ketemu → users saja sudah ter-commit (login aman)
                Log::warning('Ubah sandi komite: mirror komite tidak ditemukan', [
                    'user_id' => $user->username,
                ]);
            }

            // Logout paksa: revoke SEMUA token (perangkat lain ikut terlogout)
            $user->tokens()->delete();

            // SMS notifikasi (opsional, non-blocking) — antrean Gammu outbox
            try {
                if ($komite && strlen((string) $komite->no_hp) > 8) {
                    DB::table('outbox')->insert([
                        'DestinationNumber' => $komite->no_hp,
                        'TextDecoded' => 'Password akun Komite Teknis Anda baru saja diubah. Bila bukan Anda, hubungi admin LSK.',
                        'CreatorID' => 'api-laravel',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Ubah sandi komite: SMS outbox gagal (diabaikan)', [
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Ganti Password Berhasil, silahkan login kembali',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui kata sandi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
