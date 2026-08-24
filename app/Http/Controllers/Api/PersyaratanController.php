<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SkemaPersyaratan;
use App\Models\SkemaPersyaratantuk;
use App\Models\SkemaKkni;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PersyaratanController extends Controller
{
    // ==================== PERSYARATAN PESERTA ====================

    /**
     * Get list persyaratan peserta by skema (Admin/Public)
     * GET /api/v1/skema/{id}/persyaratan
     *
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function indexPeserta($skemaId)
    {
        $skema = SkemaKkni::findOrFail($skemaId);
        $persyaratan = SkemaPersyaratan::bySkema($skemaId)
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'skema' => $skema,
                'persyaratan' => $persyaratan,
            ],
        ]);
    }

    /**
     * Create new persyaratan peserta (Admin)
     * POST /api/v1/admin/skema/{id}/persyaratan
     *
     * @param Request $request
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function storePeserta(Request $request, $skemaId)
    {
        $skema = SkemaKkni::findOrFail($skemaId);

        $validator = Validator::make($request->all(), [
            'persyaratan' => 'required|string',
        ], [
            'persyaratan.required' => 'Persyaratan harus diisi',
        ]);

        // Return validation errors first
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Check unique persyaratan per skema AFTER validation passes
        $exists = SkemaPersyaratan::where('persyaratan', $request->persyaratan)
            ->where('id_skemakkni', $skemaId)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Persyaratan sudah ada dalam skema ini',
            ], 422);
        }

        try {
            $persyaratan = SkemaPersyaratan::create([
                'persyaratan' => $request->persyaratan,
                'id_skemakkni' => $skemaId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Persyaratan berhasil ditambahkan',
                'data' => $persyaratan,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan persyaratan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update persyaratan peserta (Admin)
     * POST /api/v1/admin/persyaratan/{id}?_method=PUT
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePeserta(Request $request, $id)
    {
        $persyaratan = SkemaPersyaratan::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'persyaratan' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Update field (use input() for FormData compatibility)
            if ($request->input('persyaratan') !== null) {
                $persyaratan->persyaratan = $request->input('persyaratan');
                $persyaratan->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Persyaratan berhasil diperbarui',
                'data' => $persyaratan->fresh(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui persyaratan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete persyaratan peserta (Admin)
     * DELETE /api/v1/admin/persyaratan/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyPeserta($id)
    {
        try {
            $persyaratan = SkemaPersyaratan::findOrFail($id);
            $persyaratan->delete();

            return response()->json([
                'success' => true,
                'message' => 'Persyaratan berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus persyaratan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== PERSYARATAN TUK ====================

    /**
     * Get list persyaratan TUK by skema (Admin/Public)
     * GET /api/v1/skema/{id}/persyaratan-tuk
     *
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function indexTuk($skemaId)
    {
        $skema = SkemaKkni::findOrFail($skemaId);
        $persyaratan = SkemaPersyaratantuk::bySkema($skemaId)
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'skema' => $skema,
                'persyaratan_tuk' => $persyaratan,
            ],
        ]);
    }

    /**
     * Create new persyaratan TUK (Admin)
     * POST /api/v1/admin/skema/{id}/persyaratan-tuk
     *
     * @param Request $request
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeTuk(Request $request, $skemaId)
    {
        $skema = SkemaKkni::findOrFail($skemaId);

        $validator = Validator::make($request->all(), [
            'perlengkapan' => 'required|string',
            'spesifikasi' => 'nullable|string',
        ], [
            'perlengkapan.required' => 'Perlengkapan harus diisi',
        ]);

        // Check unique perlengkapan per skema
        if ($request->filled('perlengkapan')) {
            $exists = SkemaPersyaratantuk::where('perlengkapan', $request->perlengkapan)
                ->where('id_skemakkni', $skemaId)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Perlengkapan sudah ada dalam skema ini',
                ], 422);
            }
        }

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $persyaratan = SkemaPersyaratantuk::create([
                'perlengkapan' => $request->perlengkapan,
                'spesifikasi' => $request->filled('spesifikasi') ? $request->spesifikasi : null,
                'id_skemakkni' => $skemaId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Persyaratan TUK berhasil ditambahkan',
                'data' => $persyaratan,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan persyaratan TUK',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update persyaratan TUK (Admin)
     * POST /api/v1/admin/persyaratan-tuk/{id}?_method=PUT
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateTuk(Request $request, $id)
    {
        $persyaratan = SkemaPersyaratantuk::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'perlengkapan' => 'nullable|string',
            'spesifikasi' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Update fields (use input() for FormData compatibility)
            if ($request->input('perlengkapan') !== null) {
                $persyaratan->perlengkapan = $request->input('perlengkapan');
            }

            if ($request->input('spesifikasi') !== null) {
                $persyaratan->spesifikasi = $request->input('spesifikasi');
            }

            $persyaratan->save();

            return response()->json([
                'success' => true,
                'message' => 'Persyaratan TUK berhasil diperbarui',
                'data' => $persyaratan->fresh(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui persyaratan TUK',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete persyaratan TUK (Admin)
     * DELETE /api/v1/admin/persyaratan-tuk/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyTuk($id)
    {
        try {
            $persyaratan = SkemaPersyaratantuk::findOrFail($id);
            $persyaratan->delete();

            return response()->json([
                'success' => true,
                'message' => 'Persyaratan TUK berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus persyaratan TUK',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Batch create persyaratan TUK (Admin)
     * POST /api/v1/admin/skema/{id}/persyaratan-tuk/batch
     *
     * @param Request $request
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchStoreTuk(Request $request, $skemaId)
    {
        $skema = SkemaKkni::findOrFail($skemaId);

        $validator = Validator::make($request->all(), [
            'persyaratan' => 'required|array|min:1',
            'persyaratan.*.perlengkapan' => 'required|string',
            'persyaratan.*.spesifikasi' => 'nullable|string',
        ], [
            'persyaratan.required' => 'Persyaratan harus diisi',
            'persyaratan.array' => 'Persyaratan harus berupa array',
            'persyaratan.*.perlengkapan.required' => 'Perlengkapan wajib diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $created = [];
            foreach ($request->persyaratan as $item) {
                $persyaratan = SkemaPersyaratantuk::create([
                    'perlengkapan' => $item['perlengkapan'],
                    'spesifikasi' => $item['spesifikasi'] ?? null,
                    'id_skemakkni' => $skemaId,
                ]);
                $created[] = $persyaratan;
            }

            return response()->json([
                'success' => true,
                'message' => count($created) . ' persyaratan TUK berhasil ditambahkan',
                'data' => $created,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan persyaratan TUK',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
