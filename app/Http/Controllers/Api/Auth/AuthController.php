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
     * Login user dengan No. KTP/NIK atau No. Handphone + Password
     * POST /api/v1/auth/login
     */
    public function login(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string',
            'password' => 'required|string|min:4',
        ], [
            'identifier.required' => 'No. KTP/NIK atau No. Handphone wajib diisi',
            'identifier.string' => 'Format identifier tidak valid',
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

        $identifier = $request->input('identifier');
        $password = $request->input('password');

        // Find user by KTP or phone number
        $user = User::where(function ($query) use ($identifier) {
            $query->where('no_ktp', $identifier)
                  ->orWhere('no_telp', $identifier);
        })->active()->first();

        // Check if user exists
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No. KTP/NIK atau No. Handphone tidak ditemukan',
            ], 401);
        }

        // Check if user is peserta (user role only)
        if (!$user->isPeserta()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus untuk Peserta.',
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
        $token = $user->createToken('auth-token')->plainTextToken;

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
     * Update password
     * POST /api/v1/auth/update-password
     */
    public function updatePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'password' => 'required|string|min:4',
        ], [
            'current_password.required' => 'Password saat ini wajib diisi',
            'password.required' => 'Password baru wajib diisi',
            'password.min' => 'Password minimal 4 karakter',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        // Check current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password saat ini salah',
            ], 401);
        }

        try {
            $user->update([
                'password' => Hash::make($request->password),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Password berhasil diperbarui',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui password',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
