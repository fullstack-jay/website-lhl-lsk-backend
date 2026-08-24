<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ElemenKompetensi;
use App\Models\UnitKompetensi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ElemenKompetensiController extends Controller
{
    /**
     * Get list elemen kompetensi (Admin/Public)
     * GET /api/v1/elemen-kompetensi
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = ElemenKompetensi::with(['unitKompetensi.skemaKkni']);

        // Filter by unit kompetensi
        if ($request->has('id_unitkompetensi') && $request->id_unitkompetensi) {
            $query->byUnit($request->id_unitkompetensi);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $query->where('elemen_kompetensi', 'like', '%' . $request->search . '%');
        }

        // Sorting
        $sortBy = $request->sort_by ?? 'id';
        $sortOrder = $request->sort_order ?? 'asc';
        $validSorts = ['id', 'elemen_kompetensi'];

        if (!in_array($sortBy, $validSorts)) {
            $sortBy = 'id';
        }
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = min((int)($request->per_page ?? 20), 100);
        $page = max(1, (int)($request->page ?? 1));

        $result = $query->paginate($perPage, ['*'], 'page', $page);

        // Add jumlah KUK to each item
        $items = collect($result->items())->map(function ($elemen) {
            $elemen->jumlah_kuk = $elemen->jumlah_kuk;
            return $elemen;
        });

        return response()->json([
            'success' => true,
            'data' => $items,
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
     * Get detail elemen kompetensi (Admin/Public)
     * GET /api/v1/elemen-kompetensi/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $elemen = ElemenKompetensi::with([
            'unitKompetensi.skemaKkni',
            'kriteriaUnjukkerja'
        ])->findOrFail($id);

        $elemen->jumlah_kuk = $elemen->jumlah_kuk;

        return response()->json([
            'success' => true,
            'data' => $elemen,
        ]);
    }

    /**
     * Create new elemen kompetensi (Admin)
     * POST /api/v1/admin/elemen-kompetensi
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'elemen_kompetensi' => 'required|string',
            'id_unitkompetensi' => 'required|integer|exists:unit_kompetensi,id',
        ], [
            'elemen_kompetensi.required' => 'Elemen kompetensi harus diisi',
            'id_unitkompetensi.required' => 'Unit kompetensi harus dipilih',
            'id_unitkompetensi.exists' => 'Unit kompetensi tidak ditemukan',
        ]);

        // Check unique elemen per unit
        if ($request->filled('elemen_kompetensi') && $request->filled('id_unitkompetensi')) {
            $exists = ElemenKompetensi::where('elemen_kompetensi', $request->elemen_kompetensi)
                ->where('id_unitkompetensi', $request->id_unitkompetensi)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Elemen kompetensi sudah ada dalam unit ini',
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
            $elemen = ElemenKompetensi::create([
                'elemen_kompetensi' => $request->elemen_kompetensi,
                'id_unitkompetensi' => $request->id_unitkompetensi,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Elemen kompetensi berhasil ditambahkan',
                'data' => $elemen->load(['unitKompetensi.skemaKkni']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan elemen kompetensi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update elemen kompetensi (Admin)
     * POST /api/v1/admin/elemen-kompetensi/{id}?_method=PUT
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $elemen = ElemenKompetensi::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'elemen_kompetensi' => 'nullable|string',
            'id_unitkompetensi' => 'nullable|integer|exists:unit_kompetensi,id',
        ]);

        // Check unique elemen per unit (excluding current record)
        if ($request->input('elemen_kompetensi') && $request->input('id_unitkompetensi')) {
            $exists = ElemenKompetensi::where('elemen_kompetensi', $request->input('elemen_kompetensi'))
                ->where('id_unitkompetensi', $request->input('id_unitkompetensi'))
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Elemen kompetensi sudah ada dalam unit ini',
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
            if ($request->input('elemen_kompetensi') !== null) {
                $elemen->elemen_kompetensi = $request->input('elemen_kompetensi');
            }

            if ($request->input('id_unitkompetensi') !== null) {
                $elemen->id_unitkompetensi = $request->input('id_unitkompetensi');
            }

            $elemen->save();

            return response()->json([
                'success' => true,
                'message' => 'Elemen kompetensi berhasil diperbarui',
                'data' => $elemen->fresh()->load(['unitKompetensi.skemaKkni']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui elemen kompetensi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete elemen kompetensi (Admin)
     * DELETE /api/v1/admin/elemen-kompetensi/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $elemen = ElemenKompetensi::findOrFail($id);

            // Check if there are related data
            if ($elemen->kriteriaUnjukkerja()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat menghapus elemen yang masih memiliki kriteria unjuk kerja',
                ], 400);
            }

            // Delete record
            $elemen->delete();

            return response()->json([
                'success' => true,
                'message' => 'Elemen kompetensi berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus elemen kompetensi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get elemen kompetensi by unit
     * GET /api/v1/unit-kompetensi/{id}/elemen
     *
     * @param int $unitId
     * @return \Illuminate\Http\JsonResponse
     */
    public function byUnit($unitId)
    {
        $unit = UnitKompetensi::findOrFail($unitId);
        $elemen = ElemenKompetensi::byUnit($unitId)
            ->orderBy('id', 'asc')
            ->get();

        $elemen->each(function ($el) {
            $el->jumlah_kuk = $el->jumlah_kuk;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'unit_kompetensi' => $unit,
                'elemen_kompetensi' => $elemen,
            ],
        ]);
    }
}
