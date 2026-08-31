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

        // 3 opsi identifier (idem native cek_login komite)
        $user = User::where(function ($query) use ($identifier) {
            $query->where('username', $identifier)     // username = no_ktp
                  ->orWhere('no_ktp', $identifier)
                  ->orWhere('no_telp', $identifier)    // no_hp
                  ->orWhere('no_induk', $identifier);  // No. Register
        })->active()->first();

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

        // Check password
        if (!Hash::check($password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password salah',
            ], 401);
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
