<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminAuthController extends ApiController
{
    /**
     * Login Admin
     * POST /api/v1/auth/admin/login
     */
    public function login(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string|min:4',
        ], [
            'username.required' => 'Username wajib diisi',
            'password.required' => 'Password wajib diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $username = $request->input('username');
        $password = $request->input('password');

        // Find user by username
        $user = User::where('username', $username)->active()->first();

        // Check if user exists
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Username tidak ditemukan',
            ], 401);
        }

        // Check if user is admin
        if (!$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus untuk Admin.',
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
        $token = $user->createToken('admin-auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil',
            'data' => [
                'user' => [
                    'username' => $user->username,
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
     * Get authenticated admin info
     */
    public function me(Request $request)
    {
        $user = $request->user();

        // Verify user is admin
        if (!$user->isAdmin()) {
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
     * Ubah Kata Sandi Sendiri (self-service) — khusus ADMIN
     * POST /api/v1/auth/admin/ubah-password
     *
     * Implementasi modul `password` native (aksi_password.php) versi API,
     * dengan validasi fail-fast 4 langkah yang sama:
     *   1. Semua field terisi (password_lama, password_baru, password_ulangi)
     *   2. Password baru minimal 8 karakter
     *   3. Password lama COCOK dengan hash di DB
     *      (perbaikan keamanan: verifikasi server-side dari DB — native
     *      mengirim hash lama via hidden input yang terlihat di view-source)
     *   4. Konfirmasi cocok (password_baru == password_ulangi)
     *
     * Lolos semua → UPDATE password (bcrypt, fix MD5 native) +
     * REVOKE semua token = logout paksa (harus login ulang dgn password baru),
     * padanan redirect ke index.php native.
     */
    public function ubahPassword(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }

        // Hanya role admin (modul password adalah menu internal admin/staff)
        if (!$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus untuk admin.',
            ], 403);
        }

        // ── Langkah 1: Semua field terisi ──
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
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError,
                'errors' => $validator->errors(),
            ], 422);
        }

        // ── Langkah 3: Password lama cocok? (verifikasi server-side dari DB) ──
        if (!Hash::check($request->password_lama, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda salah memasukkan Password Lama',
            ], 422);
        }

        // ── Langkah 4: Konfirmasi cocok? ──
        if ($request->password_baru !== $request->password_ulangi) {
            return response()->json([
                'success' => false,
                'message' => 'Password baru belum cocok',
            ], 422);
        }

        try {
            // UPDATE password (bcrypt — fix MD5 tanpa salt native)
            $user->password = Hash::make($request->password_baru);
            $user->save();

            // Logout paksa: revoke semua token (harus login ulang dgn password baru)
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
}
