<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class KomiteTeknisAuthController extends ApiController
{
    /**
     * Login Komite Teknis
     * POST /api/v1/auth/komite-teknis/login
     *
     * Sesuai docs/BACKEND_KOMITETEKNIS.md: username bisa 3 hal (salah satu):
     * no_ktp (NIK) ATAU no_hp ATAU no_induk. Session native menyimpan no_ktp
     * → di API setara users.username.
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
            $query->where('username', $identifier)     // username = no_ktp
                  ->orWhere('no_ktp', $identifier)
                  ->orWhere('no_telp', $identifier)    // no_hp
                  ->orWhere('no_induk', $identifier)   // No. Register
                  ->orWhere('email', $identifier);
        })->where('level', 'komite-teknis')->first();

        // 2. Jika belum ada di tabel users, cari di tabel komite dan auto-sinkronkan
        if (!$user) {
            $komite = \App\Models\Komite::where(function ($query) use ($identifier) {
                $query->where('no_ktp', $identifier)
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

        // Check if user exists
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'NIK / No. Handphone / No. Induk tidak ditemukan',
            ], 401);
        }

        // Check if user is komite teknis
        if (!$user->isKomiteTeknis()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus untuk Komite Teknis.',
            ], 403);
        }

        // Check if user is blocked
        if ($user->blokir === 'Y') {
            return response()->json([
                'success' => false,
                'message' => 'Akun Komite Teknis Anda dinonaktifkan / diblokir. Silakan hubungi Administrator.',
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
        $token = $user->createToken('komite-teknis-auth-token')->plainTextToken;

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
     * Get authenticated komite teknis info
     */
    public function me(Request $request)
    {
        $user = $request->user();

        // Verify user is komite teknis
        if (!$user->isKomiteTeknis()) {
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
