<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SkemaPersyaratantuk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PersyaratanTukController extends Controller
{
    /**
     * Get all persyaratan TUK by skema ID
     *
     * @param Request $request
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, $skemaId)
    {
        $query = SkemaPersyaratantuk::bySkema($skemaId);

        // Search
        if ($request->has('search')) {
            $query->where('perlengkapan', 'like', '%' . $request->search . '%');
        }

        $persyaratan = $query->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'data' => $persyaratan->map(function ($item) {
                return [
                    'id' => $item->id,
                    'perlengkapan' => $item->perlengkapan,
                    'spesifikasi' => $item->spesifikasi,
                ];
            }),
        ]);
    }

    /**
     * Get persyaratan detail by ID
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $persyaratan = SkemaPersyaratantuk::find($id);

        if (!$persyaratan) {
            return response()->json([
                'success' => false,
                'message' => 'Persyaratan TUK tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $persyaratan->id,
                'perlengkapan' => $persyaratan->perlengkapan,
                'spesifikasi' => $persyaratan->spesifikasi,
                'id_skemakkni' => $persyaratan->id_skemakkni,
            ],
        ]);
    }

    /**
     * Store new persyaratan TUK
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'id_skemakkni' => 'required|exists:skema_kkni,id',
            'perlengkapan' => 'required',
            'spesifikasi' => 'required',
        ], [
            'id_skemakkni.required' => 'Skema sertifikasi wajib dipilih',
            'id_skemakkni.exists' => 'Skema sertifikasi tidak valid',
            'perlengkapan.required' => 'Perlengkapan wajib diisi',
            'spesifikasi.required' => 'Spesifikasi wajib diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Check for duplicate
        $exists = SkemaPersyaratantuk::where('id_skemakkni', $request->id_skemakkni)
            ->where('perlengkapan', $request->perlengkapan)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Persyaratan dengan nama perlengkapan tersebut sudah ada untuk skema ini',
            ], 400);
        }

        try {
            $persyaratan = SkemaPersyaratantuk::create([
                'id_skemakkni' => $request->id_skemakkni,
                'perlengkapan' => $request->perlengkapan,
                'spesifikasi' => $request->spesifikasi,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Persyaratan TUK berhasil ditambahkan',
                'data' => [
                    'id' => $persyaratan->id,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan persyaratan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update persyaratan TUK
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $persyaratan = SkemaPersyaratantuk::find($id);

        if (!$persyaratan) {
            return response()->json([
                'success' => false,
                'message' => 'Persyaratan TUK tidak ditemukan',
            ], 404);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'perlengkapan' => 'required',
            'spesifikasi' => 'required',
        ], [
            'perlengkapan.required' => 'Perlengkapan wajib diisi',
            'spesifikasi.required' => 'Spesifikasi wajib diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $persyaratan->update([
                'perlengkapan' => $request->perlengkapan,
                'spesifikasi' => $request->spesifikasi,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Persyaratan TUK berhasil diperbarui',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui persyaratan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete persyaratan TUK
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $persyaratan = SkemaPersyaratantuk::find($id);

        if (!$persyaratan) {
            return response()->json([
                'success' => false,
                'message' => 'Persyaratan TUK tidak ditemukan',
            ], 404);
        }

        try {
            $persyaratan->delete();

            return response()->json([
                'success' => true,
                'message' => 'Persyaratan TUK berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus persyaratan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Batch store persyaratan TUK
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchStore(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'id_skemakkni' => 'required|exists:skema_kkni,id',
            'persyaratan' => 'required|array|min:1',
            'persyaratan.*.perlengkapan' => 'required',
            'persyaratan.*.spesifikasi' => 'required',
        ], [
            'id_skemakkni.required' => 'Skema sertifikasi wajib dipilih',
            'id_skemakkni.exists' => 'Skema sertifikasi tidak valid',
            'persyaratan.required' => 'Data persyaratan wajib diisi',
            'persyaratan.array' => 'Format persyaratan tidak valid',
            'persyaratan.min' => 'Minimal satu data persyaratan',
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
                // Check for duplicate
                $exists = SkemaPersyaratantuk::where('id_skemakkni', $request->id_skemakkni)
                    ->where('perlengkapan', $item['perlengkapan'])
                    ->exists();

                if (!$exists) {
                    $persyaratan = SkemaPersyaratantuk::create([
                        'id_skemakkni' => $request->id_skemakkni,
                        'perlengkapan' => $item['perlengkapan'],
                        'spesifikasi' => $item['spesifikasi'],
                    ]);
                    $created[] = $persyaratan->id;
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($created) . ' persyaratan TUK berhasil ditambahkan',
                'data' => [
                    'created_ids' => $created,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan persyaratan: ' . $e->getMessage(),
            ], 500);
        }
    }
}
