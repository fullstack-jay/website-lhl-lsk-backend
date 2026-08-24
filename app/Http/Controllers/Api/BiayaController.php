<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BiayaSertifikasi;
use App\Models\BiayaJenis;
use App\Models\Rekeningbayar;
use App\Models\Lsp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BiayaController extends Controller
{
    // ==================== JENIS BIAYA ====================

    /**
     * Get list jenis biaya
     * GET /api/v1/admin/biaya/jenis
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function jenisBiaya()
    {
        $jenis = BiayaJenis::orderBy('id', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $jenis,
        ]);
    }

    /**
     * Create new jenis biaya
     * POST /api/v1/admin/biaya/jenis
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeJenisBiaya(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'jenis_biaya' => 'required|string|max:100|unique:biaya_jenis,jenis_biaya',
        ], [
            'jenis_biaya.required' => 'Jenis biaya harus diisi',
            'jenis_biaya.unique' => 'Jenis biaya sudah ada',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $jenis = BiayaJenis::create([
                'jenis_biaya' => strip_tags(trim($request->jenis_biaya)),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Jenis biaya berhasil ditambahkan',
                'data' => $jenis,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan jenis biaya',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update jenis biaya
     * PUT /api/v1/admin/biaya/jenis/{id}
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateJenisBiaya(Request $request, $id)
    {
        $jenis = BiayaJenis::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'jenis_biaya' => 'nullable|string|max:100|unique:biaya_jenis,jenis_biaya,' . $id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            if ($request->input('jenis_biaya') !== null) {
                $jenis->jenis_biaya = strip_tags(trim($request->input('jenis_biaya')));
                $jenis->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Jenis biaya berhasil diperbarui',
                'data' => $jenis->fresh(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui jenis biaya',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete jenis biaya
     * DELETE /api/v1/admin/biaya/jenis/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyJenisBiaya($id)
    {
        try {
            $jenis = BiayaJenis::findOrFail($id);

            // Check if used in biaya_sertifikasi
            if ($jenis->biayaSertifikasi()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat menghapus jenis biaya yang masih digunakan',
                ], 400);
            }

            $jenis->delete();

            return response()->json([
                'success' => true,
                'message' => 'Jenis biaya berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus jenis biaya',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== BIAYA SERTIFIKASI ====================

    /**
     * Get list biaya sertifikasi (Admin/Public)
     * GET /api/v1/biaya
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = BiayaSertifikasi::with([
            'lsp',
            'skkni',
            'skemaKkni',
            'jenisBiaya'
        ]);

        // Filter by LSP
        if ($request->has('id_lsp') && $request->id_lsp) {
            $query->byLsp($request->id_lsp);
        }

        // Filter by SKKNI
        if ($request->has('id_skkni') && $request->id_skkni) {
            $query->bySkkni($request->id_skkni);
        }

        // Filter by Skema
        if ($request->has('id_skemakkni') && $request->id_skemakkni) {
            $query->bySkema($request->id_skemakkni);
        }

        // Filter by Jenis Biaya
        if ($request->has('jenis_biaya') && $request->jenis_biaya) {
            $query->byJenisBiaya($request->jenis_biaya);
        }

        // Sorting
        $sortBy = $request->sort_by ?? 'id';
        $sortOrder = $request->sort_order ?? 'asc';
        $validSorts = ['id', 'nominal', 'id_skemakkni'];

        if (!in_array($sortBy, $validSorts)) {
            $sortBy = 'id';
        }
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = min((int)($request->per_page ?? 20), 100);
        $page = max(1, (int)($request->page ?? 1));

        $result = $query->paginate($perPage, ['*'], 'page', $page);

        // Add formatted nominal to each item
        $items = collect($result->items())->map(function ($biaya) {
            $biaya->nominal_format = $biaya->nominal_format;
            return $biaya;
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
     * Get detail biaya sertifikasi
     * GET /api/v1/biaya/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $biaya = BiayaSertifikasi::with([
            'lsp',
            'skkni',
            'skemaKkni',
            'jenisBiaya'
        ])->findOrFail($id);

        $biaya->nominal_format = $biaya->nominal_format;

        return response()->json([
            'success' => true,
            'data' => $biaya,
        ]);
    }

    /**
     * Create new biaya sertifikasi (Admin)
     * POST /api/v1/admin/biaya
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_lsp' => 'required|integer|exists:lsp,id',
            'id_skkni' => 'nullable|integer|exists:skkni,id',
            'id_skemakkni' => 'required|integer|exists:skema_kkni,id',
            'jenis_biaya' => 'required|integer|exists:biaya_jenis,id',
            'nominal' => 'required|numeric|min:0',
        ], [
            'id_lsp.required' => 'LSP harus dipilih',
            'id_lsp.exists' => 'LSP tidak ditemukan',
            'id_skemakkni.required' => 'Skema sertifikasi harus dipilih',
            'id_skemakkni.exists' => 'Skema sertifikasi tidak ditemukan',
            'jenis_biaya.required' => 'Jenis biaya harus dipilih',
            'jenis_biaya.exists' => 'Jenis biaya tidak ditemukan',
            'nominal.required' => 'Nominal harus diisi',
            'nominal.numeric' => 'Nominal harus berupa angka',
            'nominal.min' => 'Nominal tidak boleh negatif',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $lspId = $request->id_lsp;
            $skkniId = $request->filled('id_skkni') ? $request->id_skkni : null;
            $skemaId = $request->id_skemakkni;
            $jenisBiayaId = $request->jenis_biaya;

            // Check unique combination
            if (!BiayaSertifikasi::isUnique($lspId, $skkniId, $skemaId, $jenisBiayaId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Biaya untuk kombinasi LSP, SKKNI, Skema, dan Jenis Biaya tersebut sudah ada',
                ], 422);
            }

            $biaya = BiayaSertifikasi::create([
                'id_lsp' => $lspId,
                'id_skkni' => $skkniId,
                'id_skemakkni' => $skemaId,
                'jenis_biaya' => $jenisBiayaId,
                'nominal' => $request->nominal,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Biaya sertifikasi berhasil ditambahkan',
                'data' => $biaya->load(['lsp', 'skkni', 'skemaKkni', 'jenisBiaya']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan biaya sertifikasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update biaya sertifikasi (Admin)
     * POST /api/v1/admin/biaya/{id}?_method=PUT
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $biaya = BiayaSertifikasi::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'jenis_biaya' => 'nullable|integer|exists:biaya_jenis,id',
            'nominal' => 'nullable|numeric|min:0',
        ], [
            'jenis_biaya.exists' => 'Jenis biaya tidak ditemukan',
            'nominal.numeric' => 'Nominal harus berupa angka',
            'nominal.min' => 'Nominal tidak boleh negatif',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Only jenis_biaya and nominal can be updated
            $updateData = [];

            if ($request->input('jenis_biaya') !== null) {
                $updateData['jenis_biaya'] = $request->input('jenis_biaya');
            }

            if ($request->input('nominal') !== null) {
                $updateData['nominal'] = $request->input('nominal');
            }

            if (!empty($updateData)) {
                $biaya->update($updateData);
            }

            return response()->json([
                'success' => true,
                'message' => 'Biaya sertifikasi berhasil diperbarui',
                'data' => $biaya->fresh()->load(['lsp', 'skkni', 'skemaKkni', 'jenisBiaya']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui biaya sertifikasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete biaya sertifikasi (Admin)
     * DELETE /api/v1/admin/biaya/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $biaya = BiayaSertifikasi::findOrFail($id);
            $biaya->delete();

            return response()->json([
                'success' => true,
                'message' => 'Biaya sertifikasi berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus biaya sertifikasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get biaya by skema
     * GET /api/v1/biaya/skema/{id}
     *
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function bySkema($skemaId)
    {
        $biaya = BiayaSertifikasi::bySkema($skemaId)
            ->with(['lsp', 'skkni', 'skemaKkni', 'jenisBiaya'])
            ->orderBy('jenis_biaya', 'asc')
            ->get();

        $biaya->each(function ($item) {
            $item->nominal_format = $item->nominal_format;
        });

        return response()->json([
            'success' => true,
            'data' => $biaya,
        ]);
    }

    /**
     * Get total biaya per skema
     * GET /api/v1/biaya/statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics()
    {
        try {
            $totalBiaya = BiayaSertifikasi::sum('nominal');
            $totalRecords = BiayaSertifikasi::count();
            $avgBiaya = BiayaSertifikasi::avg('nominal');

            return response()->json([
                'success' => true,
                'data' => [
                    'total_nominal' => $totalBiaya,
                    'total_records' => $totalRecords,
                    'average_nominal' => $avgBiaya,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil statistik',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== REKENING BANK ====================

    /**
     * Get list rekening bank (Admin/Public)
     * GET /api/v1/rekening
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function rekeningIndex(Request $request)
    {
        $query = Rekeningbayar::with(['lsp']);

        // Filter by LSP
        if ($request->has('kode_lsp') && $request->kode_lsp) {
            $query->byLsp($request->kode_lsp);
        }

        // Filter by bank
        if ($request->has('bank') && $request->bank) {
            $query->byBank($request->bank);
        }

        // Filter active only
        if ($request->has('aktif') && $request->aktif) {
            if ($request->aktif === 'Y') {
                $query->active();
            }
        }

        // Sorting
        $sortBy = $request->sort_by ?? 'bank';
        $sortOrder = $request->sort_order ?? 'asc';

        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = min((int)($request->per_page ?? 20), 100);
        $page = max(1, (int)($request->page ?? 1));

        $result = $query->paginate($perPage, ['*'], 'page', $page);

        // Add logo URL to each item
        $items = collect($result->items())->map(function ($rekening) {
            $rekening->logo_url = $rekening->logo_url;
            return $rekening;
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
     * Get detail rekening bank
     * GET /api/v1/rekening/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function rekeningShow($id)
    {
        $rekening = Rekeningbayar::with(['lsp'])->findOrFail($id);
        $rekening->logo_url = $rekening->logo_url;

        return response()->json([
            'success' => true,
            'data' => $rekening,
        ]);
    }

    /**
     * Create new rekening bank (Admin)
     * POST /api/v1/admin/rekening
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeRekening(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kode_lsp' => 'required|integer|exists:lsp,id',
            'bank' => 'required|string|max:50',
            'norek' => 'required|string|max:50',
            'atasnama' => 'required|string|max:100',
            'metode' => 'nullable|string|max:50',
            'aktif' => 'nullable|in:Y,N',
        ], [
            'kode_lsp.required' => 'LSP harus dipilih',
            'kode_lsp.exists' => 'LSP tidak ditemukan',
            'bank.required' => 'Nama bank harus diisi',
            'norek.required' => 'Nomor rekening harus diisi',
            'atasnama.required' => 'Atas nama harus diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $lspId = $request->kode_lsp;
            $bank = strip_tags(trim($request->bank));
            $norek = strip_tags(trim($request->norek));
            $atasnama = strip_tags(trim($request->atasnama));

            // Check unique combination
            if (!Rekeningbayar::isUnique($lspId, $bank, $norek, $atasnama)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rekening bank dengan kombinasi LSP, Bank, No. Rek, dan Atas Nama tersebut sudah ada',
                ], 422);
            }

            // Get logo based on bank name
            $logo = Rekeningbayar::getLogoByBank($bank);

            $rekening = Rekeningbayar::create([
                'kode_lsp' => $lspId,
                'bank' => $bank,
                'norek' => $norek,
                'atasnama' => $atasnama,
                'logo' => $logo,
                'metode' => $request->filled('metode') ? strip_tags(trim($request->metode)) : 'Transfer',
                'aktif' => $request->filled('aktif') ? $request->aktif : 'Y',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Rekening bank berhasil ditambahkan',
                'data' => $rekening->load('lsp'),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan rekening bank',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update rekening bank (Admin)
     * POST /api/v1/admin/rekening/{id}?_method=PUT
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateRekening(Request $request, $id)
    {
        $rekening = Rekeningbayar::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'bank' => 'nullable|string|max:50',
            'norek' => 'nullable|string|max:50',
            'atasnama' => 'nullable|string|max:100',
            'metode' => 'nullable|string|max:50',
            'aktif' => 'nullable|in:Y,N',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $updateData = [];

            if ($request->input('bank') !== null) {
                $bank = strip_tags(trim($request->input('bank')));
                $updateData['bank'] = $bank;
                $updateData['logo'] = Rekeningbayar::getLogoByBank($bank);
            }

            if ($request->input('norek') !== null) {
                $updateData['norek'] = strip_tags(trim($request->input('norek')));
            }

            if ($request->input('atasnama') !== null) {
                $updateData['atasnama'] = strip_tags(trim($request->input('atasnama')));
            }

            if ($request->input('metode') !== null) {
                $updateData['metode'] = strip_tags(trim($request->input('metode')));
            }

            if ($request->input('aktif') !== null) {
                $updateData['aktif'] = $request->input('aktif');
            }

            if (!empty($updateData)) {
                $rekening->update($updateData);
            }

            return response()->json([
                'success' => true,
                'message' => 'Rekening bank berhasil diperbarui',
                'data' => $rekening->fresh()->load('lsp'),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui rekening bank',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete rekening bank (Admin)
     * DELETE /api/v1/admin/rekening/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyRekening($id)
    {
        try {
            $rekening = Rekeningbayar::findOrFail($id);
            $rekening->delete();

            return response()->json([
                'success' => true,
                'message' => 'Rekening bank berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus rekening bank',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get rekening by LSP
     * GET /api/v1/rekening/lsp/{id}
     *
     * @param int $lspId
     * @return \Illuminate\Http\JsonResponse
     */
    public function rekeningByLsp($lspId)
    {
        $rekening = Rekeningbayar::byLsp($lspId)
            ->active()
            ->orderBy('bank', 'asc')
            ->get();

        $rekening->each(function ($item) {
            $item->logo_url = $item->logo_url;
        });

        return response()->json([
            'success' => true,
            'data' => $rekening,
        ]);
    }

    /**
     * Get bank options (dropdown)
     * GET /api/v1/rekening/bank-options
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function bankOptions()
    {
        $banks = [
            ['value' => 'Tunai', 'label' => 'Tunai (Loket Pembayaran Tunai)'],
            ['value' => 'BRI', 'label' => 'BRI - Bank Rakyat Indonesia'],
            ['value' => 'BNI', 'label' => 'BNI - Bank Negara Indonesia'],
            ['value' => 'Mandiri', 'label' => 'Mandiri'],
            ['value' => 'BTN', 'label' => 'BTN - Bank Tabungan Negara'],
            ['value' => 'Bank Jateng', 'label' => 'Bank Jateng'],
            ['value' => 'BCA', 'label' => 'BCA - Bank Central Asia'],
            ['value' => 'CIMB Niaga', 'label' => 'CIMB Niaga'],
        ];

        return response()->json([
            'success' => true,
            'data' => $banks,
        ]);
    }

    /**
     * Get LSP options (dropdown)
     * GET /api/v1/lsp/options
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function lspOptions()
    {
        $lsp = Lsp::active()
            ->orderBy('nama', 'asc')
            ->get(['id', 'nama']);

        return response()->json([
            'success' => true,
            'data' => $lsp->map(function ($item) {
                return [
                    'value' => $item->id,
                    'label' => $item->nama,
                ];
            }),
        ]);
    }
}
