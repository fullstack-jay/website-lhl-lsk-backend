<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Mail\ResetPasswordMail;
use App\Models\Asesi;
use App\Models\Asesor;
use App\Models\Komite;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ForgotPasswordController extends ApiController
{
    /**
     * Kirim link reset password ke email berdasarkan No. KTP (NIK) atau No. Handphone
     * POST /api/v1/auth/lupa-password
     */
    public function lupaPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string',
            'role' => 'nullable|string|in:peserta,penguji,komite-teknis,admin',
        ], [
            'identifier.required' => 'No. KTP / NIK atau No. Handphone wajib diisi',
            'role.in' => 'Peran pengguna tidak valid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => collect($validator->errors()->all())->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = trim($request->input('identifier'));
        $role = $request->input('role');

        // 1. Cari user di tabel users
        $userQuery = User::where(function ($query) use ($identifier) {
            $query->where('no_ktp', $identifier)
                ->orWhere('no_telp', $identifier)
                ->orWhere('username', $identifier)
                ->orWhere('no_induk', $identifier)
                ->orWhere('email', $identifier);
        });

        if ($role) {
            $targetLevel = match ($role) {
                'peserta' => 'user',
                'penguji' => 'penguji',
                'komite-teknis' => 'komite-teknis',
                'admin' => 'admin',
                default => null,
            };
            if ($targetLevel) {
                $userQuery->where('level', $targetLevel);
            }
        }

        $user = $userQuery->first();

        // 2. Jika tidak ditemukan di tabel users, coba cari di tabel spesifik dan sinkronkan
        if (!$user) {
            if (!$role || $role === 'penguji') {
                $asesor = Asesor::where(function ($q) use ($identifier) {
                    $q->where('no_ktp', $identifier)
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

            if (!$user && (!$role || $role === 'komite-teknis')) {
                $komite = Komite::where(function ($q) use ($identifier) {
                    $q->where('no_ktp', $identifier)
                        ->orWhere('no_hp', $identifier)
                        ->orWhere('no_induk', $identifier)
                        ->orWhere('email', $identifier);
                })->first();

                if ($komite) {
                    $username = $komite->no_ktp ?: ($komite->no_induk ?: $identifier);
                    $user = User::updateOrCreate(
                        ['username' => $username],
                        [
                            'password' => $komite->password ?: Hash::make('Kbl12345'),
                            'nama_lengkap' => $komite->nama,
                            'gelar_depan' => $komite->gelar_depan,
                            'gelar_blk' => $komite->gelar_blk,
                            'tmp_lahir' => $komite->tmp_lahir,
                            'tgl_lahir' => $komite->tgl_lahir,
                            'no_induk' => $komite->no_induk,
                            'no_ktp' => $komite->no_ktp,
                            'pendidikan_terakhir' => $komite->pendidikan_terakhir,
                            'email' => $komite->email,
                            'no_telp' => $komite->no_hp,
                            'level' => 'komite-teknis',
                            'blokir' => ($komite->aktif === 'N') ? 'Y' : 'N',
                        ]
                    );
                }
            }

            if (!$user && (!$role || $role === 'peserta')) {
                $asesi = Asesi::where(function ($q) use ($identifier) {
                    $q->where('no_ktp', $identifier)
                        ->orWhere('nohp', $identifier)
                        ->orWhere('no_pendaftaran', $identifier)
                        ->orWhere('email', $identifier);
                })->first();

                if ($asesi) {
                    $username = $asesi->no_pendaftaran ?: ($asesi->no_ktp ?: $identifier);
                    $user = User::updateOrCreate(
                        ['username' => $username],
                        [
                            'password' => $asesi->password ?: Hash::make('Kbl12345'),
                            'nama_lengkap' => $asesi->nama,
                            'tmp_lahir' => $asesi->tmp_lahir,
                            'tgl_lahir' => $asesi->tgl_lahir,
                            'no_induk' => $asesi->no_pendaftaran,
                            'no_ktp' => $asesi->no_ktp,
                            'pendidikan_terakhir' => $asesi->pendidikan,
                            'email' => $asesi->email,
                            'no_telp' => $asesi->nohp,
                            'level' => 'user',
                            'blokir' => ($asesi->blokir === 'Y') ? 'Y' : 'N',
                        ]
                    );
                }
            }
        }

        // 3. Jika user tetap tidak ditemukan
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No. KTP / NIK atau No. Handphone tidak ditemukan dalam sistem.',
            ], 404);
        }

        // 4. Verifikasi ketersediaan email akun
        $email = trim((string) ($user->email ?? ''));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => "Akun ditemukan atas nama {$user->nama_lengkap}, namun tidak memiliki alamat email yang valid. Silakan hubungi Administrator untuk mengatur email Anda.",
            ], 422);
        }

        // 5. Generate token reset dan simpan ke database (berlaku 60 menit)
        $token = Str::random(64);
        $expiresAt = Carbon::now()->addMinutes(60);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'identifier' => $identifier,
                'token' => $token,
                'role' => $user->level,
                'expires_at' => $expiresAt,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );

        // 6. Buat link reset password
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $resetUrl = "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($email);

        $roleName = match ($user->level) {
            'penguji' => 'Penguji',
            'komite-teknis' => 'Komite Teknis',
            'admin' => 'Administrator',
            default => 'Peserta Uji',
        };

        // 7. Kirim email reset password
        try {
            Mail::to($email)->send(new ResetPasswordMail(
                userName: $user->nama_lengkap ?: $user->username,
                resetUrl: $resetUrl,
                roleName: $roleName,
                expiresInMinutes: 60
            ));
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim email: ' . $e->getMessage(),
            ], 500);
        }

        $maskedEmail = $this->maskEmail($email);

        return response()->json([
            'success' => true,
            'message' => "Tautan reset kata sandi telah berhasil dikirim ke email Anda: {$maskedEmail}. Silakan periksa kotak masuk atau folder spam Anda.",
            'data' => [
                'masked_email' => $maskedEmail,
                'expires_in_minutes' => 60,
            ],
        ], 200);
    }

    /**
     * Validasi token reset sebelum menampilkan form reset password
     * GET /api/v1/auth/validate-reset-token
     */
    public function validateResetToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email',
        ], [
            'token.required' => 'Token reset kata sandi wajib disertakan',
            'email.required' => 'Email wajib disertakan',
            'email.email' => 'Format email tidak valid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'valid' => false,
                'message' => collect($validator->errors()->all())->first(),
            ], 422);
        }

        $token = $request->query('token');
        $email = $request->query('email');

        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $token)
            ->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'valid' => false,
                'message' => 'Tautan reset kata sandi tidak valid atau sudah pernah digunakan.',
            ], 400);
        }

        if (Carbon::now()->isAfter($record->expires_at)) {
            return response()->json([
                'success' => false,
                'valid' => false,
                'message' => 'Tautan reset kata sandi telah kadaluarsa. Silakan ajukan permintaan reset baru.',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'valid' => true,
            'data' => [
                'email' => $record->email,
                'role' => $record->role,
            ],
        ], 200);
    }

    /**
     * Proses reset password dan simpan kata sandi baru
     * POST /api/v1/auth/reset-password
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:6|confirmed',
            'password_confirmation' => 'required|string',
        ], [
            'token.required' => 'Token reset kata sandi tidak valid',
            'email.required' => 'Alamat email wajib disertakan',
            'email.email' => 'Format email tidak valid',
            'password.required' => 'Kata sandi baru wajib diisi',
            'password.min' => 'Kata sandi baru minimal 6 karakter',
            'password.confirmed' => 'Konfirmasi kata sandi baru tidak cocok',
            'password_confirmation.required' => 'Konfirmasi kata sandi baru wajib diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => collect($validator->errors()->all())->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $token = $request->input('token');
        $email = $request->input('email');
        $newPassword = $request->input('password');

        // 1. Verifikasi token di database
        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $token)
            ->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Tautan reset kata sandi tidak valid atau sudah kadaluarsa. Silakan ajukan permintaan reset baru.',
            ], 400);
        }

        if (Carbon::now()->isAfter($record->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'Tautan reset kata sandi telah kadaluarsa. Silakan ajukan permintaan reset baru.',
            ], 400);
        }

        // 2. Cari user terkait
        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Pengguna dengan email ini tidak ditemukan.',
            ], 404);
        }

        // 3. Enkripsi password baru dan dual-write ke users dan tabel terkait
        try {
            $bcrypt = Hash::make($newPassword);

            DB::transaction(function () use ($user, $bcrypt, $email) {
                // Update tabel users
                $user->password = $bcrypt;
                $user->save();

                // Dual-write ke tabel asesor (jika penguji)
                if ($user->level === 'penguji' || $user->isPenguji()) {
                    Asesor::where('email', $email)
                        ->orWhere('no_ktp', $user->no_ktp)
                        ->orWhere('no_induk', $user->no_induk)
                        ->update(['password' => $bcrypt]);
                }

                // Dual-write ke tabel komite (jika komite-teknis)
                if ($user->level === 'komite-teknis' || $user->isKomiteTeknis()) {
                    Komite::where('email', $email)
                        ->orWhere('no_ktp', $user->no_ktp)
                        ->orWhere('no_induk', $user->no_induk)
                        ->update(['password' => $bcrypt]);
                }

                // Dual-write ke tabel asesi (jika peserta)
                if ($user->level === 'user' || $user->isPeserta()) {
                    Asesi::where('email', $email)
                        ->orWhere('no_ktp', $user->no_ktp)
                        ->orWhere('no_pendaftaran', $user->username)
                        ->update(['password' => $bcrypt]);
                }

                // Revoke semua personal access token (force logout seluruh sesi login aktif)
                $user->tokens()->delete();

                // Hapus token reset dari password_reset_tokens
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Kata sandi berhasil diubah. Silakan login kembali dengan kata sandi baru Anda.',
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui kata sandi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sensor / mask email untuk keamanan tampilan (misal: j***y@gmail.com)
     */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }

        $name = $parts[0];
        $domain = $parts[1];
        $len = strlen($name);

        if ($len <= 2) {
            $maskedName = substr($name, 0, 1) . '***';
        } elseif ($len <= 4) {
            $maskedName = substr($name, 0, 1) . '**' . substr($name, -1);
        } else {
            $maskedName = substr($name, 0, 1) . str_repeat('*', min(5, $len - 2)) . substr($name, -1);
        }

        return "{$maskedName}@{$domain}";
    }
}