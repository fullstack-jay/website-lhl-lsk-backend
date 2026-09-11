<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class PengujiAuthController extends ApiController
{
    /**
     * Login Penguji
     * POST /api/v1/auth/penguji/login
     *
     * Sesuai docs/BACKEND_PENGUJI.md (TL;DR):
     * Username bisa 3 hal (salah satu): no_ktp (NIK) ATAU no_hp ATAU no_induk.
     * Pada tabel users: username = no_ktp (pola akun non-admin), no_telp = no_hp,
     * dan kolom no_induk tersedia untuk No. Register Penguji.
     * Session native menyimpan no_ktp → di API ini setara dengan users.username.
     */
    public function login(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string',
            'password' => 'required|string|min:4',
        ], [
            'identifier.required' => 'NIK / No. Handphone / No. Induk wajib diisi',
            'password.required' => 'Password wajib diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = $request->input('identifier');
        $password = $request->input('password');

        // 1. Cari user di tabel users terlebih dahulu
        $user = User::where(function ($query) use ($identifier) {
            $query->where('username', $identifier)
                ->orWhere('no_ktp', $identifier)
                ->orWhere('no_telp', $identifier)
                ->orWhere('no_induk', $identifier)
                ->orWhere('email', $identifier);
        })->where('level', 'penguji')->first();

        // 2. Jika belum ada di tabel users, cari di tabel asesor dan auto-sinkronkan
        if (! $user) {
            $asesor = \App\Models\Asesor::where(function ($query) use ($identifier) {
                $query->where('no_ktp', $identifier)
                    ->orWhere('no_hp', $identifier)
                    ->orWhere('no_induk', $identifier)
                    ->orWhere('email', $identifier);
            })->first();

            if ($asesor) {
                $username = $asesor->no_ktp ?: ($asesor->no_induk ?: $identifier);
                $user = User::updateOrCreate(
                    ['username' => $username],
                    [
                        'password' => $asesor->password ?: Hash::make('Kbl12345'),
                        'nama_lengkap' => $asesor->nama,
                        'gelar_depan' => $asesor->gelar_depan,
                        'gelar_blk' => $asesor->gelar_blk,
                        'tmp_lahir' => $asesor->tmp_lahir,
                        'tgl_lahir' => $asesor->tgl_lahir,
                        'no_induk' => $asesor->no_induk,
                        'no_ktp' => $asesor->no_ktp,
                        'pendidikan_terakhir' => $asesor->pendidikan_terakhir,
                        'email' => $asesor->email,
                        'no_telp' => $asesor->no_hp,
                        'level' => 'penguji',
                        'blokir' => ($asesor->aktif ?? true) ? 'N' : 'Y',
                    ]
                );
            }
        }

        // Check if user exists
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'NIK / No. Handphone / No. Induk tidak ditemukan',
            ], 401);
        }

        // Check if user is penguji
        if (! $user->isPenguji()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus untuk Penguji.',
            ], 403);
        }

        // Check if user is blocked
        if ($user->blokir === 'Y') {
            return response()->json([
                'success' => false,
                'message' => 'Akun Penguji Anda dinonaktifkan / diblokir. Silakan hubungi Administrator.',
            ], 403);
        }

        // Check password (supports Bcrypt and legacy double-MD5)
        $passwordValid = Hash::check($password, $user->password) ||
                         md5(md5($password)) === $user->password ||
                         md5($password) === $user->password;

        if (! $passwordValid) {
            return response()->json([
                'success' => false,
                'message' => 'Password salah',
            ], 401);
        }

        // Auto-upgrade legacy password to Bcrypt if needed
        if (! Hash::check($password, $user->password)) {
            $user->password = Hash::make($password);
            $user->save();
        }

        // Create token for API authentication
        $token = $user->createToken('penguji-auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil',
            'data' => [
                'user' => [
                    // identity = session native ($_SESSION['namauser'] = no_ktp)
                    'identity' => $user->username,
                    'username' => $user->username,
                    'no_ktp' => $user->no_ktp,
                    'nama_lengkap' => $user->nama_lengkap,
                    'email' => $user->email,
                    'no_hp' => $user->no_telp,
                    'role' => $user->getRoleAttribute(),
                    'status' => $user->getStatusAttribute(),
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], 200);
    }

    /**
     * Get authenticated penguji info
     */
    public function me(Request $request)
    {
        $user = $request->user();

        // Verify user is penguji
        if (! $user->isPenguji()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'username' => $user->username,
                'nama_lengkap' => $user->nama_lengkap,
                'email' => $user->email,
                'no_hp' => $user->no_telp,
                'role' => $user->getRoleAttribute(),
                'status' => $user->getStatusAttribute(),
            ],
        ]);
    }

    /**
     * Ubah password sendiri (penguji login)
     * POST /api/v1/auth/penguji/ubah-password
     *
     * Sesuai docs/BACKEND_LUPA_PASSWORD_PENGUJI.md Fitur A — validasi 4 langkah
     * legacy (fail-fast): semua terisi → min 8 → password lama cocok → konfirmasi.
     * Perbaikan native: verifikasi server-side (hash lama TIDAK diekspos di
     * hidden input), bcrypt bukan MD5, dual-write asesor+users (login baca
     * users saja), revoke token = logout paksa.
     */
    public function ubahPassword(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }
        if (! $user->isPenguji()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus penguji.',
            ], 403);
        }

        // ── Langkah 1: Semua field terisi + min 8 ──
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

        // ── Langkah 2 (legacy 3): Password lama cocok? ──
        // md5 fallback chain idem login (mirror users bisa berisi legacy md5
        // hasil copy dari asesor)
        $passwordLamaValid = Hash::check($request->password_lama, $user->password) ||
                             md5(md5($request->password_lama)) === $user->password ||
                             md5($request->password_lama) === $user->password;

        if (! $passwordLamaValid) {
            return response()->json([
                'success' => false,
                'message' => 'Anda salah memasukkan Password Lama',
            ], 422);
        }

        // ── Langkah 3 (legacy 4): Konfirmasi cocok? ──
        if ($request->password_baru !== $request->password_ulangi) {
            return response()->json([
                'success' => false,
                'message' => 'Password baru belum cocok',
            ], 422);
        }

        try {
            $bcrypt = Hash::make($request->password_baru);

            DB::transaction(function () use ($user, $bcrypt) {
                // Dual-write: login memverifikasi users saja, tapi mirror
                // asesor tetap disinkronkan (preseden resetPassword admin)
                $user->password = $bcrypt;
                $user->save();

                \App\Models\Asesor::where('no_ktp', $user->username)
                    ->orWhere('no_ktp', $user->no_ktp)
                    ->update(['password' => $bcrypt]);
            });

            // Logout paksa: revoke semua token (harus login ulang)
            $user->tokens()->delete();

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

    /**
     * Lupa password (public, tanpa login)
     * POST /api/v1/auth/penguji/lupa-password
     *
     * Sesuai docs/BACKEND_LUPA_PASSWORD_PENGUJI.md Fitur B — security question
     * (nomorktp / tahunlahir) → generate 6 digit → update + SMS via outbox.
     * Perbaikan native:
     *   - verifikasi jawaban server-side (captcha session tidak berlaku di API)
     *   - UPDATE by id (bukan WHERE no_induk multi-row)
     *   - dual-write users (login baca users saja — tanpa ini password SMS
     *     tidak akan bisa login)
     *   - rate-limit throttle:lupa-password (brute-force guard)
     *   - fix typo $ds/$ds1 (nama kosong di SMS branch tahunlahir)
     * Keputusan desain: password baru dikembalikan di response (SMS gateway
     * sedang tidak aktif — idem legacy yang menampilkannya di layar).
     */
    public function lupaPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'npm' => 'required|string',
            'pertanyaan' => 'required|in:nomorktp,tahunlahir',
            'jawaban' => 'required|string',
        ], [
            'npm.required' => 'No. Induk Asesor wajib diisi',
            'pertanyaan.required' => 'Pilih pertanyaan keamanan',
            'pertanyaan.in' => 'Pertanyaan keamanan tidak valid',
            'jawaban.required' => 'Jawaban wajib diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => collect($validator->errors()->all())->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Cari asesor by no_induk (exclude kosong — idem legacy)
        $asesor = \App\Models\Asesor::where('no_induk', trim($request->npm))
            ->where('no_induk', '!=', '')
            ->first();

        if (! $asesor) {
            return response()->json([
                'success' => false,
                'message' => 'No. Induk tidak ditemukan',
            ], 404);
        }

        // Verifikasi jawaban security question (server-side)
        $jawaban = trim($request->jawaban);
        $jawabanBenar = match ($request->pertanyaan) {
            'nomorktp' => $jawaban === trim((string) $asesor->no_ktp),
            'tahunlahir' => $jawaban === optional($asesor->tgl_lahir)->format('Y-m-d'),
            default => false,
        };

        if (! $jawabanBenar) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Jawaban Anda Salah',
            ], 422);
        }

        // Generate password acak 6 digit (idem legacy rand(100000,999999))
        $generatepassword = (string) random_int(100000, 999999);
        $bcrypt = Hash::make($generatepassword);

        try {
            $username = $asesor->no_ktp ?: ($asesor->no_induk ?: (string) $asesor->id);

            DB::transaction(function () use ($asesor, $bcrypt, $username) {
                // UPDATE by id (perbaikan: legacy WHERE no_induk bisa multi-row)
                \App\Models\Asesor::where('id', $asesor->id)
                    ->update(['password' => $bcrypt]);

                // WAJIB: login penguji memverifikasi users.password saja
                \App\Models\User::updateOrCreate(
                    ['username' => $username],
                    [
                        'password' => $bcrypt,
                        'no_ktp' => $asesor->no_ktp,
                        'no_induk' => $asesor->no_induk,
                        'nama_lengkap' => $asesor->nama,
                        'no_telp' => $asesor->no_hp,
                        'email' => $asesor->email,
                        'level' => 'penguji',
                        'blokir' => 'N',
                    ]
                );
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses reset password',
                'error' => $e->getMessage(),
            ], 500);
        }

        // SMS via outbox — setelah commit; gagal tidak membatalkan reset
        $smsMasuk = false;
        try {
            $nohp = preg_replace('/[^0-9]/', '', (string) $asesor->no_hp);
            if (strlen($nohp) > 8) {
                DB::table('outbox')->insert([
                    'DestinationNumber' => $asesor->no_hp,
                    'TextDecoded' => 'Yth. '.$asesor->nama.', Kata sandi baru Anda adalah '
                        .$generatepassword.' Silahkan ganti password di '
                        .$request->getSchemeAndHttpHost().'/asesor',
                    'CreatorID' => 'api-laravel',
                ]);
                $smsMasuk = true;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('SMS lupa password penguji gagal: '.$e->getMessage(), [
                'asesor_id' => $asesor->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Password baru berhasil dibuat',
            'data' => [
                'password_baru' => $generatepassword,
                'no_hp' => $asesor->no_hp,
                'sms_masuk_antrean' => $smsMasuk,
            ],
        ], 200);
    }
}
