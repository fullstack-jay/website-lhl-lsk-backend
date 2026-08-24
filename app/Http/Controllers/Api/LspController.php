<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lsp;
use App\Models\LspJenis;
use App\Models\DataWilayah;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LspController extends Controller
{
    /**
     * Get all LSP with pagination, filtering, and sorting
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Lsp::with(['jenisLsp:id,kode,nama_kategori', 'wilayah:id_wil,nm_wil,id_level_wil,id_induk_wilayah'])
            ->with(['wilayah.parent:id_wil,nm_wil,id_level_wil,id_induk_wilayah'])
            ->with(['wilayah.parent.parent:id_wil,nm_wil,id_level_wil,id_induk_wilayah']);

        // Search
        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Filter by jenis
        if ($request->has('jenis_lsp')) {
            $query->where('jenis_lsp', $request->jenis_lsp);
        }

        // Filter by license status
        if ($request->has('license_status')) {
            if ($request->license_status === 'active') {
                $query->licenseActive();
            } elseif ($request->license_status === 'expired') {
                $query->licenseExpired();
            }
        }

        // Filter by wilayah (provinsi)
        if ($request->has('id_provinsi')) {
            $query->whereHas('wilayah.parent.parent', function ($q) use ($request) {
                $q->where('id_wil', $request->id_provinsi);
            });
        }

        // Filter by wilayah (kota)
        if ($request->has('id_kota')) {
            $query->whereHas('wilayah.parent', function ($q) use ($request) {
                $q->where('id_wil', $request->id_kota);
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'id');
        $sortOrder = $request->get('sort_order', 'asc');

        if (!in_array($sortBy, ['id', 'nama', 'kode_lsp', 'masa_berlaku', 'tgl_pendirian'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = min((int) $request->get('per_page', 20), 100);
        $page = (int) $request->get('page', 1);

        $lsp = $query->paginate($perPage, ['*'], 'page', $page);

        // Transform data with eager loaded wilayah hierarchy
        $data = $lsp->map(function ($item) {
            return $this->transformLsp($item, false, true);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $lsp->currentPage(),
                'per_page' => $lsp->perPage(),
                'total' => $lsp->total(),
                'last_page' => $lsp->lastPage(),
                'from' => $lsp->firstItem(),
                'to' => $lsp->lastItem(),
            ],
        ]);
    }

    /**
     * Get LSP detail by ID
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $lsp = Lsp::with(['jenisLsp', 'wilayah'])->find($id);

        if (!$lsp) {
            return response()->json([
                'success' => false,
                'message' => 'LSK tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->transformLsp($lsp, true),
        ]);
    }

    /**
     * Create new LSP
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kode_lsp' => 'required|unique:lsp,kode_lsp',
            'nama' => 'required',
            'jenis_lsp' => 'required|exists:lsp_jenis,kode',
            'direktur' => 'required',
            'nama_jabatanpimpinan' => 'nullable|in:Ketua,Direktur',
            'penanggungjawab' => 'nullable',
            'manajer_sertifikasi' => 'nullable',
            'institusi_induk' => 'nullable',
            'alamat' => 'nullable',
            'kelurahan' => 'nullable',
            'id_wilayah' => 'nullable|exists:data_wilayah,id_wil',
            'kodepos' => 'nullable',
            'telepon' => 'nullable',
            'email' => 'nullable|email',
            'email_alternatif' => 'nullable|email',
            'fax' => 'nullable',
            'wa' => 'nullable',
            'website' => 'nullable',
            'tgl_pendirian' => 'nullable|date',
            'no_lisensi' => 'nullable',
            'masa_berlaku' => 'nullable|date',
            'id_skkni' => 'nullable',
            'googlemapcode' => 'nullable',
            'meta_keywords' => 'nullable',
            'logo' => 'nullable|mimes:jpg,jpeg|max:2048',
            'ttddigital' => 'nullable|mimes:png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->except(['logo', 'ttddigital']);

        // Handle logo upload (JPG only)
        if ($request->hasFile('logo')) {
            $logoFile = $request->file('logo');
            $logoName = 'logolsp.' . $logoFile->getClientOriginalExtension();
            $logoFile->move(public_path('images'), $logoName);
            $data['logo'] = $logoName;
        }

        // Handle TTD digital upload (PNG only)
        if ($request->hasFile('ttddigital')) {
            $ttdFile = $request->file('ttddigital');
            $ttdName = 'ttd-stempel-pimpinan.' . $ttdFile->getClientOriginalExtension();
            $ttdFile->move(public_path('images'), $ttdName);
            $data['ttddigital'] = $ttdName;
        }

        // Convert empty strings to null
        foreach ($data as $key => $value) {
            if ($value === '') {
                $data[$key] = null;
            }
        }

        $lsp = Lsp::create($data);

        return response()->json([
            'success' => true,
            'message' => 'LSK berhasil ditambahkan',
            'data' => $this->transformLsp($lsp->load(['jenisLsp', 'wilayah'])),
        ], 201);
    }

    /**
     * Update LSP
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $lsp = Lsp::find($id);

        if (!$lsp) {
            return response()->json([
                'success' => false,
                'message' => 'LSK tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'kode_lsp' => 'required|unique:lsp,kode_lsp,' . $id,
            'nama' => 'required',
            'jenis_lsp' => 'required|exists:lsp_jenis,kode',
            'direktur' => 'required',
            'nama_jabatanpimpinan' => 'nullable|in:Ketua,Direktur',
            'penanggungjawab' => 'nullable',
            'manajer_sertifikasi' => 'nullable',
            'institusi_induk' => 'nullable',
            'alamat' => 'nullable',
            'kelurahan' => 'nullable',
            'id_wilayah' => 'nullable|exists:data_wilayah,id_wil',
            'kodepos' => 'nullable',
            'telepon' => 'nullable',
            'email' => 'nullable|email',
            'email_alternatif' => 'nullable|email',
            'fax' => 'nullable',
            'wa' => 'nullable',
            'website' => 'nullable',
            'tgl_pendirian' => 'nullable|date',
            'no_lisensi' => 'nullable',
            'masa_berlaku' => 'nullable|date',
            'id_skkni' => 'nullable',
            'googlemapcode' => 'nullable',
            'meta_keywords' => 'nullable',
            'logo' => 'nullable|mimes:jpg,jpeg|max:2048',
            'ttddigital' => 'nullable|mimes:png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->except(['logo', 'ttddigital']);

        // Handle logo upload (JPG only)
        if ($request->hasFile('logo')) {
            $logoFile = $request->file('logo');
            $logoName = 'logolsp.' . $logoFile->getClientOriginalExtension();

            // Delete old logo if exists
            if ($lsp->logo && file_exists(public_path('images/' . $lsp->logo))) {
                unlink(public_path('images/' . $lsp->logo));
            }

            $logoFile->move(public_path('images'), $logoName);
            $data['logo'] = $logoName;
        }

        // Handle TTD digital upload (PNG only)
        if ($request->hasFile('ttddigital')) {
            $ttdFile = $request->file('ttddigital');
            $ttdName = 'ttd-stempel-pimpinan.' . $ttdFile->getClientOriginalExtension();

            // Delete old TTD if exists
            if ($lsp->ttddigital && file_exists(public_path('images/' . $lsp->ttddigital))) {
                unlink(public_path('images/' . $lsp->ttddigital));
            }

            $ttdFile->move(public_path('images'), $ttdName);
            $data['ttddigital'] = $ttdName;
        }

        // Convert empty strings to null
        foreach ($data as $key => $value) {
            if ($value === '') {
                $data[$key] = null;
            }
        }

        $lsp->update($data);

        return response()->json([
            'success' => true,
            'message' => 'LSK berhasil diperbarui',
            'data' => $this->transformLsp($lsp->load(['jenisLsp', 'wilayah'])),
        ]);
    }

    /**
     * Delete LSP
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $lsp = Lsp::find($id);

        if (!$lsp) {
            return response()->json([
                'success' => false,
                'message' => 'LSK tidak ditemukan',
            ], 404);
        }

        // Check if LSP has related data
        if ($lsp->biayaSertifikasi()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'LSK tidak dapat dihapus karena masih memiliki data biaya sertifikasi',
            ], 400);
        }

        if ($lsp->rekeningbayar()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'LSK tidak dapat dihapus karena masih memiliki data rekening bank',
            ], 400);
        }

        // Delete files
        if ($lsp->logo && file_exists(public_path('images/' . $lsp->logo))) {
            unlink(public_path('images/' . $lsp->logo));
        }

        if ($lsp->ttddigital && file_exists(public_path('images/' . $lsp->ttddigital))) {
            unlink(public_path('images/' . $lsp->ttddigital));
        }

        $lsp->delete();

        return response()->json([
            'success' => true,
            'message' => 'LSK berhasil dihapus',
        ]);
    }

    /**
     * Get LSP options for dropdown
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function options()
    {
        $lsp = Lsp::orderBy('nama')->get(['id', 'nama']);

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

    /**
     * Get LSP statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics()
    {
        $total = Lsp::count();
        $active = Lsp::licenseActive()->count();
        $expired = Lsp::licenseExpired()->count();

        // Per jenis
        $perJenis = Lsp::selectRaw('jenis_lsp, COUNT(*) as total')
            ->with('jenisLsp:id,kode,nama_kategori')
            ->groupBy('jenis_lsp')
            ->get()
            ->map(function ($item) {
                return [
                    'jenis' => $item->jenis_lsp,
                    'nama_kategori' => $item->jenisLsp->nama_kategori ?? 'Unknown',
                    'total' => $item->total,
                ];
            });

        // Per provinsi
        $lspWithWilayah = Lsp::whereHas('wilayah.parent.parent')
            ->with(['wilayah.parent.parent:id_wil,nm_wil'])
            ->get();

        $perProvinsi = $lspWithWilayah
            ->groupBy(function ($item) {
                return $item->wilayah->parent->parent->nm_wil ?? 'Unknown';
            })
            ->map(function ($items, $provinsi) {
                return [
                    'provinsi' => $provinsi,
                    'total' => $items->count(),
                ];
            })
            ->sortByDesc('total')
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'active' => $active,
                'expired' => $expired,
                'per_jenis' => $perJenis,
                'per_provinsi' => $perProvinsi,
            ],
        ]);
    }

    /**
     * Transform LSP data
     *
     * @param Lsp $lsp
     * @param bool $detail
     * @param bool $includeLocation
     * @return array
     */
    private function transformLsp(Lsp $lsp, bool $detail = false, bool $includeLocation = false): array
    {
        $data = [
            'id' => $lsp->id,
            'kode_lsp' => $lsp->kode_lsp,
            'nama' => $lsp->nama,
            'jenis_lsp' => $lsp->jenis_lsp,
            'jenis_lsp_obj' => $lsp->jenisLsp ? [
                'kode' => $lsp->jenisLsp->kode,
                'nama_kategori' => $lsp->jenisLsp->nama_kategori,
            ] : null,
            'direktur' => $lsp->direktur,
            'nama_jabatanpimpinan' => $lsp->nama_jabatanpimpinan,
            'penanggungjawab' => $lsp->penanggungjawab,
            'manajer_sertifikasi' => $lsp->manajer_sertifikasi,
            'telepon' => $lsp->telepon,
            'email' => $lsp->email,
            'wa' => $lsp->wa,
            'website' => $lsp->website,
            'no_lisensi' => $lsp->no_lisensi,
            'masa_berlaku' => $lsp->masa_berlaku ? $lsp->masa_berlaku->format('Y-m-d') : null,
            'tgl_pendirian' => $lsp->tgl_pendirian ? $lsp->tgl_pendirian->format('Y-m-d') : null,
            'license_status' => $lsp->license_status,
            'logo_url' => $lsp->logo_url,
            'ttd_url' => $lsp->ttd_url,
            'created_at' => $detail ? optional($lsp->created_at)->format('Y-m-d H:i:s') : null,
        ];

        // Add location data for list view or detail view
        if ($includeLocation || $detail) {
            $locationData = $this->getLocationData($lsp);
            $data = array_merge($data, $locationData);
        }

        if ($detail) {
            $data = array_merge($data, [
                'institusi_induk' => $lsp->institusi_induk,
                'alamat' => $lsp->alamat,
                'kelurahan' => $lsp->kelurahan,
                'kodepos' => $lsp->kodepos,
                'email_alternatif' => $lsp->email_alternatif,
                'fax' => $lsp->fax,
                'googlemapcode' => $lsp->googlemapcode,
                'meta_keywords' => $lsp->meta_keywords,
                'id_skkni' => $lsp->id_skkni,
                'full_address' => $lsp->full_address,
                'wilayah' => $lsp->wilayah ? [
                    'id_wil' => $lsp->wilayah->id_wil,
                    'nm_wil' => $lsp->wilayah->nm_wil,
                    'id_level_wil' => $lsp->wilayah->id_level_wil,
                ] : null,
            ]);
        }

        return $data;
    }

    /**
     * Get location data from LSP
     *
     * @param Lsp $lsp
     * @return array
     */
    private function getLocationData(Lsp $lsp): array
    {
        $location = [
            'alamat' => $lsp->alamat,
            'kelurahan' => $lsp->kelurahan,
            'kodepos' => $lsp->kodepos,
        ];

        if ($lsp->wilayah) {
            $kecamatan = $lsp->wilayah;
            $kota = $kecamatan->parent ?? null;
            $provinsi = $kota ? $kota->parent ?? null : null;

            $location['kecamatan'] = $kecamatan->nm_wil ?? null;
            $location['kota'] = $kota->nm_wil ?? null;
            $location['provinsi'] = $provinsi->nm_wil ?? null;

            // Format location string
            $locationParts = array_filter([
                $lsp->alamat,
                $lsp->kelurahan,
                $kecamatan->nm_wil ? "Kec. {$kecamatan->nm_wil}" : null,
                $kota->nm_wil ?? null,
                $provinsi->nm_wil ?? null,
                $lsp->kodepos,
            ]);

            $location['lokasi'] = implode(', ', $locationParts);
        } else {
            $location['kecamatan'] = null;
            $location['kota'] = null;
            $location['provinsi'] = null;
            $location['lokasi'] = $lsp->alamat ?? '-';
        }

        return $location;
    }
}
