<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesi;
use App\Models\MasterKeahlian;
use App\Models\AsesiAsesmen;
use App\Models\AsesiDoc;
use App\Models\AsesiPembayaran;
use App\Models\Pendaftaran;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

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
        $tab = str_replace('-', '_', $request->get('tab', 'all'));

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

        $query = Asesi::where(function ($q) use ($asesiIds) {
            $q->whereIn('no_pendaftaran', $asesiIds)
              ->orWhereIn('id', $asesiIds);
        });

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
        $asesiIds = AsesiAsesmen::whereIn('status_asesmen', ['BK', 'TL'])
            ->distinct()
            ->pluck('id_asesi');

        $query = Asesi::where(function ($q) use ($asesiIds) {
            $q->whereIn('no_pendaftaran', $asesiIds)
              ->orWhereIn('id', $asesiIds);
        });

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
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('angkatan')) {
            $query->byAngkatan($request->angkatan);
        }

        if ($request->filled('propinsi')) {
            $query->byPropinsi($request->propinsi);
        }

        // Filter by kelengkapan dan status dokumen
        $this->applyDokumenFilter($query, $request);

        // Prioritas Urutan (Sorting Cerdas):
        // 1. Lengkap 4/4 Terverifikasi (1000 poin)
        // 2. Ada dokumen terverifikasi (50 poin per berkas terverifikasi, walaupun baru 1)
        // 3. Sudah upload dokumen (5 poin per berkas yang diunggah)
        $query->orderByRaw("
            (
                CASE 
                    WHEN (
                        JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.ijazah')) = 'terverifikasi' AND
                        JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.sertifikat_amdal')) = 'terverifikasi' AND
                        JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.bukti_keterlibatan')) = 'terverifikasi' AND
                        JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.dokumen_amdal')) = 'terverifikasi'
                    ) THEN 1000
                    ELSE 0
                END
                +
                (
                    (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.ijazah')) = 'terverifikasi' THEN 50 ELSE 0 END) +
                    (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.sertifikat_amdal')) = 'terverifikasi' THEN 50 ELSE 0 END) +
                    (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.bukti_keterlibatan')) = 'terverifikasi' THEN 50 ELSE 0 END) +
                    (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.dokumen_amdal')) = 'terverifikasi' THEN 50 ELSE 0 END) +
                    (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.foto')) = 'terverifikasi' THEN 50 ELSE 0 END) +
                    (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.ktp')) = 'terverifikasi' THEN 50 ELSE 0 END) +
                    (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.cv')) = 'terverifikasi' THEN 50 ELSE 0 END)
                )
                +
                (
                    (CASE WHEN ijazah IS NOT NULL AND TRIM(ijazah) != '' THEN 5 ELSE 0 END) +
                    (CASE WHEN (sertifikat_amdal IS NOT NULL AND TRIM(sertifikat_amdal) != '') OR (sertifikat IS NOT NULL AND TRIM(sertifikat) != '') THEN 5 ELSE 0 END) +
                    (CASE WHEN (bukti_keterlibatan IS NOT NULL AND TRIM(bukti_keterlibatan) != '') OR (suket IS NOT NULL AND TRIM(suket) != '') THEN 5 ELSE 0 END) +
                    (CASE WHEN dokumen_amdal IS NOT NULL AND TRIM(dokumen_amdal) != '' THEN 5 ELSE 0 END) +
                    (CASE WHEN foto IS NOT NULL AND TRIM(foto) != '' THEN 5 ELSE 0 END) +
                    (CASE WHEN ktp IS NOT NULL AND TRIM(ktp) != '' THEN 5 ELSE 0 END) +
                    (CASE WHEN cv IS NOT NULL AND TRIM(cv) != '' THEN 5 ELSE 0 END)
                )
            ) DESC
        ");

        // Prioritas 2: Hindari data kosong/rusak di urutan awal
        $query->orderByRaw("CASE WHEN nama IS NULL OR TRIM(nama) = '' THEN 1 ELSE 0 END ASC");

        // Prioritas 3: Sorting nama / id
        if ($request->has('sort_by')) {
            $query->orderBy($request->get('sort_by'), $request->get('sort_order', 'asc'));
        } else {
            $query->orderBy('id', 'desc');
        }

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
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('angkatan')) {
            $query->byAngkatan($request->angkatan);
        }

        if ($request->filled('propinsi')) {
            $query->byPropinsi($request->propinsi);
        }

        // Filter dokumen
        $this->applyDokumenFilter($query, $request);

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
    /**
     * Apply filter kelengkapan dan status dokumen
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param Request $request
     * @return void
     */
    private function applyDokumenFilter($query, Request $request)
    {
        $filterDokumen = $request->get('dokumen_pokok');
        if (!$filterDokumen || $filterDokumen === 'semua') {
            return;
        }

        if ($filterDokumen === 'lengkap') {
            $query->whereRaw("(
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.ijazah')) = 'terverifikasi' AND
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.sertifikat_amdal')) = 'terverifikasi' AND
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.bukti_keterlibatan')) = 'terverifikasi' AND
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.dokumen_amdal')) = 'terverifikasi'
            )");
        } elseif ($filterDokumen === 'ada_verifikasi') {
            // Ada dokumen yang sudah diverifikasi (walaupun baru 1 dokumen)
            $query->whereRaw("(
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.ijazah')) = 'terverifikasi' OR
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.sertifikat_amdal')) = 'terverifikasi' OR
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.bukti_keterlibatan')) = 'terverifikasi' OR
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.dokumen_amdal')) = 'terverifikasi' OR
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.foto')) = 'terverifikasi' OR
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.ktp')) = 'terverifikasi' OR
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.cv')) = 'terverifikasi'
            )");
        } elseif ($filterDokumen === 'sudah_upload') {
            // Sudah upload dokumen (berkas terupload tapi belum/sebagian diverifikasi)
            $query->whereRaw("(
                (ijazah IS NOT NULL AND TRIM(ijazah) != '') OR
                (sertifikat_amdal IS NOT NULL AND TRIM(sertifikat_amdal) != '') OR
                (sertifikat IS NOT NULL AND TRIM(sertifikat) != '') OR
                (bukti_keterlibatan IS NOT NULL AND TRIM(bukti_keterlibatan) != '') OR
                (suket IS NOT NULL AND TRIM(suket) != '') OR
                (dokumen_amdal IS NOT NULL AND TRIM(dokumen_amdal) != '') OR
                (foto IS NOT NULL AND TRIM(foto) != '') OR
                (ktp IS NOT NULL AND TRIM(ktp) != '') OR
                (cv IS NOT NULL AND TRIM(cv) != '')
            )");
        } elseif ($filterDokumen === 'belum_upload') {
            // Belum upload berkas sama sekali
            $query->whereRaw("(
                (ijazah IS NULL OR TRIM(ijazah) = '') AND
                (sertifikat_amdal IS NULL OR TRIM(sertifikat_amdal) = '') AND
                (sertifikat IS NULL OR TRIM(sertifikat) = '') AND
                (bukti_keterlibatan IS NULL OR TRIM(bukti_keterlibatan) = '') AND
                (suket IS NULL OR TRIM(suket) = '') AND
                (dokumen_amdal IS NULL OR TRIM(dokumen_amdal) = '') AND
                (foto IS NULL OR TRIM(foto) = '') AND
                (ktp IS NULL OR TRIM(ktp) = '') AND
                (cv IS NULL OR TRIM(cv) = '')
            )");
        } elseif ($filterDokumen === 'belum_lengkap') {
            $query->whereRaw("NOT (
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.ijazah')) = 'terverifikasi' AND
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.sertifikat_amdal')) = 'terverifikasi' AND
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.bukti_keterlibatan')) = 'terverifikasi' AND
                JSON_UNQUOTE(JSON_EXTRACT(verifikasi_dokumen, '$.dokumen_amdal')) = 'terverifikasi'
            )");
        }
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
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filter by verifikasi (only if not in kompeten/belum_kompeten tabs)
        if ($request->filled('verifikasi')) {
            $query->where('verifikasi', $request->verifikasi);
        }

        // Filter by blokir (only if not in kompeten/belum_kompeten tabs)
        if ($request->filled('blokir')) {
            $query->where('blokir', $request->blokir);
        }

        // Filter by angkatan
        if ($request->filled('angkatan')) {
            $query->byAngkatan($request->angkatan);
        }

        // Filter by propinsi
        if ($request->filled('propinsi')) {
            $query->byPropinsi($request->propinsi);
        }

        // Filter by kota
        if ($request->filled('kota')) {
            $query->byKota($request->kota);
        }

        // Filter dokumen
        $this->applyDokumenFilter($query, $request);
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

        // Transform data based on tab - sertakan skema_list & data remedial untuk semua tab
        $data = collect($asesi->items())->map(function ($item) use ($tab) {
            return $this->transformAsesiWithSkema($item, $tab);
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
            'no_ktp' => 'required|string',   // NIK ganda diizinkan (pemegang multi-sertifikat / dual-role)
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

            // Sertifikat Kompetensi (opsional — catatan perubahan frontend)
            'no_sertifikat' => 'nullable|string|max:100',
            'tgl_sertifikat' => 'nullable|date',
            'suket' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',

            // NOTE: upload file Scan KTP dihapus dari form frontend
            'foto' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'ktp' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'ijazah' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'sertifikat_amdal' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'bukti_keterlibatan' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'dokumen_amdal' => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip,rar|max:10240',
            'cv' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'sertifikat_kompetensi_lain' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'form_pendaftaran' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'sertifikat_atpa_ktpa' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'transkrip' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'suket' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'sertifikat' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ], [
            'nama.required' => 'Nama wajib diisi',
            'no_ktp.required' => 'No. KTP wajib diisi',
            'no_ktp.unique' => 'No. KTP sudah terdaftar',
            'nohp.required' => 'Nomor HP wajib diisi',
            'jenis_kelamin.required' => 'Jenis kelamin wajib dipilih',
            'jenis_kelamin.in' => 'Jenis kelamin harus L atau P',
        ]);

        if ($validator->fails()) {
            \Log::info('Peserta Store Validation Failed', [
                'fields' => array_keys($request->all()),
                'files' => array_keys($request->allFiles()),
                'errors' => $validator->errors()->toArray(),
            ]);
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Generate no_pendaftaran (gunakan input frontend jika berformat REG-, atau generate baru)
            $noPendaftaran = $request->filled('no_pendaftaran') && str_starts_with($request->no_pendaftaran, 'REG-')
                ? $request->no_pendaftaran
                : Asesi::generateNoPendaftaran();
            $tglDaftar = now()->toDateString();
            $angkatan = now()->year;

            // Handle file uploads
            $data = $request->except(['foto', 'ktp', 'kk', 'ijazah', 'transkrip', 'suket']);
            $data['no_pendaftaran'] = $noPendaftaran;
            $data['tgl_daftar'] = $tglDaftar;
            $data['angkatan'] = $angkatan;
            $data['verifikasi'] = 'P';
            $data['blokir'] = 'N';

            // Metadata Sertifikat Kompetensi (opsional)
            if ($request->filled('keahlian_penyusun')) {
                $data['keahlian_penyusun'] = MasterKeahlian::recordMultipleIfNew($request->keahlian_penyusun);
            }
            $data['no_sertifikat'] = $request->filled('no_sertifikat') ? $request->no_sertifikat : null;
            $data['tgl_sertifikat'] = $request->filled('tgl_sertifikat') ? $request->tgl_sertifikat : null;

            // Upload files
            $docKeys = ['foto', 'ktp', 'ijazah', 'sertifikat_amdal', 'bukti_keterlibatan', 'dokumen_amdal', 'cv', 'sertifikat_kompetensi_lain', 'form_pendaftaran', 'sertifikat_atpa_ktpa', 'transkrip', 'suket', 'sertifikat', 'kk'];
            foreach ($docKeys as $key) {
                if ($request->hasFile($key)) {
                    $uploaded = $request->file($key);
                    $savedPath = $uploaded->storeAs('foto_asesi', $noPendaftaran . '_' . $key . '.' . $uploaded->getClientOriginalExtension(), 'public');
                    $data[$key] = basename($savedPath);
                    if ($key === 'sertifikat_amdal') $data['sertifikat'] = $data[$key];
                    if ($key === 'bukti_keterlibatan') $data['suket'] = $data[$key];
                    if ($key === 'sertifikat_kompetensi_lain') $data['transkrip'] = $data[$key];
                }
            }

            if (false && $request->hasFile('foto')) {
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

            // File Sertifikat Kompetensi (suket) → foto_asesi/, nama file ke asesi.suket
            $fileNameSuket = null;
            if ($request->hasFile('suket')) {
                $suket = $request->file('suket');
                $suketPath = $suket->storeAs('foto_asesi', $noPendaftaran . '_suket.' . $suket->getClientOriginalExtension(), 'public');
                $fileNameSuket = basename($suketPath);
                $data['suket'] = $fileNameSuket;
            }

            $asesi = Asesi::create($data);

            // Catat Sertifikat Kompetensi ke asesi_doc (opsional multi-dokumen)
            if ($request->filled('no_sertifikat') || $request->filled('tgl_sertifikat') || $request->hasFile('suket')) {
                DB::table('asesi_doc')->insert([
                    'id_asesi'  => $noPendaftaran,
                    'nama_doc'  => 'Sertifikat Kompetensi',
                    'nomor_doc' => $request->input('no_sertifikat'),
                    'tgl_doc'   => $request->input('tgl_sertifikat'),
                    'file'      => $fileNameSuket,
                    'status'    => 'P',
                ]);
            }

            // Buat akun login peserta di tabel users jika belum ada
            $defaultPassword = 'Kbl12345';

            $user = \App\Models\User::where('username', $noPendaftaran)
                ->orWhere('no_ktp', $asesi->no_ktp)
                ->first();

            $userData = [
                'username'            => $noPendaftaran,
                'nama_lengkap'        => $asesi->nama,
                'no_ktp'              => $asesi->no_ktp,
                'no_induk'            => $noPendaftaran,
                'tmp_lahir'           => $asesi->tmp_lahir,
                'tgl_lahir'           => $asesi->tgl_lahir,
                'pendidikan_terakhir' => $asesi->pendidikan,
                'email'               => $asesi->email ?: ($noPendaftaran . '@peserta.lsplhl.id'),
                'alamat'              => $asesi->alamat,
                'RT'                  => $asesi->RT,
                'RW'                  => $asesi->RW,
                'kelurahan'           => $asesi->kelurahan,
                'kecamatan'           => $asesi->kecamatan,
                'kota'                => $asesi->kota,
                'propinsi'            => $asesi->propinsi,
                'no_telp'             => $asesi->nohp,
                'foto'                => $data['foto'] ?? null,
                'level'               => 'user',
                'blokir'              => 'N',
                'id_session'          => md5($noPendaftaran),
                'waktu'               => now(),
            ];

            if (!$user) {
                $userData['password'] = \Illuminate\Support\Facades\Hash::make($defaultPassword);
                $user = \App\Models\User::create($userData);
            } else {
                $user->update($userData);
            }

            DB::commit();

            // Kirim email kredensial akun login ke Peserta
            try {
                if (!empty($asesi->email)) {
                    $frontendUrl = rtrim(config('app.frontend_url') ?? env('FRONTEND_URL') ?: 'https://lsk-lhl.com', '/');
                    $loginUrl = $frontendUrl . '/login/peserta';

                    Mail::html("
                        <div style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px; background-color: #ffffff;'>
                            <div style='background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%); color: white; padding: 25px; border-radius: 8px 8px 0 0; text-align: center;'>
                                <h2 style='margin: 0; font-size: 22px; font-weight: 700;'>Pendaftaran Akun Peserta Berhasil</h2>
                                <p style='margin: 8px 0 0 0; opacity: 0.9; font-size: 13px;'>LSK Lingkungan Hidup Lestari</p>
                            </div>
                            <div style='padding: 25px;'>
                                <p>Halo <strong>{$asesi->nama}</strong>,</p>
                                <p>Selamat! Pendaftaran akun peserta Anda telah berhasil diproses oleh Administrator. Berikut adalah rincian data akun dan kata sandi Anda untuk masuk ke sistem:</p>

                                <div style='background-color: #f8fafc; border-left: 4px solid #0d9488; padding: 16px; margin: 20px 0; border-radius: 6px;'>
                                    <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                                        <tr>
                                            <td style='padding: 6px 0; color: #64748b; width: 40%;'>Nomor Pendaftaran</td>
                                            <td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>: {$asesi->no_pendaftaran}</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 6px 0; color: #64748b;'>Nomor KTP (NIK)</td>
                                            <td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>: {$asesi->no_ktp}</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 6px 0; color: #64748b;'>Kata Sandi (Password)</td>
                                            <td style='padding: 6px 0; font-weight: bold; color: #b45309; font-size: 16px;'>: {$defaultPassword}</td>
                                        </tr>
                                    </table>
                                </div>
                                <p>Silakan gunakan <strong>Nomor Pendaftaran</strong> (atau Nomor KTP) dan <strong>Kata Sandi</strong> di atas untuk login ke portal peserta:</p>
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='{$loginUrl}' style='background-color: #0d9488; color: white; padding: 12px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>Masuk ke Portal Peserta</a>
                                </div>
                                <p style='color: #64748b; font-size: 12px; margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 12px;'>
                                    * Harap simpan informasi kata sandi ini dengan baik dan jangan bagikan kepada siapa pun.
                                </p>
                            </div>
                        </div>
                    ", function ($message) use ($asesi) {
                        $message->to($asesi->email, $asesi->nama)
                                ->subject('Informasi Akun & Kata Sandi Pendaftaran - LSK LHL');
                    });
                }
            } catch (\Throwable $mailEx) {
                Log::warning('Gagal mengirim email kredensial peserta ke ' . $asesi->email . ': ' . $mailEx->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Peserta berhasil didaftarkan',
                'data' => [
                    'id' => $asesi->id,
                    'no_pendaftaran' => $asesi->no_pendaftaran,
                    'nama' => $asesi->nama,
                    'no_ktp' => $asesi->no_ktp,
                    'nohp' => $asesi->nohp,
                    'tgl_daftar' => $asesi->tgl_daftar,
                    'no_sertifikat' => $asesi->no_sertifikat,
                    'tgl_sertifikat' => $asesi->tgl_sertifikat,
                ],
                'akun_login' => [
                    'username' => $noPendaftaran,
                    'no_ktp' => $asesi->no_ktp,
                    'no_hp' => $asesi->nohp,
                    'password' => $defaultPassword,
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
            'no_ktp' => 'required|string',   // NIK ganda diizinkan
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

            $docKeys = ['foto', 'ktp', 'ijazah', 'sertifikat_amdal', 'bukti_keterlibatan', 'dokumen_amdal', 'cv', 'sertifikat_kompetensi_lain', 'form_pendaftaran', 'sertifikat_atpa_ktpa', 'transkrip', 'suket', 'sertifikat', 'kk'];
            $updateData = $request->except(array_merge(['no_pendaftaran', 'password'], $docKeys));
            
            if ($request->filled('keahlian_penyusun')) {
                $updateData['keahlian_penyusun'] = MasterKeahlian::recordMultipleIfNew($request->keahlian_penyusun);
            }

            foreach ($docKeys as $key) {
                if ($request->hasFile($key)) {
                    $uploaded = $request->file($key);
                    $savedPath = $uploaded->storeAs('foto_asesi', $asesi->no_pendaftaran . '_' . $key . '.' . $uploaded->getClientOriginalExtension(), 'public');
                    $updateData[$key] = basename($savedPath);
                    if ($key === 'sertifikat_amdal') $updateData['sertifikat'] = $updateData[$key];
                    if ($key === 'bukti_keterlibatan') $updateData['suket'] = $updateData[$key];
                    if ($key === 'sertifikat_kompetensi_lain') $updateData['transkrip'] = $updateData[$key];
                }
            }

            $asesi->update($updateData);

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
        // 1. Cari asesi berdasarkan no_pendaftaran, id, atau no_ktp
        $asesi = Asesi::where('no_pendaftaran', $noPendaftaran)
            ->orWhere('id', $noPendaftaran)
            ->orWhere('no_ktp', $noPendaftaran)
            ->first();

        // 2. Jika asesi tidak ditemukan (misal sudah terhapus sebagian sebelumnya), cari juga di pendaftarans atau users
        $pendaftaran = Pendaftaran::withTrashed()
            ->where('no_pendaftaran', $noPendaftaran)
            ->orWhere('no_ktp', $noPendaftaran)
            ->orWhere('id', $noPendaftaran)
            ->first();

        $user = User::where('username', $noPendaftaran)
            ->orWhere('no_ktp', $noPendaftaran)
            ->orWhere('no_induk', $noPendaftaran)
            ->first();

        if (!$asesi && !$pendaftaran && !$user) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Kumpulkan seluruh identifier
            $regNumbers = array_filter(array_unique([
                $noPendaftaran,
                $asesi?->no_pendaftaran,
                $pendaftaran?->no_pendaftaran,
                $user?->no_induk,
            ]));

            $niks = array_filter(array_unique([
                $asesi?->no_ktp,
                $pendaftaran?->no_ktp,
                $user?->no_ktp,
                (is_numeric($noPendaftaran) && strlen((string)$noPendaftaran) === 16) ? $noPendaftaran : null,
            ]));

            $emails = array_filter(array_unique([
                $asesi?->email,
                $pendaftaran?->email,
                $user?->email,
            ]));

            $telepons = array_filter(array_unique([
                $asesi?->nohp,
                $pendaftaran?->no_hp,
                $user?->no_telp,
            ]));

            $allIdentifiers = array_filter(array_unique(array_merge($regNumbers, $niks)));

            // 1. Kumpulkan file fisik untuk dihapus
            $filesToDelete = [];
            if ($asesi) {
                $kolomFiles = ['foto', 'ktp', 'kk', 'ijazah', 'transkrip', 'suket', 'cv', 'sertifikat', 'dokumen_amdal', 'bukti_keterlibatan', 'sertifikat_amdal', 'form_pendaftaran', 'sertifikat_atpa_ktpa', 'sertifikat_kompetensi_lain'];
                foreach ($kolomFiles as $col) {
                    if (!empty($asesi->{$col})) {
                        $filesToDelete[] = 'foto_asesi/' . $asesi->{$col};
                    }
                }
            }

            // File dari asesi_doc
            $dokumen = AsesiDoc::whereIn('id_asesi', $allIdentifiers)->get();
            foreach ($dokumen as $doc) {
                if ($doc->file) {
                    $filesToDelete[] = 'foto_asesi/' . $doc->file;
                }
            }
            AsesiDoc::whereIn('id_asesi', $allIdentifiers)->delete();

            // File dari asesi_pembayaran
            $pembayaran = AsesiPembayaran::whereIn('id_asesi', $allIdentifiers)->get();
            foreach ($pembayaran as $p) {
                if ($p->bukti_bayar) {
                    $filesToDelete[] = 'foto_buktibayar/' . $p->bukti_bayar;
                }
            }
            AsesiPembayaran::whereIn('id_asesi', $allIdentifiers)->delete();

            // File dari asesi_apl02doc
            if (Schema::hasTable('asesi_apl02doc')) {
                $apl02Docs = DB::table('asesi_apl02doc')->whereIn('id_asesi', $allIdentifiers)->get();
                foreach ($apl02Docs as $doc) {
                    if (!empty($doc->file)) {
                        $filesToDelete[] = 'foto_apl02/' . $doc->file;
                    }
                }
                DB::table('asesi_apl02doc')->whereIn('id_asesi', $allIdentifiers)->delete();
            }

            // 2. Cascade delete di tabel turunan
            $tabelTurunan = [
                'asesi_asesmen',
                'asesi_apl02',
                'asesmen_ak01',
                'asesmen_ak03',
                'asesmen_ia03',
                'asesmen_ia05',
                'asesmen_ia06',
                'asesmen_ia08',
                'asesmen_ia08asesor',
                'asesmen_ia09',
                'asesmen_ia11',
            ];
            foreach ($tabelTurunan as $tbl) {
                if (Schema::hasTable($tbl)) {
                    DB::table($tbl)->whereIn('id_asesi', $allIdentifiers)->delete();
                }
            }

            // 3. Hapus akun login di tabel users (termasuk token dan modul)
            $usersToDelete = User::where(function ($q) use ($allIdentifiers, $emails, $telepons) {
                $q->whereIn('username', $allIdentifiers)
                  ->orWhereIn('no_ktp', $allIdentifiers);
                if (!empty($emails)) {
                    $q->orWhereIn('email', $emails);
                }
                if (!empty($telepons)) {
                    $q->orWhereIn('no_telp', $telepons);
                }
            })->get();

            foreach ($usersToDelete as $u) {
                if (Schema::hasTable('personal_access_tokens')) {
                    DB::table('personal_access_tokens')->where('tokenable_id', $u->username)->delete();
                }
                if (Schema::hasTable('users_modul')) {
                    DB::table('users_modul')->where('id_session', $u->id_session ?? md5($u->username))->delete();
                }
                if (!empty($u->foto)) {
                    $filesToDelete[] = 'foto_user/' . $u->foto;
                }
                $u->delete();
            }

            // 4. HAPUS BERSIH PERMANEN (forceDelete) di tabel pendaftarans
            Pendaftaran::withTrashed()->where(function ($q) use ($regNumbers, $niks, $emails, $telepons) {
                if (!empty($regNumbers)) {
                    $q->orWhereIn('no_pendaftaran', $regNumbers);
                }
                if (!empty($niks)) {
                    $q->orWhereIn('no_ktp', $niks);
                }
                if (!empty($emails)) {
                    $q->orWhereIn('email', $emails);
                }
                if (!empty($telepons)) {
                    $q->orWhereIn('no_hp', $telepons);
                }
            })->forceDelete();

            // 5. Hapus record asesi
            if ($asesi) {
                $asesi->delete();
            }
            Asesi::whereIn('no_pendaftaran', $allIdentifiers)
                ->orWhereIn('no_ktp', $allIdentifiers)
                ->delete();

            // 6. Hapus file fisik dari disk
            foreach (array_unique($filesToDelete) as $file) {
                if (Storage::disk('public')->exists($file)) {
                    Storage::disk('public')->delete($file);
                }
                $absPublic = public_path($file);
                if (file_exists($absPublic)) {
                    @unlink($absPublic);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Peserta beserta seluruh data terkait berhasil dihapus bersih',
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
            $newVerifikasi = $request->verifikasi; // 'V' or 'P' or 'D'

            if ($newVerifikasi === 'V') {
                // Pastikan pembayaran sudah divalidasi oleh Admin (lunas)
                $isLunas = \Illuminate\Support\Facades\DB::table('asesi_pembayaran')
                    ->where(function ($q) use ($asesi) {
                        $q->where('id_asesi', $asesi->no_pendaftaran)
                          ->orWhere('id_asesi', (string) $asesi->id);
                        if (!empty($asesi->no_ktp)) {
                            $q->orWhere('id_asesi', $asesi->no_ktp);
                        }
                        if (!empty($asesi->id_asesi)) {
                            $q->orWhere('id_asesi', $asesi->id_asesi);
                        }
                    })
                    ->where('status', 'V')
                    ->exists();

                if (!$isLunas) {
                    $isLunas = \Illuminate\Support\Facades\DB::table('asesi_asesmen')
                        ->where(function ($q) use ($asesi) {
                            $q->where('id_asesi', $asesi->no_pendaftaran)
                              ->orWhere('id_asesi', (string) $asesi->id);
                            if (!empty($asesi->no_ktp)) {
                                $q->orWhere('id_asesi', $asesi->no_ktp);
                            }
                            if (!empty($asesi->id_asesi)) {
                                $q->orWhere('id_asesi', $asesi->id_asesi);
                            }
                        })
                        ->where('biaya_asesmen', 'L')
                        ->exists();
                }

                if (!$isLunas) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Permohonan sertifikasi tidak dapat disetujui karena status pembayaran belum divalidasi oleh Admin.',
                    ], 422);
                }

                // Pastikan 4 dokumen pokok sudah terverifikasi
                $verif = is_array($asesi->verifikasi_dokumen)
                    ? $asesi->verifikasi_dokumen
                    : (is_string($asesi->verifikasi_dokumen) ? (json_decode($asesi->verifikasi_dokumen, true) ?: []) : []);
                $wajibShortcodes = ['ijazah', 'sertifikat_amdal', 'bukti_keterlibatan', 'dokumen_amdal'];
                $allVerified = true;
                foreach ($wajibShortcodes as $sc) {
                    if (($verif[$sc] ?? '') !== 'terverifikasi') {
                        $allVerified = false;
                        break;
                    }
                }
                if (!$allVerified) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Semua 4 dokumen persyaratan pokok harus terverifikasi terlebih dahulu sebelum disetujui.',
                    ], 422);
                }
            }

            if ($newVerifikasi === 'V') {
                if ($request->filled('signature') || $request->filled('tanda_tangan')) {
                    $signature = $request->input('signature') ?: $request->input('tanda_tangan');
                    $verif['ttd_admin'] = $signature;
                    $verif['tgl_persetujuan_admin'] = now()->toISOString();
                    $verif['nama_admin'] = auth()->user()?->nama_lengkap ?? 'Administrator Sistem';
                    $asesi->verifikasi_dokumen = $verif;
                }
            }

            $asesi->verifikasi = $newVerifikasi;
            $asesi->save();

            // ── Sync asesi_asesmen.status ──────────────────────────────────────
            // Ketika admin menyetujui (verifikasi='V') → status asesmen = 'A' (Approved)
            // Ketika ditolak/dikembalikan → status asesmen kembali ke 'P' (Pending)
            // Ini yang dibaca oleh portal peserta (AsesmenSayaController state machine)
            $statusAsesmen = $newVerifikasi === 'V' ? 'A' : 'P';
            \Illuminate\Support\Facades\DB::table('asesi_asesmen')
                ->where(function ($q) use ($asesi) {
                    $q->where('id_asesi', $asesi->no_pendaftaran)
                      ->orWhere('id_asesi', (string) $asesi->id);
                    if (!empty($asesi->no_ktp)) {
                        $q->orWhere('id_asesi', $asesi->no_ktp);
                    }
                })
                ->update(['status' => $statusAsesmen]);

            return response()->json([
                'success' => true,
                'message' => 'Status verifikasi berhasil diperbarui',
                'data' => [
                    'verifikasi' => $asesi->verifikasi,
                    'status_asesmen_sync' => $statusAsesmen,
                ],
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
    /**
     * Update status verifikasi dokumen persyaratan pokok
     * PUT /api/v1/admin/peserta/{noPendaftaran}/verifikasi-dokumen
     */
    public function updateVerifikasiDokumen(Request $request, $noPendaftaran)
    {
        $asesi = Asesi::where('no_pendaftaran', $noPendaftaran)
            ->orWhere('id', $noPendaftaran)
            ->first();

        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        $shortcode = $request->input('shortcode');
        $status = $request->input('status'); // 'terverifikasi', 'ditolak', 'terupload'

        if (!$shortcode || !$status) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter shortcode dan status diperlukan',
            ], 422);
        }

        $verif = is_array($asesi->verifikasi_dokumen)
            ? $asesi->verifikasi_dokumen
            : (is_string($asesi->verifikasi_dokumen) ? (json_decode($asesi->verifikasi_dokumen, true) ?: []) : []);

        $verif[$shortcode] = $status;
        $asesi->verifikasi_dokumen = $verif;

        // Jika dokumen ditolak, otomatis file yang dikirim dihapus dari database & storage
        if ($status === 'ditolak') {
            $deletedFile = null;
            if ($shortcode === 'ijazah') {
                $deletedFile = $asesi->ijazah;
                $asesi->ijazah = null;
            } elseif ($shortcode === 'sertifikat_amdal' || $shortcode === 'sertifikat') {
                $deletedFile = $asesi->sertifikat_amdal ?: $asesi->sertifikat;
                $asesi->sertifikat_amdal = null;
                $asesi->sertifikat = null;
            } elseif ($shortcode === 'bukti_keterlibatan' || $shortcode === 'suket') {
                $deletedFile = $asesi->bukti_keterlibatan ?: $asesi->suket;
                $asesi->bukti_keterlibatan = null;
                $asesi->suket = null;
            } elseif ($shortcode === 'dokumen_amdal') {
                $deletedFile = $asesi->dokumen_amdal;
                $asesi->dokumen_amdal = null;
            } elseif ($shortcode === 'sertifikat_kompetensi_lain' || $shortcode === 'transkrip') {
                $deletedFile = $asesi->sertifikat_kompetensi_lain ?: $asesi->transkrip;
                $asesi->sertifikat_kompetensi_lain = null;
                $asesi->transkrip = null;
            } elseif (isset($asesi->{$shortcode})) {
                $deletedFile = $asesi->{$shortcode};
                $asesi->{$shortcode} = null;
            }

            if ($deletedFile && !str_starts_with($deletedFile, '/private/var')) {
                $paths = [
                    public_path('foto_asesi/' . $deletedFile),
                    storage_path('app/public/foto_asesi/' . $deletedFile),
                    storage_path('app/' . $deletedFile),
                ];
                foreach ($paths as $p) {
                    if (file_exists($p) && is_file($p)) {
                        @unlink($p);
                    }
                }
            }

            // Pastikan verifikasi profil = P karena ada dokumen ditolak
            $asesi->verifikasi = 'P';
        }

        // Apakah SEMUA 4 dokumen persyaratan pokok sudah terverifikasi?
        $wajibShortcodes = ['ijazah', 'sertifikat_amdal', 'bukti_keterlibatan', 'dokumen_amdal'];
        $allVerified = true;
        foreach ($wajibShortcodes as $sc) {
            $alias = match ($sc) {
                'sertifikat_amdal' => 'sertifikat',
                'bukti_keterlibatan' => 'suket',
                'dokumen_amdal' => 'salinan_dokumen',
                default => null,
            };
            $scVerif = ($verif[$sc] ?? '') === 'terverifikasi' || ($alias && ($verif[$alias] ?? '') === 'terverifikasi');
            if (!$scVerif) {
                $allVerified = false;
                break;
            }
        }

        // Jika SEMUA 4 syarat pokok sudah terverifikasi, otomatis verifikasi = 'V'
        // Jika belum semua atau ada dokumen ditolak, kembalikan ke 'P'
        if ($allVerified) {
            $asesi->verifikasi = 'V';
        } else {
            $asesi->verifikasi = 'P';
        }

        $asesi->save();

        // ── Notifikasi revisi ke peserta saat dokumen DITOLAK ──
        // (lonceng notifikasi portal peserta — idem pola verifikasi sertifikat)
        if ($status === 'ditolak') {
            $docNames = [
                'ijazah' => 'Scan Ijazah (Minimal S1/D4)',
                'sertifikat_amdal' => 'Sertifikat Pelatihan AMDAL',
                'sertifikat' => 'Sertifikat Pelatihan AMDAL',
                'bukti_keterlibatan' => 'Bukti Keterlibatan AMDAL',
                'suket' => 'Bukti Keterlibatan AMDAL',
                'dokumen_amdal' => 'Salinan Dokumen AMDAL',
                'cv' => 'Curriculum Vitae (CV)',
                'foto' => 'Pas Foto (3x4)',
                'ktp' => 'Scan KTP',
                'sertifikat_kompetensi_lain' => 'Sertifikat Pelatihan Relevan',
                'transkrip' => 'Sertifikat Pelatihan Relevan',
            ];
            $namaDok = $docNames[$shortcode] ?? $shortcode;
            if (Schema::hasTable('asesi_notifikasi')) {
                DB::table('asesi_notifikasi')->insert([
                    'id_asesi' => $asesi->no_pendaftaran,
                    'tipe' => 'warning',
                    'judul' => 'Dokumen Perlu Upload Ulang',
                    'pesan' => "Dokumen \"{$namaDok}\" yang Anda unggah dinyatakan belum sesuai atau ditolak oleh Verifikator LSK. Silakan segera unggah kembali berkas perbaikan melalui menu Profil.",
                    'kategori' => 'dokumen',
                    'dibaca' => 0,
                    'waktu' => now(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Status verifikasi dokumen berhasil diperbarui',
            'data' => [
                'shortcode' => $shortcode,
                'status' => $status,
                'verifikasi_dokumen' => $verif,
                'all_verified' => $allVerified,
                'verifikasi' => $asesi->verifikasi,
            ],
        ]);
    }

    /**
     * Validasi status pembayaran peserta (admin)
     * Mengubah status asesi_pembayaran (P -> V) dan asesi_asesmen.biaya_asesmen (K -> L)
     */
    public function updateValidasiPembayaran(Request $request, $noPendaftaran)
    {
        $asesi = Asesi::where('no_pendaftaran', $noPendaftaran)
            ->orWhere('id', $noPendaftaran)
            ->first();

        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        $status = $request->input('status', 'V'); // 'V' untuk lunas, 'P' untuk pending/batal, 'D' untuk ditolak
        $idPembayaran = $request->input('id_pembayaran');
        $catatan = $request->input('catatan');

        \DB::beginTransaction();
        try {
            $pembayaranQuery = \DB::table('asesi_pembayaran')
                ->where(function ($q) use ($asesi) {
                    $q->where('id_asesi', $asesi->no_pendaftaran)
                      ->orWhere('id_asesi', (string) $asesi->id);
                });

            if ($idPembayaran) {
                $pembayaranQuery->where('id', $idPembayaran);
            } else {
                $pembayaranQuery->orderBy('id', 'desc');
            }

            $pembayaran = $pembayaranQuery->first();
            if ($pembayaran) {
                $updateData = ['status' => $status];
                if ($status === 'D') {
                    $updateData['catatan_penolakan'] = $catatan ?: 'Bukti pembayaran tidak sesuai atau tidak valid.';
                } elseif ($status === 'V' || $status === 'P') {
                    $updateData['catatan_penolakan'] = null;
                }
                \DB::table('asesi_pembayaran')
                    ->where('id', $pembayaran->id)
                    ->update($updateData);
            }

            // Update asesi_asesmen: 'L' jika 'V', atau 'K' jika 'P', atau 'P' jika 'D' (ditolak)
            $newBiayaAsesmen = $status === 'V' ? 'L' : ($status === 'D' ? 'P' : ($pembayaran ? 'K' : 'P'));
            \DB::table('asesi_asesmen')
                ->where(function ($q) use ($asesi) {
                    $q->where('id_asesi', $asesi->no_pendaftaran)
                      ->orWhere('id_asesi', (string) $asesi->id);
                })
                ->update(['biaya_asesmen' => $newBiayaAsesmen]);

            \DB::commit();

            $message = match ($status) {
                'V' => 'Pembayaran berhasil divalidasi (Lunas).',
                'D' => 'Pembayaran berhasil ditolak: ' . ($catatan ?: 'Bukti transfer tidak sesuai.'),
                default => 'Validasi pembayaran dibatalkan (Menunggu Validasi).',
            };

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'status' => $status,
                    'biaya_asesmen' => $newBiayaAsesmen,
                    'pembayaran_id' => $pembayaran ? $pembayaran->id : null,
                    'catatan_penolakan' => $status === 'D' ? ($catatan ?: 'Bukti transfer tidak sesuai.') : null,
                ]
            ]);
        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal validasi pembayaran: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Simpan Tanda Tangan Kwitansi Pembayaran (Admin)
     * PUT /api/v1/admin/peserta/{noPendaftaran}/kwitansi-signature
     */
    public function updateKwitansiSignature(Request $request, $noPendaftaran)
    {
        $asesi = Asesi::where('no_pendaftaran', $noPendaftaran)
            ->orWhere('id', $noPendaftaran)
            ->first();

        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        $signature = $request->input('signature') ?: $request->input('tanda_tangan');
        if (!$signature) {
            return response()->json([
                'success' => false,
                'message' => 'Tanda tangan wajib diisi',
            ], 422);
        }

        $namaAdmin = $request->input('nama_admin') ?: (auth()->user()?->nama_lengkap ?? 'Administrator LSK');

        $verif = is_array($asesi->verifikasi_dokumen)
            ? $asesi->verifikasi_dokumen
            : (is_string($asesi->verifikasi_dokumen) ? (json_decode($asesi->verifikasi_dokumen, true) ?: []) : []);

        $verif['ttd_kwitansi'] = $signature;
        if (empty($verif['ttd_admin'])) {
            $verif['ttd_admin'] = $signature;
        }
        $verif['tgl_ttd_kwitansi'] = now()->toISOString();
        if (empty($verif['nama_admin'])) {
            $verif['nama_admin'] = $namaAdmin;
        }

        $asesi->verifikasi_dokumen = $verif;
        $asesi->save();

        return response()->json([
            'success' => true,
            'message' => 'Tanda tangan kwitansi berhasil disimpan',
            'data' => [
                'ttd_kwitansi' => $signature,
                'nama_admin' => $namaAdmin,
                'tgl_ttd_kwitansi' => $verif['tgl_ttd_kwitansi'],
            ],
        ]);
    }

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
            ->select(
                DB::raw('COALESCE(w.id_wil, a.propinsi) as id_wil'),
                DB::raw('COALESCE(w.nm_wil, a.propinsi, "(Tanpa Provinsi)") as propinsi'),
                DB::raw('COUNT(*) as total')
            )
            ->leftJoin('data_wilayah as w', 'a.propinsi', '=', 'w.id_wil')
            ->whereNotNull('a.propinsi')
            ->where('a.propinsi', '!=', '')
            ->groupBy('w.id_wil', 'w.nm_wil', 'a.propinsi')
            ->orderBy('total', 'desc')
            ->get();

        // By kota
        $byKota = DB::table('asesi as a')
            ->select(
                DB::raw('COALESCE(w.id_wil, a.kota) as id_wil'),
                DB::raw('COALESCE(w.nm_wil, a.kota, "(Tanpa Kota)") as kota'),
                DB::raw('COUNT(*) as total')
            )
            ->leftJoin('data_wilayah as w', 'a.kota', '=', 'w.id_wil')
            ->whereNotNull('a.kota')
            ->where('a.kota', '!=', '')
            ->groupBy('w.id_wil', 'w.nm_wil', 'a.kota')
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
                'by_kota' => $byKota,
            ],
        ]);
    }

    /**
     * Transform asesi data
     */
    private function transformAsesi($asesi)
    {
        $verifDok = is_array($asesi->verifikasi_dokumen)
            ? $asesi->verifikasi_dokumen
            : (is_string($asesi->verifikasi_dokumen) ? (json_decode($asesi->verifikasi_dokumen, true) ?: []) : []);

        $wajibItems = [
            'ijazah' => [
                'label' => 'Scan Ijazah (Minimal S1/D4)',
                'file' => $asesi->ijazah,
            ],
            'sertifikat_amdal' => [
                'label' => 'Sertifikat Pelatihan AMDAL',
                'file' => $asesi->sertifikat_amdal ?: $asesi->sertifikat,
            ],
            'bukti_keterlibatan' => [
                'label' => 'Bukti Keterlibatan AMDAL',
                'file' => $asesi->bukti_keterlibatan ?: $asesi->suket,
            ],
            'dokumen_amdal' => [
                'label' => 'Salinan Dokumen AMDAL',
                'file' => $asesi->dokumen_amdal,
            ],
        ];

        $dokPersyaratan = [];
        foreach ($wajibItems as $key => $item) {
            $file = $item['file'];
            $savedStatus = $verifDok[$key] ?? null;
            $status = 'belum_ada';
            if ($savedStatus === 'ditolak') {
                $status = 'ditolak';
            } elseif (!empty($file)) {
                if ($savedStatus === 'terverifikasi' || $asesi->verifikasi === 'V') {
                    $status = 'terverifikasi';
                } else {
                    $status = 'terupload';
                }
            }
            $dokPersyaratan[$key] = [
                'status' => $status,
                'file' => $file,
                'url' => $file ? asset('storage/foto_asesi/' . $file) : null,
                'label' => $item['label'],
            ];
        }

        $tambahanItems = [
            'cv' => [
                'label' => 'Curriculum Vitae (CV)',
                'file' => $asesi->cv,
            ],
            'foto' => [
                'label' => 'Pas Foto (3x4)',
                'file' => $asesi->foto,
            ],
            'ktp' => [
                'label' => 'Scan KTP',
                'file' => $asesi->ktp,
            ],
            'sertifikat_kompetensi_lain' => [
                'label' => 'Sertifikat Pelatihan Relevan',
                'file' => $asesi->sertifikat_kompetensi_lain ?: $asesi->transkrip,
            ],
            'form_pendaftaran' => [
                'label' => 'Formulir Pendaftaran',
                'file' => $asesi->form_pendaftaran,
            ],
            'sertifikat_atpa_ktpa' => [
                'label' => 'Sertifikat ATPA/KTPA Sebelumnya',
                'file' => $asesi->sertifikat_atpa_ktpa,
            ],
        ];

        $dokTambahan = [];
        foreach ($tambahanItems as $key => $item) {
            $file = $item['file'];
            $savedStatus = $verifDok[$key] ?? null;
            $status = 'belum_ada';
            if ($savedStatus === 'ditolak') {
                $status = 'ditolak';
            } elseif (!empty($file)) {
                if ($savedStatus === 'terverifikasi' || $asesi->verifikasi === 'V') {
                    $status = 'terverifikasi';
                } else {
                    $status = 'terupload';
                }
            }
            $dokItem = [
                'status' => $status,
                'file' => $file,
                'url' => $file ? asset('storage/foto_asesi/' . $file) : null,
                'label' => $item['label'],
            ];
            $dokTambahan[$key] = $dokItem;
            $dokPersyaratan[$key] = $dokItem;
        }

        // Fallback data pekerjaan sekarang dari pendaftarans jika belum terisi di asesi
        $namaKantor = $asesi->nama_kantor;
        $jabatan = $asesi->jabatan;
        $alamatKantor = $asesi->alamat_kantor;
        $telpKantor = $asesi->telp_kantor;
        $faxKantor = $asesi->fax_kantor;
        $emailKantor = $asesi->email_kantor;
        $pekerjaan = $asesi->pekerjaan;

        if (empty($namaKantor) || empty($jabatan) || empty($alamatKantor)) {
            $pendaftaran = \App\Models\Pendaftaran::where('no_ktp', $asesi->no_ktp)
                ->orWhere('email', $asesi->email)
                ->orWhere('no_pendaftaran', $asesi->no_pendaftaran)
                ->orWhere('no_hp', $asesi->nohp)
                ->latest()
                ->first();

            if ($pendaftaran) {
                if (empty($namaKantor) && !empty($pendaftaran->nama_institusi)) $namaKantor = $pendaftaran->nama_institusi;
                if (empty($jabatan) && !empty($pendaftaran->jabatan)) $jabatan = $pendaftaran->jabatan;
                if (empty($alamatKantor) && !empty($pendaftaran->alamat_kantor)) $alamatKantor = $pendaftaran->alamat_kantor;
                if (empty($telpKantor) && !empty($pendaftaran->no_telp_kantor)) $telpKantor = $pendaftaran->no_telp_kantor;
                if (empty($faxKantor) && !empty($pendaftaran->no_fax_kantor)) $faxKantor = $pendaftaran->no_fax_kantor;
                if (empty($emailKantor) && !empty($pendaftaran->email_kantor)) $emailKantor = $pendaftaran->email_kantor;
                if (empty($pekerjaan) && !empty($pendaftaran->bidang_keahlian)) $pekerjaan = $pendaftaran->bidang_keahlian;

                $updateFields = [];
                if (empty($asesi->nama_kantor) && !empty($namaKantor)) $updateFields['nama_kantor'] = $namaKantor;
                if (empty($asesi->jabatan) && !empty($jabatan)) $updateFields['jabatan'] = $jabatan;
                if (empty($asesi->alamat_kantor) && !empty($alamatKantor)) $updateFields['alamat_kantor'] = $alamatKantor;
                if (empty($asesi->telp_kantor) && !empty($telpKantor)) $updateFields['telp_kantor'] = $telpKantor;
                if (empty($asesi->fax_kantor) && !empty($faxKantor)) $updateFields['fax_kantor'] = $faxKantor;
                if (empty($asesi->email_kantor) && !empty($emailKantor)) $updateFields['email_kantor'] = $emailKantor;
                if (empty($asesi->pekerjaan) && !empty($pekerjaan)) $updateFields['pekerjaan'] = $pekerjaan;

                if (!empty($updateFields)) {
                    \DB::table('asesi')->where('id', $asesi->id)->update($updateFields);
                    foreach ($updateFields as $fld => $val) {
                        $asesi->{$fld} = $val;
                    }
                }
            }
        }

        // Ambil tanda tangan digital pemohon dari logdigisign
        $ttdLog = \DB::table('logdigisign')
            ->where(function ($q) use ($asesi) {
                $q->where('file', 'like', 'ttd_' . $asesi->no_pendaftaran . '_%')
                  ->orWhere('penandatangan', $asesi->nama)
                  ->orWhere('url_ditandatangani', 'like', '%' . $asesi->no_pendaftaran . '%');
            })
            ->orderBy('id', 'desc')
            ->first();

        $ttdPemohonUrl = null;
        $ttdPemohonWaktu = null;
        if ($ttdLog) {
            $ttdPemohonWaktu = $ttdLog->waktu;
            if (!empty($ttdLog->file)) {
                $fileName = basename($ttdLog->file);
                $ttdPemohonUrl = asset('storage/foto_tandatangan/' . $fileName);
            } elseif (!empty($ttdLog->url_ditandatangani)) {
                $ttdPemohonUrl = $ttdLog->url_ditandatangani;
            }
        }

        return [
            'id' => $asesi->id,
            'no_pendaftaran' => $asesi->no_pendaftaran,
            'ttd_pemohon' => $ttdPemohonUrl,
            'ttd_pemohon_waktu' => $ttdPemohonWaktu,
            'nama' => $asesi->nama,
            'tmp_lahir' => $asesi->tmp_lahir,
            'tgl_lahir' => $asesi->tgl_lahir ? $asesi->tgl_lahir->format('Y-m-d') : null,
            'usia' => $asesi->usia ?: ($asesi->age_from_dob ?: ($asesi->tgl_lahir ? $asesi->tgl_lahir->age : null)),
            'jenis_kelamin' => $asesi->jenis_kelamin,
            'no_ktp' => $asesi->no_ktp,
            'nohp' => $asesi->nohp,
            'whatsapp' => $asesi->whatsapp,
            'email' => $asesi->email,
            'pendidikan' => $asesi->pendidikan,
            'keahlian_penyusun' => $asesi->keahlian_penyusun,
            'lembaga_pendidikan' => $asesi->lembaga_pendidikan,
            'agama' => $asesi->agama,
            'prodi' => $asesi->prodi,
            'tahun_lulus' => $asesi->tahun_lulus,
            'kebangsaan' => $asesi->kebangsaan ?: 'Indonesia',
            'pekerjaan' => $pekerjaan,
            'jabatan' => $jabatan,
            'nama_kantor' => $namaKantor,
            'alamat_kantor' => $alamatKantor,
            'telp_kantor' => $telpKantor,
            'fax_kantor' => $faxKantor,
            'email_kantor' => $emailKantor,
            'no_sertifikat' => $asesi->no_sertifikat,
            'tgl_sertifikat' => $asesi->tgl_sertifikat ? $asesi->tgl_sertifikat->format('Y-m-d') : null,
            'tgl_daftar' => $asesi->tgl_daftar ? $asesi->tgl_daftar->format('Y-m-d') : null,
            'angkatan' => $asesi->angkatan,
            'propinsi' => $asesi->propinsi,
            'propinsi_nama' => $asesi->propinsiWilayah->nm_wil ?? (is_string($asesi->propinsi) ? $asesi->propinsi : ''),
            'kota' => $asesi->kota,
            'kota_nama' => $asesi->kotaWilayah->nm_wil ?? (is_string($asesi->kota) ? $asesi->kota : ''),
            'verifikasi' => $asesi->verifikasi,
            'blokir' => $asesi->blokir,
            'status' => $asesi->status_label,
            'dokumen_pokok' => $asesi->dokumen_pokok,
            'dokumen_lengkap' => $asesi->dokumen_lengkap,
            'dokumen_persyaratan' => $dokPersyaratan,
            'dokumen_tambahan' => $dokTambahan,
            'verifikasi_dokumen' => $verifDok,
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
        $skemaQuery = AsesiAsesmen::where(function ($q) use ($asesi) {
            $q->where('id_asesi', $asesi->no_pendaftaran)
              ->orWhere('id_asesi', (string) $asesi->id);
        });

        if ($tab === 'kompeten') {
            $skemaQuery->where('status_asesmen', 'K');
        } elseif ($tab === 'belum_kompeten') {
            $skemaQuery->where('status_asesmen', 'BK');
        }

        // Hitung dokumen pokok & tambahan dari tabel asesi
        $dokumenWajib = ['ijazah', 'sertifikat_amdal', 'bukti_keterlibatan', 'dokumen_amdal'];
        $countWajib = 0;
        $countWajibVerif = 0;
        $verifDok = is_array($asesi->verifikasi_dokumen)
            ? $asesi->verifikasi_dokumen
            : (is_string($asesi->verifikasi_dokumen) ? (json_decode($asesi->verifikasi_dokumen, true) ?: []) : []);

        foreach ($dokumenWajib as $f) {
            if (!empty($asesi->{$f})) {
                $countWajib++;
                if (($verifDok[$f] ?? null) === 'terverifikasi' || $asesi->verifikasi === 'V') {
                    $countWajibVerif++;
                }
            }
        }

        $dokumenTambahan = ['sertifikat_atpa_ktpa', 'sertifikat_kompetensi_lain', 'cv', 'ktp', 'foto', 'form_pendaftaran'];
        $countTambahan = 0;
        foreach ($dokumenTambahan as $f) {
            if (!empty($asesi->{$f})) $countTambahan++;
        }

        // Load skema relationship with pivot data
        $skemaList = $skemaQuery
            ->with(['skema', 'jadwal', 'asesor'])
            ->get()
            ->map(function ($asesmen) use ($asesi, $countWajib, $countTambahan, $countWajibVerif) {
                $countAsesiDoc = AsesiDoc::where(function ($q) use ($asesi) {
                    $q->where('id_asesi', $asesi->no_pendaftaran)
                      ->orWhere('id_asesi', (string) $asesi->id);
                })
                ->where('id_skemakkni', $asesmen->id_skemakkni)
                ->count();

                $totalDokumen = $countWajib + $countTambahan + $countAsesiDoc;
                $isLengkap = ($countWajib >= 4) || ($asesi->verifikasi === 'V') || ($totalDokumen >= 4);
                $isTerverifikasi = ($asesi->verifikasi === 'V') || ($countWajibVerif >= 4);

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
                    'jumlah_dokumen' => $totalDokumen,
                    'dokumen_lengkap' => $isLengkap,
                    'dokumen_terverifikasi' => $isTerverifikasi,
                    'jumlah_dokumen_terverifikasi' => $countWajibVerif,
                ];
            });

        $data['skema_list'] = $skemaList;
        $data['total_skema'] = $skemaList->count();

        // Cek pendaftaran/pembayaran remedial peserta
        $asesmenIds = \DB::table('asesi_asesmen')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->orWhere('id_asesi', (string) $asesi->id)
            ->pluck('id')
            ->toArray();

        $remedialPembayaran = \DB::table('asesi_pembayaran')
            ->where(function ($q) use ($asesi, $asesmenIds) {
                $q->where('id_asesi', $asesi->no_pendaftaran)
                  ->orWhere('id_asesi', (string) $asesi->id);
                if (!empty($asesmenIds)) {
                    $q->orWhereIn('id_asesmen', $asesmenIds);
                }
            })
            ->where(function ($q) {
                $q->where('nominal', 1500000)
                  ->orWhere('jalur_bayar', 'like', '%remedial%');
            })
            ->orderBy('id', 'desc')
            ->first();

        // Fallback jika ada 2+ pembayaran dan belum teridentifikasi
        if (!$remedialPembayaran) {
            $allPayments = \DB::table('asesi_pembayaran')
                ->where(function ($q) use ($asesi) {
                    $q->where('id_asesi', $asesi->no_pendaftaran)
                      ->orWhere('id_asesi', (string) $asesi->id);
                })
                ->orderBy('id', 'desc')
                ->get();
            if ($allPayments->count() > 1) {
                $remedialPembayaran = $allPayments->first();
            }
        }

        $statusRemedial = 'belum_daftar';
        if ($remedialPembayaran) {
            if ($remedialPembayaran->status === 'V') {
                $statusRemedial = 'lunas';
            } elseif ($remedialPembayaran->status === 'D') {
                $statusRemedial = 'ditolak';
            } else {
                $statusRemedial = 'menunggu_verifikasi';
            }
        }

        $statusRemedialLabel = match ($remedialPembayaran?->status) {
            'V' => 'Telah Divalidasi',
            'D' => 'Ditolak',
            default => 'Menunggu Validasi',
        };

        $data['remedial'] = $remedialPembayaran ? [
            'id' => $remedialPembayaran->id,
            'nominal' => (int) $remedialPembayaran->nominal,
            'nominal_formatted' => number_format((float) $remedialPembayaran->nominal, 0, ',', '.'),
            'status' => $remedialPembayaran->status,
            'status_label' => $statusRemedialLabel,
            'catatan_penolakan' => $remedialPembayaran->catatan_penolakan ?? null,
            'is_verified' => $remedialPembayaran->status === 'V',
            'is_rejected' => $remedialPembayaran->status === 'D',
            'tgl_bayar' => $remedialPembayaran->tgl_bayar,
            'file' => $remedialPembayaran->file,
            'bukti_url' => !empty($remedialPembayaran->file) ? asset('storage/foto_asesibayar/' . $remedialPembayaran->file) : null,
        ] : null;
        $data['status_remedial'] = $statusRemedial;

        // Ambil data penilaian_asesi (hasil uji instrumen VP, PT, DPSK, PW)
        $penilaianRecord = \DB::table('penilaian_asesi')
            ->where('no_pendaftaran', $asesi->no_pendaftaran)
            ->orderBy('id', 'desc')
            ->first();

        if ($penilaianRecord) {
            $rubrikDetail = is_string($penilaianRecord->rubrik_detail)
                ? json_decode($penilaianRecord->rubrik_detail, true)
                : (is_array($penilaianRecord->rubrik_detail) ? $penilaianRecord->rubrik_detail : []);

            $data['penilaian'] = [
                'id' => $penilaianRecord->id,
                'id_jadwal' => $penilaianRecord->id_jadwal,
                'nilai_vp' => (float) $penilaianRecord->nilai_vp,
                'nilai_pt' => (float) $penilaianRecord->nilai_pt,
                'nilai_dpsk' => (float) $penilaianRecord->nilai_dpsk,
                'nilai_pw' => (float) $penilaianRecord->nilai_pw,
                'skor_vp' => (float) $penilaianRecord->skor_vp,
                'skor_pt' => (float) $penilaianRecord->skor_pt,
                'skor_dpsk' => (float) $penilaianRecord->skor_dpsk,
                'skor_pw' => (float) $penilaianRecord->skor_pw,
                'total_skor' => (float) $penilaianRecord->total_skor,
                'rekomendasi' => $penilaianRecord->rekomendasi,
                'catatan' => $penilaianRecord->catatan,
                'tgl_penilaian' => $penilaianRecord->tgl_penilaian,
                'unit_pt' => $rubrikDetail['unit_pt'] ?? null,
                'unit_pw' => $rubrikDetail['unit_pw'] ?? null,
                'rubrik_detail' => $rubrikDetail,
            ];
        } else {
            $data['penilaian'] = null;
        }

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
        $skemaItems = $asesi->skema;
        if ($skemaItems->isEmpty()) {
            $pendaftarans = AsesiAsesmen::where('id_asesi', $asesi->no_pendaftaran)
                ->orWhere('id_asesi', (string) $asesi->id)
                ->with('skema')
                ->get();
            $skemaItems = $pendaftarans->map(function ($p) {
                if ($p->skema) {
                    $p->skema->pivot = (object) [
                        'status' => $p->status,
                        'status_asesmen' => $p->status_asesmen,
                        'no_lisensi' => $p->no_lisensi,
                        'no_serisertifikat' => $p->no_serisertifikat,
                        'masa_berlaku' => $p->masa_berlaku,
                        'foto_sertifikat' => $p->foto_sertifikat,
                        'biaya' => $p->biaya,
                        'tujuan_sertifikasi' => $p->tujuan_sertifikasi,
                    ];
                    return $p->skema;
                }
                return null;
            })->filter()->values();
        }

        $data['skema_diikuti'] = $skemaItems->map(function ($skema) use ($asesi) {
            $pivot = $skema->pivot;

            $persyaratan = \DB::table('skema_persyaratan')
                ->where('id_skemakkni', $skema->id)
                ->pluck('persyaratan')
                ->map(fn($p) => trim($p))
                ->filter()
                ->values()
                ->toArray();

            if (empty($persyaratan)) {
                $persyaratan = [
                    "Fotocopy Kartu Tanda Penduduk",
                    "Foto berwarna ukuran 3 x 4 sebanyak 3 buah",
                    "Fotocopy ijazah minimal D4/S1",
                    "Memiliki sertifikat kelulusan pelatihan penyusun Amdal dari LPK Amdal yang telah terakreditasi",
                    "Memiliki pengalaman dalam penyusunan Amdal",
                ];
            }

            $units = \DB::table('unit_kompetensi')
                ->where('id_skemakkni', $skema->id)
                ->orderBy('id')
                ->get()
                ->map(function ($u, $idx) {
                    return [
                        'no' => $idx + 1,
                        'id' => $u->id,
                        'kode' => $u->kode_unit,
                        'judul' => $u->judul,
                        'jenis' => $u->jenis ?: 'SKKNI',
                    ];
                })
                ->toArray();

            return [
                'id' => $skema->id,
                'judul' => $skema->judul,
                'kode_skema' => $skema->kode_skema,
                'status' => $pivot->status ?? 'P',
                'status_asesmen' => $pivot->status_asesmen ?? 'P',
                'no_sertifikat' => $pivot->no_lisensi ?? null,
                'masa_berlaku' => $pivot->masa_berlaku ?? null,
                'biaya' => (int) ($pivot->biaya ?? 0),
                'tujuan_sertifikasi' => $pivot->tujuan_sertifikasi ?? 'Sertifikasi',
                'persyaratan' => $persyaratan,
                'unit_kompetensi' => $units,
            ];
        });

        // Top level shortcuts for primary registered skema
        $primarySkema = $data['skema_diikuti']->first();
        if ($primarySkema) {
            $data['skema_nama'] = $primarySkema['judul'];
            $data['skema_kode'] = $primarySkema['kode_skema'];
            $data['skema_id'] = $primarySkema['id'];
            $data['biaya'] = $primarySkema['biaya'];
            $data['tujuan_sertifikasi'] = $primarySkema['tujuan_sertifikasi'];
            $data['persyaratan_skema'] = $primarySkema['persyaratan'];
            $data['unit_kompetensi'] = $primarySkema['unit_kompetensi'];
        }

        // Add dokumen per skema & sinkronisasi portofolio dari Syarat Tambahan
        $verifDok = $data['verifikasi_dokumen'] ?? (is_array($asesi->verifikasi_dokumen)
            ? $asesi->verifikasi_dokumen
            : (is_string($asesi->verifikasi_dokumen) ? (json_decode($asesi->verifikasi_dokumen, true) ?: []) : []));

        $dokumenSkema = AsesiDoc::where('id_asesi', $asesi->no_pendaftaran)
            ->get()
            ->map(function ($doc) use ($asesi) {
                $statusVerif = match ($doc->status) {
                    'A', 'V' => 'V',
                    'R', 'D' => 'D',
                    default => ($asesi->verifikasi === 'V' ? 'V' : 'P'),
                };
                $statusLabel = $statusVerif === 'V' ? 'Terverifikasi' : ($statusVerif === 'D' ? 'Ditolak' : 'Menunggu Persetujuan');
                return [
                    'id' => $doc->id,
                    'jenis_doc' => $doc->nama_doc ?: ($doc->jenis_doc ?: 'Sertifikat Kompetensi'),
                    'nama_doc' => $doc->nama_doc ?: ($doc->jenis_doc ?: 'Sertifikat Kompetensi'),
                    'file' => $doc->file_url ?: (!empty($doc->file) ? asset('storage/foto_asesi/' . $doc->file) : null),
                    'nomor_doc' => $doc->nomor_doc ?: (string) $doc->id,
                    'tgl_doc' => $doc->tgl_doc ? \Carbon\Carbon::parse($doc->tgl_doc)->format('d/m/Y') : null,
                    'verifikasi' => $statusVerif,
                    'verifikasi_label' => $statusLabel,
                    'catatan' => $doc->catatan,
                ];
            })
            ->toArray();

        $existingFiles = array_filter(array_map(function ($d) {
            return basename($d['file'] ?? '');
        }, $dokumenSkema));

        $sertifikatKompetensiLain = $asesi->sertifikat_kompetensi_lain ?: $asesi->transkrip;
        if (!empty($sertifikatKompetensiLain) && !in_array($sertifikatKompetensiLain, $existingFiles, true)) {
            $statusRaw = $verifDok['sertifikat_kompetensi_lain'] ?? ($verifDok['transkrip'] ?? 'terupload');
            $isVerif = ($asesi->verifikasi === 'V') || ($statusRaw === 'terverifikasi');
            $verifCode = $isVerif ? 'V' : ($statusRaw === 'ditolak' ? 'D' : 'P');
            $verifLabel = $isVerif ? 'Terverifikasi' : ($statusRaw === 'ditolak' ? 'Ditolak' : 'Menunggu Persetujuan');

            $tglDocFormatted = $asesi->tgl_sertifikat
                ? \Carbon\Carbon::parse($asesi->tgl_sertifikat)->format('d/m/Y')
                : ($asesi->tgl_daftar ? \Carbon\Carbon::parse($asesi->tgl_daftar)->format('d/m/Y') : date('d/m/Y'));

            $dokumenSkema[] = [
                'id' => 'skema_kompetensi_lain',
                'jenis_doc' => 'Sertifikat Pelatihan Relevan',
                'nama_doc' => 'Sertifikat Pelatihan Relevan / Kompetensi',
                'file' => asset('storage/foto_asesi/' . $sertifikatKompetensiLain),
                'nomor_doc' => $asesi->no_sertifikat ?: ($asesi->no_pendaftaran),
                'tgl_doc' => $tglDocFormatted,
                'verifikasi' => $verifCode,
                'verifikasi_label' => $verifLabel,
                'catatan' => null,
            ];
            $existingFiles[] = $sertifikatKompetensiLain;
        }

        if (!empty($asesi->sertifikat_atpa_ktpa) && !in_array($asesi->sertifikat_atpa_ktpa, $existingFiles, true)) {
            $statusRaw = $verifDok['sertifikat_atpa_ktpa'] ?? 'terupload';
            $isVerif = ($asesi->verifikasi === 'V') || ($statusRaw === 'terverifikasi');
            $verifCode = $isVerif ? 'V' : ($statusRaw === 'ditolak' ? 'D' : 'P');
            $verifLabel = $isVerif ? 'Terverifikasi' : ($statusRaw === 'ditolak' ? 'Ditolak' : 'Menunggu Persetujuan');

            $tglDocFormatted = $asesi->tgl_daftar
                ? \Carbon\Carbon::parse($asesi->tgl_daftar)->format('d/m/Y')
                : date('d/m/Y');

            $dokumenSkema[] = [
                'id' => 'skema_atpa_ktpa',
                'jenis_doc' => 'Sertifikat ATPA/KTPA Sebelumnya',
                'nama_doc' => 'Sertifikat ATPA/KTPA Sebelumnya',
                'file' => asset('storage/foto_asesi/' . $asesi->sertifikat_atpa_ktpa),
                'nomor_doc' => $asesi->no_pendaftaran,
                'tgl_doc' => $tglDocFormatted,
                'verifikasi' => $verifCode,
                'verifikasi_label' => $verifLabel,
                'catatan' => null,
            ];
            $existingFiles[] = $asesi->sertifikat_atpa_ktpa;
        }

        $data['dokumen_skema'] = $dokumenSkema;

        // Pembayaran asesi (konfirmasi pembayaran & bukti transfer)
        $asesmenIds = \DB::table('asesi_asesmen')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->orWhere('id_asesi', (string) $asesi->id)
            ->pluck('id')
            ->toArray();

        $pembayaranItems = \DB::table('asesi_pembayaran')
            ->where(function ($q) use ($asesi, $asesmenIds) {
                $q->where('id_asesi', $asesi->no_pendaftaran)
                  ->orWhere('id_asesi', (string) $asesi->id);
                if (!empty($asesmenIds)) {
                    $q->orWhereIn('id_asesmen', $asesmenIds);
                }
            })
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($p) {
                $rek = \DB::table('rekeningbayar')->where('id', $p->tujuan_rek)->first();
                $rekLabel = $rek ? "{$rek->bank} {$rek->norek} a.n. {$rek->atasnama}" : ($p->tujuan_rek ? "Rekening #{$p->tujuan_rek}" : '-');
                return [
                    'id' => $p->id,
                    'id_asesmen' => $p->id_asesmen,
                    'metode_bayar' => $p->metode_bayar,
                    'jalur_bayar' => $p->jalur_bayar,
                    'tujuan_rek' => $p->tujuan_rek,
                    'rekening_label' => $rekLabel,
                    'nominal' => (int) $p->nominal,
                    'nominal_formatted' => number_format((float) $p->nominal, 0, ',', '.'),
                    'tgl_bayar' => $p->tgl_bayar,
                    'jam_bayar' => $p->jam_bayar ? substr((string) $p->jam_bayar, 0, 5) : null,
                    'file' => $p->file,
                    'bukti_url' => !empty($p->file) ? asset('storage/foto_asesibayar/' . $p->file) : null,
                    'status' => $p->status,
                    'status_label' => $p->status === 'V' ? 'Telah Divalidasi' : ($p->status === 'D' ? 'Ditolak' : 'Menunggu Validasi'),
                    'catatan_penolakan' => $p->catatan_penolakan ?? null,
                    'waktu' => $p->waktu,
                ];
            });

        $primaryAsesmen = \App\Models\AsesiAsesmen::where('id_asesi', $asesi->no_pendaftaran)
            ->orWhere('id_asesi', (string) $asesi->id)
            ->orderBy('id', 'desc')
            ->first();

        $data['pembayaran'] = $pembayaranItems->values()->toArray();
        $data['pembayaran_terakhir'] = $pembayaranItems->first();

        // Deteksi pembayaran remedial
        $remedialItem = $pembayaranItems->first(function ($p) {
            return $p['nominal'] == 1500000 || stripos($p['jalur_bayar'] ?? '', 'remedial') !== false;
        });
        if (!$remedialItem && $pembayaranItems->count() > 1) {
            $remedialItem = $pembayaranItems->first();
        }
        $data['remedial_pembayaran'] = $remedialItem;
        if ($remedialItem || ($primaryAsesmen && $primaryAsesmen->tujuan_sertifikasi === 'Sertifikasi Ulang')) {
            $data['tujuan_sertifikasi'] = 'Sertifikasi Ulang';
        }
        $data['biaya_asesmen_status'] = $primaryAsesmen ? $primaryAsesmen->biaya_asesmen : 'P';

        return $data;
    }
}
