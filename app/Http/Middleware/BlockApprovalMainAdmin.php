<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Blokir akun dengan username `admin` dari seluruh aksi APPROVAL:
 * verifikasi peserta, verifikasi dokumen, validasi pembayaran,
 * kwitansi, verifikasi pemeliharaan/PKB, verifikasi sertifikat,
 * dan penetapan status pendaftaran.
 *
 * Kebijakan: approval hanya boleh dilakukan oleh akun admin LAIN
 * (mis. adminlsk, lskeditor2026) — bukan akun `admin`.
 */
class BlockApprovalMainAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->username === 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Akun "admin" tidak memiliki wewenang untuk approval. Silakan gunakan akun admin lain (mis. adminlsk).',
            ], 403);
        }

        return $next($request);
    }
}
