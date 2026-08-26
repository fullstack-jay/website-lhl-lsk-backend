<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tuk;
use App\Models\SkemaPersyaratantuk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class TukController extends Controller
{
    /**
     * Get all TUK with pagination
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Tuk::with(['wilayah']);

        // Search
        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Filter by active lisensi
        if ($request->has('lisensi_active')) {
            if ($request->lisensi_active === 'true') {
                $query->active();
            }
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'id');
        $sortOrder = $request->get('sort_order', 'asc');

        if (!in_array($sortBy, ['id', 'nama', 'kode_tuk', 'masa_berlaku'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = min((int) $request->get('per_page', 20), 100);
        $page = (int) $request->get('page', 1);

        $tuk = $query->paginate($perPage, ['*'], 'page', $page);

        // Transform data
        $data = $tuk->map(function ($item) {
            return [
                'id' => $item->id,
                'kode_tuk' => $item->kode_tuk,
                'id_tuk_bnsp' => $item->id_tuk_bnsp,
                'nama' => $item->nama,
                'penanggungjawab' => $item->penanggungjawab,
                'jenis_tuk' => $item->jenis_tuk,
                'lsp_induk' => $item->lsp_induk,
                'institusi_induk' => $item->institusi_induk,
                'alamat' => $item->alamat,
                'kelurahan' => $item->kelurahan,
                'kodepos' => $item->kodepos,
                'telepon' => $item->telepon,
                'email' => $item->email,
                'fax' => $item->fax,
                'no_lisensi' => $item->no_lisensi,
                'masa_berlaku' => $item->masa_berlaku ? $item->masa_berlaku->format('Y-m-d') : null,
                'lisensi_active' => $item->lisensi_active,
                'status_lisensi' => $item->status_lisensi,
                'sisa_hari' => $item->sisa_hari,
                'jumlah_jadwal' => $item->jumlah_jadwal,
                'full_address' => $item->full_address,
                'wilayah' => $item->wilayah ? [
                    'id_wil' => $item->wilayah->id_wil,
                    'nm_wil' => $item->wilayah->nm_wil,
                    'id_level_wil' => $item->wilayah->id_level_wil,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $tuk->currentPage(),
                'per_page' => $tuk->perPage(),
                'total' => $tuk->total(),
                'last_page' => $tuk->lastPage(),
                'from' => $tuk->firstItem(),
                'to' => $tuk->lastItem(),
            ],
        ]);
    }

    /**
     * Get TUK detail by ID
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $tuk = Tuk::with(['wilayah', 'skema:id,judul'])->find($id);

        if (!$tuk) {
            return response()->json([
                'success' => false,
                'message' => 'TUK tidak ditemukan',
            ], 404);
        }

        // Build wilayah detail
        $wilayahDetail = null;
        if ($tuk->wilayah) {
            $kecamatan = $tuk->wilayah;
            $kota = $kecamatan->parent ?? null;
            $provinsi = $kota ? $kota->parent ?? null : null;

            $wilayahDetail = [
                'kecamatan' => [
                    'id_wil' => $kecamatan->id_wil,
                    'nm_wil' => $kecamatan->nm_wil,
                ],
                'kota' => $kota ? [
                    'id_wil' => $kota->id_wil,
                    'nm_wil' => $kota->nm_wil,
                ] : null,
                'provinsi' => $provinsi ? [
                    'id_wil' => $provinsi->id_wil,
                    'nm_wil' => $provinsi->nm_wil,
                ] : null,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $tuk->id,
                'kode_tuk' => $tuk->kode_tuk,
                'id_tuk_bnsp' => $tuk->id_tuk_bnsp,
                'nama' => $tuk->nama,
                'penanggungjawab' => $tuk->penanggungjawab,
                'jenis_tuk' => $tuk->jenis_tuk,
                'lsp_induk' => $tuk->lsp_induk,
                'institusi_induk' => $tuk->institusi_induk,
                'alamat' => $tuk->alamat,
                'kelurahan' => $tuk->kelurahan,
                'kodepos' => $tuk->kodepos,
                'telepon' => $tuk->telepon,
                'email' => $tuk->email,
                'fax' => $tuk->fax,
                'tgl_pendirian' => $tuk->tgl_pendirian ? $tuk->tgl_pendirian->format('Y-m-d') : null,
                'no_lisensi' => $tuk->no_lisensi,
                'masa_berlaku' => $tuk->masa_berlaku ? $tuk->masa_berlaku->format('Y-m-d') : null,
                'id_skkni' => $tuk->id_skkni,
                'lisensi_active' => $tuk->lisensi_active,
                'status_lisensi' => $tuk->status_lisensi,
                'sisa_hari' => $tuk->sisa_hari,
                'jumlah_jadwal' => $tuk->jumlah_jadwal,
                'can_be_deleted' => $tuk->canBeDeleted(),
                'full_address' => $tuk->full_address,
                'wilayah' => $wilayahDetail,
                'skema' => $tuk->skema ? [
                    'id' => $tuk->skema->id,
                    'judul' => $tuk->skema->judul,
                ] : null,
            ],
        ]);
    }

    /**
     * Get TUK options for dropdown
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function options()
    {
        $tuk = Tuk::active()
            ->orderBy('nama')
            ->get(['id', 'nama', 'kode_tuk', 'alamat']);

        return response()->json([
            'success' => true,
            'data' => $tuk->map(function ($item) {
                return [
                    'value' => $item->id,
                    'label' => $item->kode_tuk ? "{$item->kode_tuk} - {$item->nama}" : $item->nama,
                    'kode_tuk' => $item->kode_tuk,
                    'nama' => $item->nama,
                    'alamat' => $item->alamat,
                ];
            }),
        ]);
    }

    /**
     * Get TUK statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics()
    {
        $total = Tuk::count();
        $active = Tuk::active()->count();
        $expired = Tuk::expired()->count();

        // Get TUK with most asesmen
        $mostActive = DB::table('jadwal_asesmen')
            ->select('tempat_asesmen', DB::raw('COUNT(*) as total'))
            ->groupBy('tempat_asesmen')
            ->orderBy('total', 'desc')
            ->limit(5)
            ->get();

        $mostActiveTuk = [];
        foreach ($mostActive as $item) {
            $tuk = Tuk::find($item->tempat_asesmen);
            if ($tuk) {
                $mostActiveTuk[] = [
                    'id' => $tuk->id,
                    'nama' => $tuk->nama,
                    'kode_tuk' => $tuk->kode_tuk,
                    'jumlah_asesmen' => $item->total,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'active' => $active,
                'expired' => $expired,
                'most_active' => $mostActiveTuk,
            ],
        ]);
    }

    /**
     * Store new TUK
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'kode_tuk' => 'required|unique:tuk,kode_tuk',
            'nama' => 'required',
            'penanggungjawab' => 'required',
            'jenis_tuk' => 'required',
            'lsp_induk' => 'required',
            'id_wilayah' => 'required',
            'id_skkni' => 'required',
            'masa_berlaku' => 'nullable|date',
            'tgl_pendirian' => 'nullable|date',
        ], [
            'kode_tuk.required' => 'Kode TUK wajib diisi',
            'kode_tuk.unique' => 'Kode TUK sudah digunakan',
            'nama.required' => 'Nama TUK wajib diisi',
            'penanggungjawab.required' => 'Penanggung jawab wajib diisi',
            'jenis_tuk.required' => 'Jenis TUK wajib diisi',
            'lsp_induk.required' => 'LSP Induk wajib diisi',
            'id_wilayah.required' => 'Wilayah wajib diisi',
            'id_skkni.required' => 'SKKNI wajib diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $tuk = Tuk::create([
                'kode_tuk' => $request->kode_tuk,
                'nama' => $request->nama,
                'penanggungjawab' => $request->penanggungjawab,
                'jenis_tuk' => $request->jenis_tuk,
                'lsp_induk' => $request->lsp_induk,
                'institusi_induk' => $request->institusi_induk,
                'alamat' => $request->alamat,
                'kelurahan' => $request->kelurahan,
                'id_wilayah' => $request->id_wilayah,
                'kodepos' => $request->kodepos,
                'telepon' => $request->telepon,
                'email' => $request->email,
                'fax' => $request->fax,
                'tgl_pendirian' => $request->tgl_pendirian,
                'no_lisensi' => $request->no_lisensi,
                'masa_berlaku' => $request->masa_berlaku,
                'id_skkni' => $request->id_skkni,
                'id_tuk_bnsp' => $request->id_tuk_bnsp,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'TUK berhasil ditambahkan',
                'data' => [
                    'id' => $tuk->id,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan TUK: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update TUK
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $tuk = Tuk::find($id);

        if (!$tuk) {
            return response()->json([
                'success' => false,
                'message' => 'TUK tidak ditemukan',
            ], 404);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'kode_tuk' => 'required|unique:tuk,kode_tuk,' . $id,
            'nama' => 'required',
            'penanggungjawab' => 'required',
            'jenis_tuk' => 'required',
            'lsp_induk' => 'required',
            'id_wilayah' => 'required',
            'id_skkni' => 'required',
            'masa_berlaku' => 'nullable|date',
            'tgl_pendirian' => 'nullable|date',
        ], [
            'kode_tuk.required' => 'Kode TUK wajib diisi',
            'kode_tuk.unique' => 'Kode TUK sudah digunakan',
            'nama.required' => 'Nama TUK wajib diisi',
            'penanggungjawab.required' => 'Penanggung jawab wajib diisi',
            'jenis_tuk.required' => 'Jenis TUK wajib diisi',
            'lsp_induk.required' => 'LSP Induk wajib diisi',
            'id_wilayah.required' => 'Wilayah wajib diisi',
            'id_skkni.required' => 'SKKNI wajib diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $tuk->update([
                'kode_tuk' => $request->kode_tuk,
                'nama' => $request->nama,
                'penanggungjawab' => $request->penanggungjawab,
                'jenis_tuk' => $request->jenis_tuk,
                'lsp_induk' => $request->lsp_induk,
                'institusi_induk' => $request->institusi_induk,
                'alamat' => $request->alamat,
                'kelurahan' => $request->kelurahan,
                'id_wilayah' => $request->id_wilayah,
                'kodepos' => $request->kodepos,
                'telepon' => $request->telepon,
                'email' => $request->email,
                'fax' => $request->fax,
                'tgl_pendirian' => $request->tgl_pendirian,
                'no_lisensi' => $request->no_lisensi,
                'masa_berlaku' => $request->masa_berlaku,
                'id_skkni' => $request->id_skkni,
                'id_tuk_bnsp' => $request->id_tuk_bnsp,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'TUK berhasil diperbarui',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui TUK: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete TUK
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $tuk = Tuk::find($id);

        if (!$tuk) {
            return response()->json([
                'success' => false,
                'message' => 'TUK tidak ditemukan',
            ], 404);
        }

        // Check if TUK is used in any jadwal
        if (!$tuk->canBeDeleted()) {
            return response()->json([
                'success' => false,
                'message' => 'TUK tidak dapat dihapus karena sudah digunakan dalam jadwal asesmen',
            ], 400);
        }

        try {
            $tuk->delete();

            return response()->json([
                'success' => true,
                'message' => 'TUK berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus TUK: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Persyaratan TUK by Skema ID
     *
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function persyaratanBySkema($skemaId)
    {
        $persyaratan = SkemaPersyaratantuk::bySkema($skemaId)->get();

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
}
