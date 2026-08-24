<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KriteriaUnjukkerja;
use App\Models\ElemenKompetensi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class KriteriaUnjukkerjaController extends Controller
{
    /**
     * Get list kriteria unjuk kerja (Admin/Public)
     * GET /api/v1/kriteria-unjukkerja
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = KriteriaUnjukkerja::with([
            'elemenKompetensi.unitKompetensi.skemaKkni'
        ]);

        // Filter by elemen kompetensi
        if ($request->has('id_elemenkompetensi') && $request->id_elemenkompetensi) {
            $query->byElemen($request->id_elemenkompetensi);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $query->search($request->search);
        }

        // Sorting
        $sortBy = $request->sort_by ?? 'id';
        $sortOrder = $request->sort_order ?? 'asc';
        $validSorts = ['id', 'kriteria'];

        if (!in_array($sortBy, $validSorts)) {
            $sortBy = 'id';
        }
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = min((int)($request->per_page ?? 20), 100);
        $page = max(1, (int)($request->page ?? 1));

        $result = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $result->items(),
            'pagination' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
                'from' => $result->firstItem(),
                'to' => $result->lastItem(),
            ],
        ]);
    }

    /**
     * Get detail kriteria unjuk kerja (Admin/Public)
     * GET /api/v1/kriteria-unjukkerja/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $kuk = KriteriaUnjukkerja::with([
            'elemenKompetensi.unitKompetensi.skemaKkni'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $kuk,
        ]);
    }

    /**
     * Create new kriteria unjuk kerja (Admin)
     * POST /api/v1/admin/kriteria-unjukkerja
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kriteria' => 'required|string',
            'kriteria_pasif' => 'nullable|string',
            'id_elemenkompetensi' => 'required|integer|exists:elemen_kompetensi,id',
        ], [
            'kriteria.required' => 'Kriteria unjuk kerja harus diisi',
            'id_elemenkompetensi.required' => 'Elemen kompetensi harus dipilih',
            'id_elemenkompetensi.exists' => 'Elemen kompetensi tidak ditemukan',
        ]);

        // Check unique kriteria per elemen
        if ($request->filled('kriteria') && $request->filled('id_elemenkompetensi')) {
            $exists = KriteriaUnjukkerja::where('kriteria', $request->kriteria)
                ->where('id_elemenkompetensi', $request->id_elemenkompetensi)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kriteria unjuk kerja sudah ada dalam elemen ini',
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
            $kuk = KriteriaUnjukkerja::create([
                'kriteria' => $request->kriteria,
                'kriteria_pasif' => $request->filled('kriteria_pasif') ? $request->kriteria_pasif : null,
                'id_elemenkompetensi' => $request->id_elemenkompetensi,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Kriteria unjuk kerja berhasil ditambahkan',
                'data' => $kuk->load(['elemenKompetensi.unitKompetensi.skemaKkni']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan kriteria unjuk kerja',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update kriteria unjuk kerja (Admin)
     * POST /api/v1/admin/kriteria-unjukkerja/{id}?_method=PUT
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $kuk = KriteriaUnjukkerja::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'kriteria' => 'nullable|string',
            'kriteria_pasif' => 'nullable|string',
            'id_elemenkompetensi' => 'nullable|integer|exists:elemen_kompetensi,id',
        ]);

        // Check unique kriteria per elemen (excluding current record)
        if ($request->input('kriteria') && $request->input('id_elemenkompetensi')) {
            $exists = KriteriaUnjukkerja::where('kriteria', $request->input('kriteria'))
                ->where('id_elemenkompetensi', $request->input('id_elemenkompetensi'))
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kriteria unjuk kerja sudah ada dalam elemen ini',
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
            // Update fields (use input() for FormData compatibility)
            if ($request->input('kriteria') !== null) {
                $kuk->kriteria = $request->input('kriteria');
            }

            if ($request->input('kriteria_pasif') !== null) {
                $kuk->kriteria_pasif = $request->input('kriteria_pasif');
            }

            if ($request->input('id_elemenkompetensi') !== null) {
                $kuk->id_elemenkompetensi = $request->input('id_elemenkompetensi');
            }

            $kuk->save();

            return response()->json([
                'success' => true,
                'message' => 'Kriteria unjuk kerja berhasil diperbarui',
                'data' => $kuk->fresh()->load(['elemenKompetensi.unitKompetensi.skemaKkni']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui kriteria unjuk kerja',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete kriteria unjuk kerja (Admin)
     * DELETE /api/v1/admin/kriteria-unjukkerja/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $kuk = KriteriaUnjukkerja::findOrFail($id);

            // Delete record
            $kuk->delete();

            return response()->json([
                'success' => true,
                'message' => 'Kriteria unjuk kerja berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus kriteria unjuk kerja',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get kriteria unjuk kerja by elemen
     * GET /api/v1/elemen-kompetensi/{id}/kuk
     *
     * @param int $elemenId
     * @return \Illuminate\Http\JsonResponse
     */
    public function byElemen($elemenId)
    {
        $elemen = ElemenKompetensi::findOrFail($elemenId);
        $kuk = KriteriaUnjukkerja::byElemen($elemenId)
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'elemen_kompetensi' => $elemen,
                'kriteria_unjukkerja' => $kuk,
            ],
        ]);
    }

    /**
     * Batch create kriteria unjuk kerja (Admin)
     * POST /api/v1/admin/kriteria-unjukkerja/batch
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_elemenkompetensi' => 'required|integer|exists:elemen_kompetensi,id',
            'kriteria' => 'required|array|min:1',
            'kriteria.*' => 'required|string',
            'kriteria_pasif' => 'nullable|array',
            'kriteria_pasif.*' => 'nullable|string',
        ], [
            'id_elemenkompetensi.required' => 'Elemen kompetensi harus dipilih',
            'kriteria.required' => 'Kriteria unjuk kerja harus diisi',
            'kriteria.array' => 'Kriteria harus berupa array',
            'kriteria.*.required' => 'Semua kriteria harus diisi',
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
            foreach ($request->kriteria as $index => $kriteriaText) {
                $kuk = KriteriaUnjukkerja::create([
                    'kriteria' => $kriteriaText,
                    'kriteria_pasif' => $request->input('kriteria_pasif.' . $index),
                    'id_elemenkompetensi' => $request->id_elemenkompetensi,
                ]);
                $created[] = $kuk;
            }

            return response()->json([
                'success' => true,
                'message' => count($created) . ' kriteria unjuk kerja berhasil ditambahkan',
                'data' => $created,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan kriteria unjuk kerja',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
