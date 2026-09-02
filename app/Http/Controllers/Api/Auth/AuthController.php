<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    /**
     * Login user - Mendukung multiple login methods:
     * - Admin: dengan username
     * - Peserta/Komite Teknis/Penguji: dengan No. KTP/NIK atau No. Handphone
     * POST /api/v1/auth/login
     */
    public function login(Request $request)
    {
        // Validation - accept either 'username' (for admin) or 'identifier' (KTP/HP for others)
        $validator = Validator::make($request->all(), [
            'username' => 'required_without:identifier|string',
            'identifier' => 'required_without:username|string',
            'password' => 'required|string|min:4',
        ], [
            'username.required_without' => 'Username wajib diisi (untuk admin)',
            'identifier.required_without' => 'No. KTP/NIK atau No. Handphone wajib diisi (untuk peserta/komite/penguji)',
            'password.required' => 'Password wajib diisi',
            'password.min' => 'Password minimal 4 karakter',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $username = $request->input('username');
        $identifier = $request->input('identifier');
        $password = $request->input('password');

        $user = null;

        // Admin login by username
        if ($username) {
            $user = User::where('username', $username)->active()->first();
        }
        // Peserta/Komite/Penguji login by no_pendaftaran (username) / KTP / HP
        elseif ($identifier) {
            $user = User::where(function ($query) use ($identifier) {
                $query->where('no_ktp', $identifier)
                      ->orWhere('username', $identifier)      // username = no_pendaftaran (peserta baru)
                      ->orWhere('no_induk', $identifier)      // no_induk = no_pendaftaran (peserta baru)
                      ->orWhere('no_telp', $identifier);
            })->active()->first();
        }

        // Check if user exists
        if (!$user) {
            $message = $username
                ? 'Username tidak ditemukan'
                : 'No. KTP/NIK atau No. Handphone tidak ditemukan';

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 401);
        }

        // Check password
        if (!Hash::check($password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password salah',
            ], 401);
        }

        // Determine token name based on role
        $tokenName = match(true) {
            $user->isAdmin() => 'admin-auth-token',
            $user->isKomiteTeknis() => 'komite-teknis-auth-token',
            $user->isPenguji() => 'penguji-auth-token',
            default => 'peserta-auth-token',
        };

        // Create token for API authentication
        $token = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil',
            'data' => [
                'user' => [
                    'username' => $user->username,
                    'nama_lengkap' => $user->nama_lengkap,
                    'email' => $user->email,
                    'no_hp' => $user->no_telp,
                    'no_ktp' => $user->no_ktp,
                    'role' => $user->getRoleAttribute(),
                    'status' => $user->getStatusAttribute(),
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], 200);
    }

    /**
     * Get authenticated user info
     * GET /api/v1/auth/me
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'username' => $user->username,
                'nama_lengkap' => $user->nama_lengkap,
                'email' => $user->email,
                'no_hp' => $user->no_telp,
                'no_ktp' => $user->no_ktp,
                'role' => $user->getRoleAttribute(),
                'status' => $user->getStatusAttribute(),
                'created_at' => $user->waktu,
            ],
        ]);
    }

    /**
     * Logout user
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request)
    {
        try {
            // Revoke current token
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout berhasil',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat logout',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update password tanpa memerlukan password lama
     * POST /api/v1/auth/update-password
     */
    public function updatePassword(Request $request)
    {
        // Get authenticated user from token
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'password' => 'required|min:6|confirmed',
        ], [
            'password.required' => 'Kata sandi baru wajib diisi',
            'password.min' => 'Kata sandi minimal 6 karakter',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Update password
            $user->password = Hash::make($request->password);
            $user->save();

            // Revoke all tokens for security (force user to login again)
            $user->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Kata sandi berhasil diubah',
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
