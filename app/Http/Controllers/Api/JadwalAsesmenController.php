<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JadwalAsesmen;
use App\Models\Asesor;
use App\Models\Komite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class JadwalAsesmenController extends Controller
{
    /**
     * Get all jadwal asesmen with pagination
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = JadwalAsesmen::with([
            'skema:id,id_skkni,judul,kode_skema',
            'tuk:id,nama,kode_tuk,alamat',
            'sumberAnggaran:id,jenis_anggaran',
            'pemberiAnggaran:id,nama_instansi',
            'asesor:id,nama,gelar_depan,gelar_blk,no_lisensi',
        ]);

        // Search
        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filter by event
        if ($request->has('id_event')) {
            $query->byEvent($request->id_event);
        }

        // Filter by year
        if ($request->has('tahun')) {
            $query->where('tahun', $request->tahun);
        }

        // Filter by periode
        if ($request->has('periode')) {
            $query->where('periode', $request->periode);
        }

        // Filter active (not Selesai)
        if ($request->has('active_only') && $request->active_only) {
            $query->active();
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'tgl_asesmen');
        $sortOrder = $request->get('sort_order', 'desc');

        if (!in_array($sortBy, ['id', 'tgl_asesmen', 'tgl_asesmen_akhir', 'nama_kegiatan', 'tahun'])) {
            $sortBy = 'tgl_asesmen';
        }

        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = min((int) $request->get('per_page', 20), 100);
        $page = (int) $request->get('page', 1);

        $jadwal = $query->paginate($perPage, ['*'], 'page', $page);

        // Transform data
        $data = $jadwal->map(function ($item) {
            return $this->transformJadwal($item);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $jadwal->currentPage(),
                'per_page' => $jadwal->perPage(),
                'total' => $jadwal->total(),
                'last_page' => $jadwal->lastPage(),
                'from' => $jadwal->firstItem(),
                'to' => $jadwal->lastItem(),
            ],
        ]);
    }

    /**
     * Get jadwal asesmen detail by ID
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $jadwal = JadwalAsesmen::with([
            'skema:id,id_skkni,judul,kode_skema',
            'tuk:id,nama,kode_tuk,alamat,penanggungjawab,telepon,email,kelurahan',
            'sumberAnggaran:id,jenis_anggaran',
            'pemberiAnggaran:id,nama_instansi',
            'asesor:id,nama,gelar_depan,gelar_blk,no_lisensi',
            'komite:id,nama,gelar_depan,gelar_blk',
        ])->find($id);

        if (!$jadwal) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal asesmen tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->transformJadwal($jadwal, true),
        ]);
    }

    /**
     * Create new jadwal asesmen
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Normalisasi: nilai 0 dari dropdown frontend berarti "tidak dipilih" -> null
        foreach (['pemberi_anggaran', 'sumber_anggaran', 'pelaksanaan_uji', 'id_event'] as $field) {
            if ($request->input($field) == 0 || $request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }

        $validator = Validator::make($request->all(), [
            'nama_kegiatan' => 'required',
            'tahun' => 'required|integer',
            'periode' => 'required|in:Januari,Februari,Maret,April,Mei,Juni,Juli,Agustus,September,Oktober,November,Desember',
            'gelombang' => 'required|integer',
            'tgl_asesmen' => 'required|date',
            'tgl_asesmen_akhir' => 'required|date|after_or_equal:tgl_asesmen',
            'jam_asesmen' => 'required',
            'tempat_asesmen' => 'required|exists:tuk,id',
            'kapasitas' => 'required|integer|min:1',
            'id_skemakkni' => 'required|exists:skema_kkni,id',
            'sumber_anggaran' => 'nullable|exists:sumber_anggaran,id',
            'pemberi_anggaran' => 'nullable|exists:pemberi_anggaran,id',
            'pelaksanaan_uji' => 'nullable|in:1,2,3,4',
            'id_event' => 'nullable',
            'file_surattugas' => 'nullable|mimes:pdf,doc,docx|max:5120',
            'dok_standarkompetensi' => 'nullable|mimes:pdf,doc,docx|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->except(['file_surattugas', 'dok_standarkompetensi']);

        // Handle file uploads
        if ($request->hasFile('file_surattugas')) {
            $file = $request->file('file_surattugas');
            $fileName = $this->uploadFile($file, 'surattugas');
            $data['file_surattugas'] = $fileName;
        }

        if ($request->hasFile('dok_standarkompetensi')) {
            $file = $request->file('dok_standarkompetensi');
            $fileName = $this->uploadFile($file, 'dokskkni');
            $data['dok_standarkompetensi'] = $fileName;
        }

        // Set default status
        $data['status'] = $data['status'] ?? 'Draft';

        $jadwal = JadwalAsesmen::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Jadwal asesmen berhasil ditambahkan',
            'data' => $this->transformJadwal($jadwal->load(['skema', 'tuk', 'sumberAnggaran', 'pemberiAnggaran'])),
        ], 201);
    }

    /**
     * Update jadwal asesmen
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $jadwal = JadwalAsesmen::find($id);

        if (!$jadwal) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal asesmen tidak ditemukan',
            ], 404);
        }

        // Normalisasi: nilai 0 dari dropdown frontend berarti "tidak dipilih" -> null
        foreach (['pemberi_anggaran', 'sumber_anggaran', 'pelaksanaan_uji', 'id_event'] as $field) {
            if ($request->input($field) == 0 || $request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }

        $validator = Validator::make($request->all(), [
            'nama_kegiatan' => 'required',
            'tahun' => 'required|integer',
            'periode' => 'required|in:Januari,Februari,Maret,April,Mei,Juni,Juli,Agustus,September,Oktober,November,Desember',
            'gelombang' => 'required|integer',
            'tgl_asesmen' => 'required|date',
            'tgl_asesmen_akhir' => 'required|date|after_or_equal:tgl_asesmen',
            'jam_asesmen' => 'required',
            'tempat_asesmen' => 'required|exists:tuk,id',
            'kapasitas' => 'required|integer|min:1',
            'id_skemakkni' => 'required|exists:skema_kkni,id',
            'sumber_anggaran' => 'nullable|exists:sumber_anggaran,id',
            'pemberi_anggaran' => 'nullable|exists:pemberi_anggaran,id',
            'pelaksanaan_uji' => 'nullable|in:1,2,3,4',
            'status' => 'nullable|in:Draft,Terkonfirmasi,Berlangsung,Selesai',
            'file_surattugas' => 'nullable|mimes:pdf,doc,docx|max:5120',
            'dok_standarkompetensi' => 'nullable|mimes:pdf,doc,docx|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->except(['file_surattugas', 'dok_standarkompetensi']);

        // Handle file uploads
        if ($request->hasFile('file_surattugas')) {
            // Delete old file
            if ($jadwal->file_surattugas) {
                $this->deleteFile($jadwal->file_surattugas);
            }
            $file = $request->file('file_surattugas');
            $fileName = $this->uploadFile($file, 'surattugas');
            $data['file_surattugas'] = $fileName;
        }

        if ($request->hasFile('dok_standarkompetensi')) {
            // Delete old file
            if ($jadwal->dok_standarkompetensi) {
                $this->deleteFile($jadwal->dok_standarkompetensi, 'dokskkni');
            }
            $file = $request->file('dok_standarkompetensi');
            $fileName = $this->uploadFile($file, 'dokskkni');
            $data['dok_standarkompetensi'] = $fileName;
        }

        $jadwal->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Jadwal asesmen berhasil diperbarui',
            'data' => $this->transformJadwal($jadwal->load(['skema', 'tuk', 'sumberAnggaran', 'pemberiAnggaran'])),
        ]);
    }

    /**
     * Delete jadwal asesmen
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $jadwal = JadwalAsesmen::find($id);

        if (!$jadwal) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal asesmen tidak ditemukan',
            ], 404);
        }

        // Check if jadwal has peserta
        $pesertaCount = \DB::table('asesi_asesmen')
            ->where('id_jadwal', $jadwal->id)
            ->count();

        if ($pesertaCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal asesmen tidak dapat dihapus karena masih memiliki peserta',
            ], 400);
        }

        // Delete files
        if ($jadwal->file_surattugas) {
            $this->deleteFile($jadwal->file_surattugas);
        }
        if ($jadwal->dok_standarkompetensi) {
            $this->deleteFile($jadwal->dok_standarkompetensi, 'dokskkni');
        }

        $jadwal->delete();

        return response()->json([
            'success' => true,
            'message' => 'Jadwal asesmen berhasil dihapus',
        ]);
    }

    /**
     * Update status jadwal
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $jadwal = JadwalAsesmen::find($id);

        if (!$jadwal) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal asesmen tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:Draft,Terkonfirmasi,Berlangsung,Selesai',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $jadwal->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Status jadwal asesmen berhasil diperbarui',
            'data' => $this->transformJadwal($jadwal),
        ]);
    }

    /**
     * Get jadwal options for dropdown
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function options()
    {
        $jadwal = JadwalAsesmen::active()
            ->orderBy('tgl_asesmen', 'desc')
            ->get(['id', 'nama_kegiatan', 'tgl_asesmen']);

        return response()->json([
            'success' => true,
            'data' => $jadwal->map(function ($item) {
                return [
                    'value' => $item->id,
                    'label' => "{$item->nama_kegiatan} ({$item->tgl_asesmen})",
                ];
            }),
        ]);
    }

    /**
     * Get statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics()
    {
        $total = JadwalAsesmen::count();
        $draft = JadwalAsesmen::draft()->count();
        $terkonfirmasi = JadwalAsesmen::terkonfirmasi()->count();
        $berlangsung = JadwalAsesmen::berlangsung()->count();
        $selesai = JadwalAsesmen::selesai()->count();

        // Get unique events
        $events = JadwalAsesmen::selectRaw('id_event, MIN(tgl_asesmen) as tgl_mulai, MAX(tgl_asesmen_akhir) as tgl_selesai')
            ->groupBy('id_event')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'draft' => $draft,
                'terkonfirmasi' => $terkonfirmasi,
                'berlangsung' => $berlangsung,
                'selesai' => $selesai,
                'events' => $events->count(),
            ],
        ]);
    }

    /**
     * Upload file helper
     *
     * @param $file
     * @param string $type
     * @return string
     */
    private function uploadFile($file, $type = 'surattugas')
    {
        $allowedExtensions = ['pdf', 'doc', 'docx'];
        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, $allowedExtensions)) {
            throw new \Exception('File harus berupa PDF, DOC, atau DOCX');
        }

        $timestamp = time();
        $hash = md5($file->getClientOriginalName() . microtime());
        $fileName = $timestamp . $hash . '.' . $extension;

        $destinationPath = $type === 'dokskkni' ? public_path('foto_dokskkni') : public_path('foto_surat');

        if (!file_exists($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }

        $file->move($destinationPath, $fileName);

        return $fileName;
    }

    /**
     * Delete file helper
     *
     * @param string $fileName
     * @param string $type
     * @return void
     */
    private function deleteFile($fileName, $type = 'surattugas')
    {
        $filePath = $type === 'dokskkni' ? public_path('foto_dokskkni/' . $fileName) : public_path('foto_surat/' . $fileName);

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * Transform jadwal asesmen data
     *
     * @param JadwalAsesmen $jadwal
     * @param bool $detail
     * @return array
     */
    private function transformJadwal(JadwalAsesmen $jadwal, bool $detail = false): array
    {
        $data = [
            'id' => $jadwal->id,
            'id_event' => $jadwal->id_event,
            'nama_kegiatan' => $jadwal->nama_kegiatan,
            'tahun' => $jadwal->tahun,
            'periode' => $jadwal->periode,
            'gelombang' => $jadwal->gelombang,
            'tgl_asesmen' => $jadwal->tgl_asesmen ? $jadwal->tgl_asesmen->format('Y-m-d') : null,
            'tgl_asesmen_akhir' => $jadwal->tgl_asesmen_akhir ? $jadwal->tgl_asesmen_akhir->format('Y-m-d') : null,
            'jam_asesmen' => $jadwal->jam_asesmen,
            'status' => $jadwal->status,
            'kapasitas' => $jadwal->kapasitas,
            'jumlah_peserta' => $jadwal->jumlah_peserta,
            'peserta_terjadwal' => $jadwal->jumlah_peserta,
            'peserta_asesmen_mandiri' => \DB::table('asesi_asesmen')->where('id_jadwal', $jadwal->id)->whereNotNull('status_apl02')->where('status_apl02', '!=', '')->count(),
            'sisa_kapasitas' => $jadwal->sisa_kapasitas,
            'skema' => $jadwal->skema ? [
                'id' => $jadwal->skema->id,
                'judul' => $jadwal->skema->judul,
                'kode_skema' => $jadwal->skema->kode_skema,
                'id_skkni' => $jadwal->skema->id_skkni,
            ] : null,
            'tuk' => $jadwal->tuk ? [
                'id' => $jadwal->tuk->id,
                'nama' => $jadwal->tuk->nama,
                'kode_tuk' => $jadwal->tuk->kode_tuk,
                'alamat' => $jadwal->tuk->alamat,
            ] : null,
            'sumber_anggaran' => $jadwal->sumberAnggaran ? [
                'id' => $jadwal->sumberAnggaran->id,
                'jenis_anggaran' => $jadwal->sumberAnggaran->jenis_anggaran,
            ] : null,
            'pemberi_anggaran' => $jadwal->pemberiAnggaran ? [
                'id' => $jadwal->pemberiAnggaran->id,
                'nama_instansi' => $jadwal->pemberiAnggaran->nama_instansi,
            ] : null,
            'pelaksanaan_uji' => $jadwal->pelaksanaan_uji,
            'pelaksanaan_uji_label' => $jadwal->pelaksanaan_uji_label,
            'dokumen_lengkap' => $jadwal->dokumen_lengkap,
            'penguji' => $jadwal->asesor ? $jadwal->asesor->map(function ($asesor) {
                return [
                    'id' => $asesor->id,
                    'nama' => $asesor->nama,
                    'gelar_depan' => $asesor->gelar_depan,
                    'gelar_blk' => $asesor->gelar_blk,
                    'no_lisensi' => $asesor->no_lisensi,
                ];
            }) : [],
            'peninjau' => \DB::table('asesi_asesmen')
                ->join('asesor', 'asesor.id', '=', 'asesi_asesmen.peninjau_ia11')
                ->where('asesi_asesmen.id_jadwal', $jadwal->id)
                ->whereNotNull('asesi_asesmen.peninjau_ia11')
                ->select(
                    'asesor.id',
                    'asesor.nama',
                    'asesor.gelar_depan',
                    'asesor.gelar_blk',
                    'asesor.no_lisensi'
                )
                ->distinct()
                ->get()
                ->map(function ($pen) {
                    return [
                        'id' => $pen->id,
                        'nama' => $pen->nama,
                        'gelar_depan' => $pen->gelar_depan,
                        'gelar_blk' => $pen->gelar_blk,
                        'no_lisensi' => $pen->no_lisensi,
                    ];
                })
                ->values()
                ->all(),
            // Dokumen pendukung selalu disertakan agar status badge dapat dirender di kartu jadwal
            'no_surattugas' => $jadwal->no_surattugas,
            'file_surattugas' => $jadwal->file_surattugas,
            'file_surattugas_url' => $jadwal->file_surattugas ? asset('foto_surat/' . $jadwal->file_surattugas) : null,
            'no_surattugaskomtek' => $jadwal->no_surattugaskomtek,
            'tgl_surattugaskomtek' => $jadwal->tgl_surattugaskomtek ? (is_string($jadwal->tgl_surattugaskomtek) ? substr($jadwal->tgl_surattugaskomtek, 0, 10) : $jadwal->tgl_surattugaskomtek->format('Y-m-d')) : null,
            'file_surattugaskomtek' => $jadwal->file_surattugaskomtek,
            'file_surattugaskomtek_url' => $jadwal->file_surattugaskomtek ? asset('foto_surat/' . $jadwal->file_surattugaskomtek) : null,
            'no_surattugasia11' => $jadwal->no_surattugasia11,
            'tgl_surattugasia11' => $jadwal->tgl_surattugasia11 ? (is_string($jadwal->tgl_surattugasia11) ? substr($jadwal->tgl_surattugasia11, 0, 10) : $jadwal->tgl_surattugasia11->format('Y-m-d')) : null,
            'file_surattugasia11' => $jadwal->file_surattugasia11,
            'file_surattugasia11_url' => $jadwal->file_surattugasia11 ? asset('foto_surat/' . $jadwal->file_surattugasia11) : null,
            'no_bakomite' => $jadwal->no_bakomite,
            'file_bakomite' => $jadwal->file_bakomite,
            'file_bakomite_url' => $jadwal->file_bakomite ? asset('foto_surat/' . $jadwal->file_bakomite) : null,
            'no_skkeputusan' => $jadwal->no_skkeputusan,
            'file_skkeputusan' => $jadwal->file_skkeputusan,
            'file_skkeputusan_url' => $jadwal->file_skkeputusan ? asset('foto_surat/' . $jadwal->file_skkeputusan) : null,
            'no_permohonanblangko' => $jadwal->no_permohonanblangko,
            'file_permohonanblangko' => $jadwal->file_permohonanblangko,
            'file_permohonanblangko_url' => $jadwal->file_permohonanblangko ? asset('foto_surat/' . $jadwal->file_permohonanblangko) : null,
            'dok_standarkompetensi' => $jadwal->dok_standarkompetensi,
            'dok_standarkompetensi_url' => $jadwal->dok_standarkompetensi ? asset('foto_dokskkni/' . $jadwal->dok_standarkompetensi) : null,
        ];

        if ($detail) {
            $data = array_merge($data, [
                'no_surattugas' => $jadwal->no_surattugas,
                'file_surattugas' => $jadwal->file_surattugas,
                'file_surattugas_url' => $jadwal->file_surattugas ? asset('foto_surat/' . $jadwal->file_surattugas) : null,
                'no_surattugaskomtek' => $jadwal->no_surattugaskomtek,
                'tgl_surattugaskomtek' => $jadwal->tgl_surattugaskomtek ? $jadwal->tgl_surattugaskomtek->format('Y-m-d') : null,
                'file_surattugaskomtek' => $jadwal->file_surattugaskomtek,
                'file_surattugaskomtek_url' => $jadwal->file_surattugaskomtek ? asset('foto_surat/' . $jadwal->file_surattugaskomtek) : null,
                'no_surattugasia11' => $jadwal->no_surattugasia11,
                'tgl_surattugasia11' => $jadwal->tgl_surattugasia11 ? $jadwal->tgl_surattugasia11->format('Y-m-d') : null,
                'file_surattugasia11' => $jadwal->file_surattugasia11,
                'file_surattugasia11_url' => $jadwal->file_surattugasia11 ? asset('foto_surat/' . $jadwal->file_surattugasia11) : null,
                'no_bakomite' => $jadwal->no_bakomite,
                'file_bakomite' => $jadwal->file_bakomite,
                'file_bakomite_url' => $jadwal->file_bakomite ? asset('foto_surat/' . $jadwal->file_bakomite) : null,
                'no_skkeputusan' => $jadwal->no_skkeputusan,
                'file_skkeputusan' => $jadwal->file_skkeputusan,
                'file_skkeputusan_url' => $jadwal->file_skkeputusan ? asset('foto_surat/' . $jadwal->file_skkeputusan) : null,
                'no_permohonanblangko' => $jadwal->no_permohonanblangko,
                'file_permohonanblangko' => $jadwal->file_permohonanblangko,
                'file_permohonanblangko_url' => $jadwal->file_permohonanblangko ? asset('foto_surat/' . $jadwal->file_permohonanblangko) : null,
                'dok_standarkompetensi' => $jadwal->dok_standarkompetensi,
                'dok_standarkompetensi_url' => $jadwal->dok_standarkompetensi ? asset('foto_dokskkni/' . $jadwal->dok_standarkompetensi) : null,
                'kodejadwal_bnsp' => $jadwal->kodejadwal_bnsp,
                'id_jadwalbnsp' => $jadwal->id_jadwalbnsp,
                'asesor' => $jadwal->asesor->map(function ($asesor) {
                    return [
                        'id' => $asesor->id,
                        'nama' => $asesor->nama,
                        'gelar_depan' => $asesor->gelar_depan,
                        'gelar_blk' => $asesor->gelar_blk,
                        'no_lisensi' => $asesor->no_lisensi,
                    ];
                }),
                'komite' => $jadwal->komite->map(function ($komite) {
                    return [
                        'id' => $komite->id,
                        'nama' => $komite->nama,
                        'gelar_depan' => $komite->gelar_depan,
                        'gelar_blk' => $komite->gelar_blk,
                    ];
                }),
                'status_verifikasi_tuk' => $jadwal->status_verifikasi_tuk,
            ]);
        }

        return $data;
    }

    /**
     * Get participants assigned to a schedule
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPeserta($id)
    {
        $jadwal = JadwalAsesmen::findOrFail($id);

        $items = \App\Models\AsesiAsesmen::where('id_jadwal', $id)
            ->join('asesi', 'asesi.no_pendaftaran', '=', 'asesi_asesmen.id_asesi')
            ->leftJoin('asesor as peninjau', 'peninjau.id', '=', 'asesi_asesmen.peninjau_ia11')
            ->select([
                'asesi.id as id',
                'asesi_asesmen.id as id_asesmen',
                'asesi.no_pendaftaran',
                'asesi.nama',
                'asesi.no_ktp',
                'asesi_asesmen.tgl_daftar',
                'asesi_asesmen.status_asesmen',
                'asesi_asesmen.peninjau_ia11',
                'peninjau.nama as peninjau_nama',
                'peninjau.gelar_depan as peninjau_gelar_depan',
                'peninjau.gelar_blk as peninjau_gelar_blk',
            ])
            ->orderBy('asesi_asesmen.id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Assign or unassign Peninjau IA.11 for peserta in a schedule
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePeninjauIa11(Request $request, $id)
    {
        $jadwal = JadwalAsesmen::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'id_asesor' => 'nullable|integer',
            'id_asesmen' => 'nullable|integer',
            'apply_all' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $idAsesor = $request->input('id_asesor');
        $applyAll = filter_var($request->input('apply_all', false), FILTER_VALIDATE_BOOLEAN);
        $idAsesmen = $request->input('id_asesmen');

        if ($idAsesor !== null) {
            $asesorExists = \DB::table('asesor')->where('id', $idAsesor)->exists();
            if (!$asesorExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data asesor peninjau tidak ditemukan',
                ], 404);
            }
        }

        if ($applyAll) {
            $affected = \DB::table('asesi_asesmen')
                ->where('id_jadwal', $id)
                ->update(['peninjau_ia11' => $idAsesor]);

            return response()->json([
                'success' => true,
                'message' => $idAsesor
                    ? "Berhasil menugaskan peninjau IA.11 untuk {$affected} peserta"
                    : "Berhasil mengosongkan peninjau IA.11 untuk seluruh peserta",
                'affected' => $affected,
            ]);
        }

        if (!$idAsesmen) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter id_asesmen atau apply_all wajib diisi',
            ], 422);
        }

        $peserta = \DB::table('asesi_asesmen')
            ->where('id', $idAsesmen)
            ->where('id_jadwal', $id)
            ->first();

        if (!$peserta) {
            return response()->json([
                'success' => false,
                'message' => 'Data peserta asesmen tidak ditemukan pada jadwal ini',
            ], 404);
        }

        \DB::table('asesi_asesmen')
            ->where('id', $idAsesmen)
            ->update(['peninjau_ia11' => $idAsesor]);

        return response()->json([
            'success' => true,
            'message' => $idAsesor
                ? 'Berhasil menugaskan peninjau IA.11'
                : 'Berhasil melepas peninjau IA.11',
        ]);
    }

    /**
     * Get available participants (not scheduled yet) for this schedule's skema
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPesertaTersedia(Request $request, $id)
    {
        $jadwal = JadwalAsesmen::findOrFail($id);
        $search = $request->query('search', '');

        $query = \App\Models\AsesiAsesmen::whereNull('asesi_asesmen.id_jadwal')
            ->where('asesi_asesmen.id_skemakkni', $jadwal->id_skemakkni)
            ->where(function ($q) {
                $q->where('asesi_asesmen.status', '!=', 'R')
                  ->orWhereNull('asesi_asesmen.status');
            })
            ->join('asesi', 'asesi.no_pendaftaran', '=', 'asesi_asesmen.id_asesi')
            ->leftJoin('skema_kkni', 'skema_kkni.id', '=', 'asesi_asesmen.id_skemakkni')
            ->where('asesi.verifikasi', 'V')
            ->where(function ($q) {
                $q->where('asesi.blokir', '!=', 'Y')
                  ->orWhereNull('asesi.blokir');
            })
            ->select([
                'asesi.id as id',
                'asesi_asesmen.id as id_asesmen',
                'asesi.no_pendaftaran',
                'asesi.nama',
                'asesi.no_ktp',
                'asesi_asesmen.id_skemakkni',
                'skema_kkni.judul as judul_skema',
            ]);

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('asesi.nama', 'like', "%{$search}%")
                  ->orWhere('asesi.no_pendaftaran', 'like', "%{$search}%")
                  ->orWhere('asesi.no_ktp', 'like', "%{$search}%");
            });
        }

        $items = $query->orderBy('asesi_asesmen.id', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Get assessors assigned to a schedule
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPenguji($id)
    {
        $jadwal = JadwalAsesmen::findOrFail($id);

        $items = $jadwal->asesor()->select([
            'asesor.id',
            'asesor.nama',
            'asesor.gelar_depan',
            'asesor.gelar_blk',
            'asesor.no_lisensi',
            'asesor.no_hp',
            'asesor.email',
        ])->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Get available assessors (not assigned to this schedule yet)
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPengujiTersedia(Request $request, $id)
    {
        $jadwal = JadwalAsesmen::findOrFail($id);
        $search = $request->query('search', '');

        $assignedIds = \DB::table('jadwal_asesor')
            ->where('id_jadwal', $id)
            ->pluck('id_asesor');

        $query = Asesor::whereNotIn('id', $assignedIds);

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('no_lisensi', 'like', "%{$search}%")
                  ->orWhere('no_induk', 'like', "%{$search}%");
            });
        }

        $items = $query->orderBy('nama', 'asc')->get([
            'id', 'nama', 'gelar_depan', 'gelar_blk', 'no_lisensi', 'no_hp', 'email',
        ]);

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Assign assessor to schedule
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignPenguji(Request $request, $id)
    {
        $jadwal = JadwalAsesmen::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'id_asesor' => 'required|integer|exists:asesor,id',
        ], [
            'id_asesor.required' => 'Penguji wajib dipilih',
            'id_asesor.exists' => 'Penguji tidak valid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $exists = \DB::table('jadwal_asesor')
            ->where('id_jadwal', $id)
            ->where('id_asesor', $request->id_asesor)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Penguji sudah ditugaskan pada jadwal ini',
            ], 400);
        }

        \DB::table('jadwal_asesor')->insert([
            'id_jadwal' => $id,
            'id_asesor' => $request->id_asesor,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Penguji berhasil ditugaskan ke jadwal',
        ]);
    }

    /**
     * Unassign assessor from schedule
     *
     * @param int $id
     * @param int $idAsesor
     * @return \Illuminate\Http\JsonResponse
     */
    public function unassignPenguji($id, $idAsesor)
    {
        $jadwal = JadwalAsesmen::findOrFail($id);

        $deleted = \DB::table('jadwal_asesor')
            ->where('id_jadwal', $id)
            ->where('id_asesor', $idAsesor)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Penguji tidak ditemukan pada jadwal ini',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Penguji berhasil dilepas dari jadwal',
        ]);
    }

    /**
     * Get documents for a schedule
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDokumen($id)
    {
        $jadwal = JadwalAsesmen::find($id);

        if (!$jadwal) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal asesmen tidak ditemukan',
            ], 404);
        }

        $dokumen = [
            'surattugas' => [
                'key' => 'surattugas',
                'nama' => 'Surat Tugas Penguji',
                'deskripsi' => 'Dokumen resmi penugasan penguji untuk asesmen kompetensi',
                'nomor' => $jadwal->no_surattugas,
                'file' => $jadwal->file_surattugas,
                'file_url' => $jadwal->file_surattugas ? asset('foto_surat/' . $jadwal->file_surattugas) : null,
                'has_nomor' => true,
                'has_tanggal' => false,
            ],
            'surattugaskomtek' => [
                'key' => 'surattugaskomtek',
                'nama' => 'Surat Tugas Komite Teknis',
                'deskripsi' => 'Surat penugasan anggota komite teknis uji kompetensi',
                'nomor' => $jadwal->no_surattugaskomtek,
                'tanggal' => $jadwal->tgl_surattugaskomtek ? (is_string($jadwal->tgl_surattugaskomtek) ? substr($jadwal->tgl_surattugaskomtek, 0, 10) : $jadwal->tgl_surattugaskomtek->format('Y-m-d')) : null,
                'file' => $jadwal->file_surattugaskomtek,
                'file_url' => $jadwal->file_surattugaskomtek ? asset('foto_surat/' . $jadwal->file_surattugaskomtek) : null,
                'has_nomor' => true,
                'has_tanggal' => true,
            ],
            'surattugasia11' => [
                'key' => 'surattugasia11',
                'nama' => 'Surat Tugas Peninjau (FR.IA.11)',
                'deskripsi' => 'Surat penugasan peninjau instrumen asesmen FR.IA.11',
                'nomor' => $jadwal->no_surattugasia11,
                'tanggal' => $jadwal->tgl_surattugasia11 ? (is_string($jadwal->tgl_surattugasia11) ? substr($jadwal->tgl_surattugasia11, 0, 10) : $jadwal->tgl_surattugasia11->format('Y-m-d')) : null,
                'file' => $jadwal->file_surattugasia11,
                'file_url' => $jadwal->file_surattugasia11 ? asset('foto_surat/' . $jadwal->file_surattugasia11) : null,
                'has_nomor' => true,
                'has_tanggal' => true,
            ],
            'bakomite' => [
                'key' => 'bakomite',
                'nama' => 'Berita Acara (BA) Komite',
                'deskripsi' => 'Berita acara rapat pleno komite teknis pengambilan keputusan',
                'nomor' => $jadwal->no_bakomite,
                'file' => $jadwal->file_bakomite,
                'file_url' => $jadwal->file_bakomite ? asset('foto_surat/' . $jadwal->file_bakomite) : null,
                'has_nomor' => true,
                'has_tanggal' => false,
            ],
            'skkeputusan' => [
                'key' => 'skkeputusan',
                'nama' => 'Surat Keputusan (SK) Hasil Asesmen',
                'deskripsi' => 'SK penetapan hasil uji sertifikasi kompetensi',
                'nomor' => $jadwal->no_skkeputusan,
                'file' => $jadwal->file_skkeputusan,
                'file_url' => $jadwal->file_skkeputusan ? asset('foto_surat/' . $jadwal->file_skkeputusan) : null,
                'has_nomor' => true,
                'has_tanggal' => false,
            ],
            'permohonanblangko' => [
                'key' => 'permohonanblangko',
                'nama' => 'Permohonan Blangko Sertifikat',
                'deskripsi' => 'Surat permohonan penerbitan blangko sertifikat kompetensi',
                'nomor' => $jadwal->no_permohonanblangko,
                'file' => $jadwal->file_permohonanblangko,
                'file_url' => $jadwal->file_permohonanblangko ? asset('foto_surat/' . $jadwal->file_permohonanblangko) : null,
                'has_nomor' => true,
                'has_tanggal' => false,
            ],
            'dokskkni' => [
                'key' => 'dokskkni',
                'nama' => 'Dokumen Standar Kompetensi (SKKNI)',
                'deskripsi' => 'Dokumen acuan standar kompetensi skema yang diujikan',
                'nomor' => null,
                'file' => $jadwal->dok_standarkompetensi,
                'file_url' => $jadwal->dok_standarkompetensi ? asset('foto_dokskkni/' . $jadwal->dok_standarkompetensi) : null,
                'has_nomor' => false,
                'has_tanggal' => false,
            ],
        ];

        $uploadedCount = 0;
        foreach ($dokumen as $d) {
            if (!empty($d['file'])) {
                $uploadedCount++;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'jadwal_id' => (int) $jadwal->id,
                'nama_kegiatan' => $jadwal->nama_kegiatan,
                'total_dokumen' => count($dokumen),
                'uploaded_dokumen' => $uploadedCount,
                'is_lengkap' => $uploadedCount === count($dokumen),
                'dokumen' => $dokumen,
            ],
        ]);
    }

    /**
     * Update/Upload documents for a schedule
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateDokumen(\Illuminate\Http\Request $request, $id)
    {
        $jadwal = JadwalAsesmen::find($id);

        if (!$jadwal) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal asesmen tidak ditemukan',
            ], 404);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'no_surattugas' => 'nullable|string|max:255',
            'file_surattugas' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'no_surattugaskomtek' => 'nullable|string|max:255',
            'tgl_surattugaskomtek' => 'nullable|date',
            'file_surattugaskomtek' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'no_surattugasia11' => 'nullable|string|max:255',
            'tgl_surattugasia11' => 'nullable|date',
            'file_surattugasia11' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'no_bakomite' => 'nullable|string|max:255',
            'file_bakomite' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'no_skkeputusan' => 'nullable|string|max:255',
            'file_skkeputusan' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'no_permohonanblangko' => 'nullable|string|max:255',
            'file_permohonanblangko' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'dok_standarkompetensi' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal: ' . implode(', ', $validator->errors()->all()),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Text & date fields
        $fields = [
            'no_surattugas',
            'no_surattugaskomtek',
            'tgl_surattugaskomtek',
            'no_surattugasia11',
            'tgl_surattugasia11',
            'no_bakomite',
            'no_skkeputusan',
            'no_permohonanblangko',
        ];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $jadwal->{$field} = $request->input($field);
            }
        }

        // Files
        $fileConfigs = [
            'file_surattugas' => 'surattugas',
            'file_surattugaskomtek' => 'surattugas',
            'file_surattugasia11' => 'surattugas',
            'file_bakomite' => 'surattugas',
            'file_skkeputusan' => 'surattugas',
            'file_permohonanblangko' => 'surattugas',
            'dok_standarkompetensi' => 'dokskkni',
        ];

        foreach ($fileConfigs as $field => $folderType) {
            if ($request->hasFile($field)) {
                if ($jadwal->{$field}) {
                    $this->deleteFile($jadwal->{$field}, $folderType);
                }
                $file = $request->file($field);
                $fileName = $this->uploadFile($file, $folderType);
                $jadwal->{$field} = $fileName;
            }
        }

        $jadwal->save();

        return $this->getDokumen($id);
    }

    /**
     * Delete a document file from schedule
     *
     * @param int $id
     * @param string $jenis
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteDokumen($id, $jenis)
    {
        $jadwal = JadwalAsesmen::find($id);

        if (!$jadwal) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal asesmen tidak ditemukan',
            ], 404);
        }

        $mapping = [
            'surattugas' => ['file' => 'file_surattugas', 'type' => 'surattugas', 'no' => 'no_surattugas'],
            'surattugaskomtek' => ['file' => 'file_surattugaskomtek', 'type' => 'surattugas', 'no' => 'no_surattugaskomtek'],
            'surattugasia11' => ['file' => 'file_surattugasia11', 'type' => 'surattugas', 'no' => 'no_surattugasia11'],
            'bakomite' => ['file' => 'file_bakomite', 'type' => 'surattugas', 'no' => 'no_bakomite'],
            'skkeputusan' => ['file' => 'file_skkeputusan', 'type' => 'surattugas', 'no' => 'no_skkeputusan'],
            'permohonanblangko' => ['file' => 'file_permohonanblangko', 'type' => 'surattugas', 'no' => 'no_permohonanblangko'],
            'dokskkni' => ['file' => 'dok_standarkompetensi', 'type' => 'dokskkni', 'no' => null],
        ];

        if (!isset($mapping[$jenis])) {
            return response()->json([
                'success' => false,
                'message' => 'Jenis dokumen tidak valid',
            ], 400);
        }

        $cfg = $mapping[$jenis];
        $fileCol = $cfg['file'];

        if ($jadwal->{$fileCol}) {
            $this->deleteFile($jadwal->{$fileCol}, $cfg['type']);
            $jadwal->{$fileCol} = null;
            $jadwal->save();
        }

        return $this->getDokumen($id);
    }
}
