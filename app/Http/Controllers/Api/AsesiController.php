<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesi;
use App\Models\AsesiAsesmen;
use App\Models\AsesiDoc;
use App\Models\AsesiPembayaran;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AsesiController extends Controller
{
    /**
     * Get all peserta with pagination
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $tab = $request->get('tab', 'all');

        // Route to appropriate tab handler
        switch ($tab) {
            case 'kompeten':
                return $this->getTabKompeten($request);
            case 'belum_kompeten':
                return $this->getTabBelumKompeten($request);
            case 'belum_verifikasi':
                return $this->getTabBelumVerifikasi($request);
            case 'terverifikasi':
                return $this->getTabTerverifikasi($request);
            case 'diblokir':
                return $this->getTabDiblokir($request);
            default:
                return $this->getAllPeserta($request);
        }
    }

    /**
     * Get all peserta (default tab)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getAllPeserta(Request $request)
    {
        $query = Asesi::query();

        // Apply filters
        $this->applyCommonFilters($query, $request);

        // Sorting
        $sortBy = $request->get('sort_by', 'tgl_daftar');
        $sortOrder = $request->get('sort_order', 'desc');

        if (!in_array($sortBy, ['id', 'nama', 'no_pendaftaran', 'tgl_daftar', 'angkatan'])) {
            $sortBy = 'tgl_daftar';
        }

        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        return $this->paginateAndRespond($query, $request, 'all');
    }

    /**
     * Get Tab KOMPETEN - Peserta yang Kompeten
     * SQL: SELECT DISTINCT id_asesi FROM asesi_asesmen WHERE status_asesmen='K'
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getTabKompeten(Request $request)
    {
        // Get distinct asesi IDs from asesi_asesmen where status_asesmen='K'
        $asesiIds = AsesiAsesmen::where('status_asesmen', 'K')
            ->distinct()
            ->pluck('id_asesi');

        $query = Asesi::whereIn('no_pendaftaran', $asesiIds);

        // Apply additional filters
        $this->applyCommonFilters($query, $request);

        // Sorting - by default sort by no_pendaftaran DESC
        $sortBy = $request->get('sort_by', 'no_pendaftaran');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination with custom transformer
        return $this->paginateAndRespond($query, $request, 'kompeten');
    }

    /**
     * Get Tab BELUM KOMPETEN - Peserta yang Belum Kompeten
     * SQL: SELECT DISTINCT id_asesi FROM asesi_asesmen WHERE status_asesmen='BK'
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getTabBelumKompeten(Request $request)
    {
        // Get distinct asesi IDs from asesi_asesmen where status_asesmen='BK'
        $asesiIds = AsesiAsesmen::where('status_asesmen', 'BK')
            ->distinct()
            ->pluck('id_asesi');

        $query = Asesi::whereIn('no_pendaftaran', $asesiIds);

        // Apply additional filters
        $this->applyCommonFilters($query, $request);

        // Sorting - by default sort by no_pendaftaran DESC
        $sortBy = $request->get('sort_by', 'no_pendaftaran');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination with custom transformer
        return $this->paginateAndRespond($query, $request, 'belum_kompeten');
    }

    /**
     * Get Tab BELUM VERIFIKASI - Peserta Belum Terverifikasi
     * SQL: SELECT * FROM asesi WHERE verifikasi='P' AND blokir='N'
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getTabBelumVerifikasi(Request $request)
    {
        $query = Asesi::where('verifikasi', 'P')
            ->where('blokir', 'N');

        // Apply additional filters (except verifikasi and blokir)
        if ($request->has('search')) {
            $query->search($request->search);
        }

        if ($request->has('angkatan')) {
            $query->byAngkatan($request->angkatan);
        }

        if ($request->has('propinsi')) {
            $query->byPropinsi($request->propinsi);
        }

        // Sorting - by default sort by nama ASC
        $sortBy = $request->get('sort_by', 'nama');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        return $this->paginateAndRespond($query, $request, 'belum_verifikasi');
    }

    /**
     * Get Tab TERVERIFIKASI - Peserta Terverifikasi
     * SQL: SELECT * FROM asesi WHERE verifikasi='V' AND blokir='N'
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getTabTerverifikasi(Request $request)
    {
        $query = Asesi::where('verifikasi', 'V')
            ->where('blokir', 'N');

        // Apply additional filters (except verifikasi and blokir)
        if ($request->has('search')) {
            $query->search($request->search);
        }

        if ($request->has('angkatan')) {
            $query->byAngkatan($request->angkatan);
        }

        if ($request->has('propinsi')) {
            $query->byPropinsi($request->propinsi);
        }

        // Sorting - by default sort by nama ASC
        $sortBy = $request->get('sort_by', 'nama');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        return $this->paginateAndRespond($query, $request, 'terverifikasi');
    }

    /**
     * Get Tab DIBLOKIR - Peserta Diblokir
     * SQL: SELECT * FROM asesi WHERE blokir='Y'
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getTabDiblokir(Request $request)
    {
        $query = Asesi::where('blokir', 'Y');

        // Apply additional filters (except blokir)
        if ($request->has('search')) {
            $query->search($request->search);
        }

        if ($request->has('angkatan')) {
            $query->byAngkatan($request->angkatan);
        }

        if ($request->has('propinsi')) {
            $query->byPropinsi($request->propinsi);
        }

        // Sorting - by default sort by nama ASC
        $sortBy = $request->get('sort_by', 'nama');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        return $this->paginateAndRespond($query, $request, 'diblokir');
    }

    /**
     * Apply common filters for all tabs
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param Request $request
     * @return void
     */
    private function applyCommonFilters($query, Request $request)
    {
        // Search
        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Filter by verifikasi (only if not in kompeten/belum_kompeten tabs)
        if ($request->has('verifikasi')) {
            $query->where('verifikasi', $request->verifikasi);
        }

        // Filter by blokir (only if not in kompeten/belum_kompeten tabs)
        if ($request->has('blokir')) {
            $query->where('blokir', $request->blokir);
        }

        // Filter by angkatan
        if ($request->has('angkatan')) {
            $query->byAngkatan($request->angkatan);
        }

        // Filter by propinsi
        if ($request->has('propinsi')) {
            $query->byPropinsi($request->propinsi);
        }
    }

    /**
     * Paginate and format response
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param Request $request
     * @param string $tab
     * @return \Illuminate\Http\JsonResponse
     */
    private function paginateAndRespond($query, Request $request, $tab)
    {
        // Pagination
        $perPage = min((int) $request->get('per_page', 20), 100);
        $page = (int) $request->get('page', 1);

        $asesi = $query->paginate($perPage, ['*'], 'page', $page);

        // Transform data based on tab
        $data = collect($asesi->items())->map(function ($item) use ($tab) {
            if (in_array($tab, ['kompeten', 'belum_kompeten'])) {
                return $this->transformAsesiWithSkema($item, $tab);
            }
            return $this->transformAsesi($item);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'tab' => $tab,
            'pagination' => [
                'current_page' => $asesi->currentPage(),
                'per_page' => $asesi->perPage(),
                'total' => $asesi->total(),
                'last_page' => $asesi->lastPage(),
                'from' => $asesi->firstItem(),
                'to' => $asesi->lastItem(),
            ],
        ]);
    }

    /**
     * Get peserta detail by no_pendaftaran
     *
     * @param string $noPendaftaran
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($noPendaftaran)
    {
        // Load asesi without eager loading problematic relationships
        $asesi = Asesi::where('no_pendaftaran', $noPendaftaran)->first();

        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        // Load relationships manually to avoid pivot column issues
        $asesi->load(['skema', 'dokumen', 'pembayaran']);

        return response()->json([
            'success' => true,
            'data' => $this->transformAsesiDetail($asesi),
        ]);
    }

    /**
     * Store new peserta
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'nama' => 'required|string|max:100',
            'no_ktp' => 'required|string|unique:asesi,no_ktp',
            'tmp_lahir' => 'nullable|string',
            'tgl_lahir' => 'nullable|date',
            'jenis_kelamin' => 'required|in:L,P',
            'pendidikan' => 'nullable|string',
            'alamat' => 'nullable|string',
            'RT' => 'nullable|string|max:10',
            'RW' => 'nullable|string|max:10',
            'propinsi' => 'nullable|string',
            'kota' => 'nullable|string',
            'kecamatan' => 'nullable|string',
            'kodepos' => 'nullable|string|max:10',
            'nohp' => 'required|string|max:20',
            'email' => 'nullable|email',
            'wil_ujikom' => 'nullable|string',
            'foto' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'ktp' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'kk' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'ijazah' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'transkrip' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ], [
            'nama.required' => 'Nama wajib diisi',
            'no_ktp.required' => 'No. KTP wajib diisi',
            'no_ktp.unique' => 'No. KTP sudah terdaftar',
            'nohp.required' => 'Nomor HP wajib diisi',
            'jenis_kelamin.required' => 'Jenis kelamin wajib dipilih',
            'jenis_kelamin.in' => 'Jenis kelamin harus L atau P',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Generate no_pendaftaran
            $noPendaftaran = Asesi::generateNoPendaftaran();
            $tglDaftar = now()->toDateString();
            $angkatan = now()->year;

            // Handle file uploads
            $data = $request->except(['foto', 'ktp', 'kk', 'ijazah', 'transkrip']);
            $data['no_pendaftaran'] = $noPendaftaran;
            $data['tgl_daftar'] = $tglDaftar;
            $data['angkatan'] = $angkatan;
            $data['verifikasi'] = 'P';
            $data['blokir'] = 'N';

            // Upload files
            if ($request->hasFile('foto')) {
                $foto = $request->file('foto');
                $fotoPath = $foto->storeAs('foto_asesi', $noPendaftaran . '_foto.' . $foto->getClientOriginalExtension(), 'public');
                $data['foto'] = basename($fotoPath);
            }

            if ($request->hasFile('ktp')) {
                $ktp = $request->file('ktp');
                $ktpPath = $ktp->storeAs('foto_asesi', $noPendaftaran . '_ktp.' . $ktp->getClientOriginalExtension(), 'public');
                $data['ktp'] = basename($ktpPath);
            }

            if ($request->hasFile('kk')) {
                $kk = $request->file('kk');
                $kkPath = $kk->storeAs('foto_asesi', $noPendaftaran . '_kk.' . $kk->getClientOriginalExtension(), 'public');
                $data['kk'] = basename($kkPath);
            }

            if ($request->hasFile('ijazah')) {
                $ijazah = $request->file('ijazah');
                $ijazahPath = $ijazah->storeAs('foto_asesi', $noPendaftaran . '_ijazah.' . $ijazah->getClientOriginalExtension(), 'public');
                $data['ijazah'] = basename($ijazahPath);
            }

            if ($request->hasFile('transkrip')) {
                $transkrip = $request->file('transkrip');
                $transkripPath = $transkrip->storeAs('foto_asesi', $noPendaftaran . '_transkrip.' . $transkrip->getClientOriginalExtension(), 'public');
                $data['transkrip'] = basename($transkripPath);
            }

            $asesi = Asesi::create($data);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Peserta berhasil didaftarkan',
                'data' => [
                    'id' => $asesi->id,
                    'no_pendaftaran' => $asesi->no_pendaftaran,
                    'tgl_daftar' => $asesi->tgl_daftar,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal mendaftarkan peserta: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update peserta
     *
     * @param Request $request
     * @param string $noPendaftaran
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $noPendaftaran)
    {
        $asesi = Asesi::where('no_pendaftaran', $noPendaftaran)->first();

        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'nama' => 'required|string|max:100',
            'no_ktp' => 'required|string|unique:asesi,no_ktp,' . $asesi->id,
            'tmp_lahir' => 'nullable|string',
            'tgl_lahir' => 'nullable|date',
            'jenis_kelamin' => 'required|in:L,P',
            'pendidikan' => 'nullable|string',
            'alamat' => 'nullable|string',
            'propinsi' => 'nullable|string',
            'kota' => 'nullable|string',
            'kecamatan' => 'nullable|string',
            'kodepos' => 'nullable|string|max:10',
            'nohp' => 'required|string|max:20',
            'email' => 'nullable|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $asesi->update($request->except(['no_pendaftaran', 'password']));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data peserta berhasil diperbarui',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete peserta (cascading delete)
     *
     * @param string $noPendaftaran
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($noPendaftaran)
    {
        $asesi = Asesi::where('no_pendaftaran', $noPendaftaran)->first();

        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Delete files
            $filesToDelete = [];
            if ($asesi->foto) $filesToDelete[] = 'foto_asesi/' . $asesi->foto;
            if ($asesi->ktp) $filesToDelete[] = 'foto_asesi/' . $asesi->ktp;
            if ($asesi->kk) $filesToDelete[] = 'foto_asesi/' . $asesi->kk;
            if ($asesi->ijazah) $filesToDelete[] = 'foto_asesi/' . $asesi->ijazah;
            if ($asesi->transkrip) $filesToDelete[] = 'foto_asesi/' . $asesi->transkrip;

            // Delete from asesi_doc (and their files)
            $dokumen = AsesiDoc::where('id_asesi', $noPendaftaran)->get();
            foreach ($dokumen as $doc) {
                if ($doc->file) {
                    $filesToDelete[] = 'foto_asesi/' . $doc->file;
                }
            }
            AsesiDoc::where('id_asesi', $noPendaftaran)->delete();

            // Delete from asesi_pembayaran (and their files)
            $pembayaran = AsesiPembayaran::where('id_asesi', $noPendaftaran)->get();
            foreach ($pembayaran as $pembayaran) {
                if ($pembayaran->bukti_bayar) {
                    $filesToDelete[] = 'foto_buktibayar/' . $pembayaran->bukti_bayar;
                }
            }
            AsesiPembayaran::where('id_asesi', $noPendaftaran)->delete();

            // Delete from asesi_asesmen
            AsesiAsesmen::where('id_asesi', $noPendaftaran)->delete();

            // Delete from asesi_apl02 and asesi_apl02doc (if exists)
            // Add similar logic for other related tables

            // Delete the asesi record
            $asesi->delete();

            // Delete physical files
            foreach ($filesToDelete as $file) {
                if (Storage::disk('public')->exists($file)) {
                    Storage::disk('public')->delete($file);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Peserta beserta seluruh data terkait berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus peserta: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update blokir status
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateBlokir(Request $request, $id)
    {
        $asesi = Asesi::find($id);

        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        try {
            $asesi->update([
                'blokir' => $request->blokir ? 'Y' : 'N',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Status blokir berhasil diperbarui',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update verifikasi status
     *
     * @param Request $request
     * @param string $noPendaftaran
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateVerifikasi(Request $request, $noPendaftaran)
    {
        $asesi = Asesi::where('no_pendaftaran', $noPendaftaran)->first();

        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        try {
            $asesi->update([
                'verifikasi' => $request->verifikasi, // P or V
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Status verifikasi berhasil diperbarui',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get peserta statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics()
    {
        // Tab statistics
        $stats = [
            'all' => Asesi::count(),
            'kompeten' => AsesiAsesmen::where('status_asesmen', 'K')->distinct('id_asesi')->count(),
            'belum_kompeten' => AsesiAsesmen::where('status_asesmen', 'BK')->distinct('id_asesi')->count(),
            'belum_verifikasi' => Asesi::where('verifikasi', 'P')->where('blokir', 'N')->count(),
            'terverifikasi' => Asesi::where('verifikasi', 'V')->where('blokir', 'N')->count(),
            'diblokir' => Asesi::where('blokir', 'Y')->count(),
        ];

        // Additional detailed stats
        $total = Asesi::count();
        $verified = Asesi::verified()->count();
        $pending = Asesi::pending()->count();
        $blocked = Asesi::blocked()->count();

        // By angkatan
        $byAngkatan = Asesi::select(DB::raw('angkatan, COUNT(*) as total'))
            ->whereNotNull('angkatan')
            ->groupBy('angkatan')
            ->orderBy('angkatan', 'desc')
            ->get();

        // By propinsi
        $byPropinsi = DB::table('asesi as a')
            ->select('w.nm_wil as propinsi', DB::raw('COUNT(*) as total'))
            ->leftJoin('data_wilayah as w', 'a.propinsi', '=', 'w.id_wil')
            ->whereNotNull('a.propinsi')
            ->groupBy('w.nm_wil')
            ->orderBy('total', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'verified' => $verified,
                'pending' => $pending,
                'blocked' => $blocked,
                'kompeten' => $stats['kompeten'],
                'belum_kompeten' => $stats['belum_kompeten'],
                'belum_verifikasi' => $stats['belum_verifikasi'],
                'terverifikasi' => $stats['terverifikasi'],
                'diblokir' => $stats['diblokir'],
                'by_angkatan' => $byAngkatan,
                'by_propinsi' => $byPropinsi,
            ],
        ]);
    }

    /**
     * Transform asesi data
     */
    private function transformAsesi($asesi)
    {
        return [
            'id' => $asesi->id,
            'no_pendaftaran' => $asesi->no_pendaftaran,
            'nama' => $asesi->nama,
            'no_ktp' => $asesi->no_ktp,
            'nohp' => $asesi->nohp,
            'whatsapp' => $asesi->whatsapp,
            'email' => $asesi->email,
            'tgl_lahir' => $asesi->tgl_lahir ? $asesi->tgl_lahir->format('Y-m-d') : null,
            'jenis_kelamin' => $asesi->jenis_kelamin,
            'tgl_daftar' => $asesi->tgl_daftar ? $asesi->tgl_daftar->format('Y-m-d') : null,
            'angkatan' => $asesi->angkatan,
            'verifikasi' => $asesi->verifikasi,
            'blokir' => $asesi->blokir,
            'status' => $asesi->status_label,
            'dokumen_pokok' => $asesi->dokumen_pokok,
            'dokumen_lengkap' => $asesi->dokumen_lengkap,
            'statistik_asesmen' => $asesi->statistik_asesmen,
        ];
    }

    /**
     * Transform asesi data with skema detail (for Kompeten & Belum Kompeten tabs)
     *
     * @param Asesi $asesi
     * @param string $tab - 'kompeten' or 'belum_kompeten'
     * @return array
     */
    private function transformAsesiWithSkema($asesi, $tab)
    {
        $data = $this->transformAsesi($asesi);

        // Get skema yang diikuti berdasarkan tab
        $statusAsesmen = $tab === 'kompeten' ? 'K' : 'BK';

        // Load skema relationship with pivot data
        $skemaList = AsesiAsesmen::where('id_asesi', $asesi->no_pendaftaran)
            ->where('status_asesmen', $statusAsesmen)
            ->with(['skema', 'jadwal', 'asesor'])
            ->get()
            ->map(function ($asesmen) use ($asesi) {
                $jumlahDokumen = AsesiDoc::where('id_asesi', $asesi->no_pendaftaran)
                    ->where('id_skemakkni', $asesmen->id_skemakkni)
                    ->count();

                return [
                    'id' => $asesmen->id,
                    'id_skemakkni' => $asesmen->id_skemakkni,
                    'skema' => [
                        'id' => $asesmen->skema->id ?? null,
                        'kode_skema' => $asesmen->skema->kode_skema ?? '',
                        'judul' => $asesmen->skema->judul ?? '',
                    ],
                    'tgl_asesmen' => $asesmen->tgl_asesmen ? $asesmen->tgl_asesmen->format('Y-m-d') : null,
                    'asesor' => $asesmen->asesor ? [
                        'id' => $asesmen->asesor->id,
                        'nama' => $asesmen->asesor->nama,
                    ] : null,
                    'status_asesmen' => $asesmen->status_asesmen,
                    'no_lisensi' => $asesmen->no_lisensi,
                    'no_seri_sertifikat' => $asesmen->no_serisertifikat,
                    'masa_berlaku' => $asesmen->masa_berlaku ? $asesmen->masa_berlaku->format('Y-m-d') : null,
                    'ploting_asesor' => !empty($asesmen->id_asesor) && $asesmen->id_asesor != '0',
                    'sertifikat_ada' => !empty($asesmen->no_lisensi) || !empty($asesmen->no_serisertifikat),
                    'jumlah_dokumen' => $jumlahDokumen,
                    'dokumen_lengkap' => $jumlahDokumen > 0,
                ];
            });

        $data['skema_list'] = $skemaList;
        $data['total_skema'] = $skemaList->count();

        return $data;
    }

    /**
     * Transform asesi detail
     */
    private function transformAsesiDetail($asesi)
    {
        $data = $this->transformAsesi($asesi);

        // Add wilayah info
        $data['wilayah'] = [
            'propinsi' => $asesi->propinsi,
            'kota' => $asesi->kota,
            'kecamatan' => $asesi->kecamatan,
            'kelurahan' => $asesi->kelurahan,
            'alamat_lengkap' => $asesi->alamat,
            'rt' => $asesi->RT,
            'rw' => $asesi->RW,
            'kodepos' => $asesi->kodepos,
        ];

        // Add file URLs
        $data['files'] = [
            'foto' => $asesi->getFileUrl('foto'),
            'ktp' => $asesi->getFileUrl('ktp'),
            'kk' => $asesi->getFileUrl('kk'),
            'ijazah' => $asesi->getFileUrl('ijazah'),
            'transkrip' => $asesi->getFileUrl('transkrip'),
        ];

        // Add skema yang diikuti
        $data['skema_diikuti'] = $asesi->skema->map(function ($skema) {
            return [
                'id' => $skema->id,
                'judul' => $skema->judul,
                'kode_skema' => $skema->kode_skema,
                'status' => $skema->pivot->status,
                'status_asesmen' => $skema->pivot->status_asesmen,
                'no_sertifikat' => $skema->pivot->no_lisensi,
                'masa_berlaku' => $skema->pivot->masa_berlaku,
            ];
        });

        // Add dokumen per skema
        $data['dokumen_skema'] = AsesiDoc::where('id_asesi', $asesi->no_pendaftaran)
            ->get()
            ->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'jenis_doc' => $doc->jenis_doc,
                    'file' => $doc->file_url,
                    'verifikasi' => $doc->verifikasi,
                    'verifikasi_label' => $doc->verifikasi_label,
                    'catatan' => $doc->catatan,
                ];
            });

        return $data;
    }
}
