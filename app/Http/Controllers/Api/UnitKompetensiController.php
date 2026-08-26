<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UnitKompetensi;
use App\Models\SkemaKkni;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // Map frontend field names to backend field names
        $requestData = [
            'kode_unit' => $request->input('kodeunit') ?? $request->input('kode_unit'),
            'judul' => $request->input('namaunit') ?? $request->input('judul'),
            'judul_eng' => $request->input('namaunit_eng') ?? $request->input('judul_eng'),
            'id_skemakkni' => $request->input('skemakknilsp') ?? $request->input('id_skemakkni'),
            'id_skkni' => $request->input('id_skkni'),
            'jenis' => $request->input('jenis'),
        ];

        $validator = Validator::make($requestData, [
            'kode_unit' => 'required|string|max:50',
            'judul' => 'required|string|max:255',
            'judul_eng' => 'nullable|string|max:255',
            'id_skemakkni' => 'required|integer|exists:skema_kkni,id',
            'id_skkni' => 'nullable|integer|exists:skkni,id',
            'jenis' => 'nullable|in:SKKNI,Standar Khusus,Standar Internasional',
        ], [
            'kode_unit.required' => 'Kode Unit wajib diisi',
            'judul.required' => 'Judul wajib diisi',
            'id_skemakkni.required' => 'Skema KKNI wajib dipilih',
            'id_skemakkni.exists' => 'Skema KKNI tidak ditemukan',
            'id_skkni.exists' => 'SKKNI tidak ditemukan',
            'jenis.in' => 'Jenis standar tidak valid',
        ]);

        // Check unique kode_unit per skema
        if (!empty($requestData['kode_unit']) && !empty($requestData['id_skemakkni'])) {
            $exists = UnitKompetensi::where('kode_unit', $requestData['kode_unit'])
                ->where('id_skemakkni', $requestData['id_skemakkni'])
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maaf Unit Kompetensi dengan Kode tersebut Sudah Ada',
                ], 409);
            }
        }

        if ($validator->fails()) {
            \Log::info('Unit Kompetensi Validation Failed', [
                'request' => $request->all(),
                'mapped_data' => $requestData,
                'errors' => $validator->errors()->toArray()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $unit = UnitKompetensi::create([
                'kode_unit' => $requestData['kode_unit'],
                'judul' => $requestData['judul'],
                'judul_eng' => !empty($requestData['judul_eng']) ? $requestData['judul_eng'] : null,
                'id_skemakkni' => $requestData['id_skemakkni'],
                'id_skkni' => !empty($requestData['id_skkni']) ? $requestData['id_skkni'] : null,
                'jenis' => !empty($requestData['jenis']) ? $requestData['jenis'] : 'SKKNI',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Unit kompetensi berhasil ditambahkan',
                'data' => $unit->load(['skemaKkni', 'skkni']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan data: ' . $e->getMessage(),
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

        // Map frontend field names to backend field names
        $requestData = [
            'kode_unit' => $request->input('kodeunit') ?? $request->input('kode_unit'),
            'judul' => $request->input('namaunit') ?? $request->input('judul'),
            'judul_eng' => $request->input('namaunit_eng') ?? $request->input('judul_eng'),
            'id_skemakkni' => $request->input('skemakknilsp') ?? $request->input('id_skemakkni'),
            'id_skkni' => $request->input('id_skkni'),
            'jenis' => $request->input('jenis'),
        ];

        $validator = Validator::make($requestData, [
            'kode_unit' => 'nullable|string|max:100',
            'judul' => 'nullable|string|max:255',
            'judul_eng' => 'nullable|string|max:255',
            'id_skemakkni' => 'nullable|integer|exists:skema_kkni,id',
            'id_skkni' => 'nullable|integer|exists:skkni,id',
            'jenis' => 'nullable|in:SKKNI,Standar Khusus,Standar Internasional',
        ]);

        // Check unique kode_unit per skema (excluding current record)
        if (!empty($requestData['kode_unit']) && !empty($requestData['id_skemakkni'])) {
            $exists = UnitKompetensi::where('kode_unit', $requestData['kode_unit'])
                ->where('id_skemakkni', $requestData['id_skemakkni'])
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
            // Update fields with mapped data
            $updateData = [];
            $fields = ['kode_unit', 'judul', 'judul_eng', 'id_skemakkni', 'id_skkni', 'jenis'];

            foreach ($fields as $field) {
                if ($requestData[$field] !== null) {
                    $value = $requestData[$field];
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

    /**
     * Get statistics for unit kompetensi
     * GET /api/v1/admin/unit-kompetensi/{id}/statistics
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics($id)
    {
        $unit = UnitKompetensi::with(['elemenKompetensi'])->find($id);

        if (!$unit) {
            return response()->json([
                'success' => false,
                'message' => 'Unit Kompetensi tidak ditemukan',
            ], 404);
        }

        $elemenCount = $unit->elemenKompetensi->count();
        $kukCount = 0;

        foreach ($unit->elemenKompetensi as $elemen) {
            $kukCount += $elemen->kriteriaUnjukkerja()->count();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'unit_id' => $unit->id,
                'kode_unit' => $unit->kode_unit,
                'judul' => $unit->judul,
                'jumlah_elemen' => $elemenCount,
                'jumlah_kuk' => $kukCount,
                'bisa_dihapus' => $elemenCount === 0,
            ],
        ]);
    }

    /**
     * Check duplicate unit kompetensi
     * GET /api/v1/admin/unit-kompetensi/check-duplicate
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkDuplicate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kode' => 'required|string',
            'skema' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $exists = UnitKompetensi::where('kode_unit', $request->kode)
            ->where('id_skemakkni', $request->skema)
            ->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'is_duplicate' => $exists,
            ],
        ]);
    }
}
