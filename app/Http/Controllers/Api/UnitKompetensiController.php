<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UnitKompetensi;
use App\Models\SkemaKkni;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UnitKompetensiController extends Controller
{
    /**
     * Get list unit kompetensi (Admin/Public)
     * GET /api/v1/unit-kompetensi
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = UnitKompetensi::with(['skemaKkni', 'skkni']);

        // Filter by skema
        if ($request->has('id_skemakkni') && $request->id_skemakkni) {
            $query->bySkema($request->id_skemakkni);
        }

        // Filter by jenis
        if ($request->has('jenis') && $request->jenis) {
            $query->where('jenis', $request->jenis);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('kode_unit', 'like', '%' . $search . '%')
                    ->orWhere('judul', 'like', '%' . $search . '%')
                    ->orWhere('judul_eng', 'like', '%' . $search . '%');
            });
        }

        // Sorting
        $sortBy = $request->sort_by ?? 'id';
        $sortOrder = $request->sort_order ?? 'asc';
        $validSorts = ['id', 'kode_unit', 'judul'];

        if (!in_array($sortBy, $validSorts)) {
            $sortBy = 'id';
        }
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = min((int)($request->per_page ?? 20), 100);
        $page = max(1, (int)($request->page ?? 1));

        $result = $query->paginate($perPage, ['*'], 'page', $page);

        // Add statistics to each item
        $items = collect($result->items())->map(function ($unit) {
            $unit->jumlah_elemen = $unit->jumlah_elemen;
            $unit->jumlah_kuk = $unit->jumlah_kuk;
            return $unit;
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
     * Get detail unit kompetensi (Admin/Public)
     * GET /api/v1/unit-kompetensi/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $unit = UnitKompetensi::with([
            'skemaKkni',
            'skkni',
            'elemenKompetensi.kriteriaUnjukkerja'
        ])->findOrFail($id);

        $unit->jumlah_elemen = $unit->jumlah_elemen;
        $unit->jumlah_kuk = $unit->jumlah_kuk;

        return response()->json([
            'success' => true,
            'data' => $unit,
        ]);
    }

    /**
     * Create new unit kompetensi (Admin)
     * POST /api/v1/admin/unit-kompetensi
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kode_unit' => 'required|string|max:100',
            'judul' => 'required|string|max:255',
            'judul_eng' => 'nullable|string|max:255',
            'id_skemakkni' => 'required|integer|exists:skema_kkni,id',
            'id_skkni' => 'nullable|integer|exists:skkni,id',
            'jenis' => 'nullable|in:SKKNI,Standar Khusus,Standar Internasional',
        ], [
            'id_skemakkni.required' => 'Skema sertifikasi harus dipilih',
            'id_skemakkni.exists' => 'Skema sertifikasi tidak ditemukan',
            'id_skkni.exists' => 'Standar kompetensi tidak ditemukan',
        ]);

        // Check unique kode_unit per skema
        if ($request->filled('kode_unit') && $request->filled('id_skemakkni')) {
            $exists = UnitKompetensi::where('kode_unit', $request->kode_unit)
                ->where('id_skemakkni', $request->id_skemakkni)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode unit sudah ada dalam skema ini',
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
            $unit = UnitKompetensi::create([
                'kode_unit' => strip_tags(trim($request->kode_unit)),
                'judul' => strip_tags(trim($request->judul)),
                'judul_eng' => $request->filled('judul_eng') ? strip_tags(trim($request->judul_eng)) : null,
                'id_skemakkni' => $request->id_skemakkni,
                'id_skkni' => $request->filled('id_skkni') ? $request->id_skkni : null,
                'jenis' => $request->filled('jenis') ? $request->jenis : 'SKKNI',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Unit kompetensi berhasil ditambahkan',
                'data' => $unit->load(['skemaKkni', 'skkni']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan unit kompetensi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update unit kompetensi (Admin)
     * POST /api/v1/admin/unit-kompetensi/{id}?_method=PUT
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $unit = UnitKompetensi::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'kode_unit' => 'nullable|string|max:100',
            'judul' => 'nullable|string|max:255',
            'judul_eng' => 'nullable|string|max:255',
            'id_skemakkni' => 'nullable|integer|exists:skema_kkni,id',
            'id_skkni' => 'nullable|integer|exists:skkni,id',
            'jenis' => 'nullable|in:SKKNI,Standar Khusus,Standar Internasional',
        ]);

        // Check unique kode_unit per skema (excluding current record)
        if ($request->input('kode_unit') && $request->input('id_skemakkni')) {
            $exists = UnitKompetensi::where('kode_unit', $request->input('kode_unit'))
                ->where('id_skemakkni', $request->input('id_skemakkni'))
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode unit sudah ada dalam skema ini',
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
            $updateData = [];
            $fields = ['kode_unit', 'judul', 'judul_eng', 'id_skemakkni', 'id_skkni', 'jenis'];

            foreach ($fields as $field) {
                if ($request->input($field) !== null) {
                    $value = $request->input($field);
                    if (in_array($field, ['kode_unit', 'judul', 'judul_eng'])) {
                        $value = strip_tags(trim($value));
                    }
                    $updateData[$field] = $value;
                }
            }

            if (!empty($updateData)) {
                $unit->update($updateData);
            }

            return response()->json([
                'success' => true,
                'message' => 'Unit kompetensi berhasil diperbarui',
                'data' => $unit->fresh()->load(['skemaKkni', 'skkni']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui unit kompetensi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete unit kompetensi (Admin)
     * DELETE /api/v1/admin/unit-kompetensi/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $unit = UnitKompetensi::findOrFail($id);

            // Check if there are related data
            if ($unit->elemenKompetensi()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat menghapus unit yang masih memiliki elemen kompetensi',
                ], 400);
            }

            // Delete record
            $unit->delete();

            return response()->json([
                'success' => true,
                'message' => 'Unit kompetensi berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus unit kompetensi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get unit kompetensi by skema
     * GET /api/v1/skema/{id}/unit-kompetensi
     *
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function bySkema($skemaId)
    {
        $skema = SkemaKkni::findOrFail($skemaId);
        $units = UnitKompetensi::bySkema($skemaId)
            ->with(['skkni'])
            ->orderBy('kode_unit', 'asc')
            ->get();

        $units->each(function ($unit) {
            $unit->jumlah_elemen = $unit->jumlah_elemen;
            $unit->jumlah_kuk = $unit->jumlah_kuk;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'skema' => $skema,
                'unit_kompetensi' => $units,
            ],
        ]);
    }
}
