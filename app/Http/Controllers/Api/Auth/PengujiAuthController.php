<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use Illuminate\Http\Request;
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
        if (!$user) {
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
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'NIK / No. Handphone / No. Induk tidak ditemukan',
            ], 401);
        }

        // Check if user is penguji
        if (!$user->isPenguji()) {
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

        if (!$passwordValid) {
            return response()->json([
                'success' => false,
                'message' => 'Password salah',
            ], 401);
        }

        // Auto-upgrade legacy password to Bcrypt if needed
        if (!Hash::check($password, $user->password)) {
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
        if (!$user->isPenguji()) {
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
}
