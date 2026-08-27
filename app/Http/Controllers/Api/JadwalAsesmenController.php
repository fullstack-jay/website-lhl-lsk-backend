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
            \Log::info('Jadwal Asesmen Validation Failed', [
                'request' => $request->all(),
                'files' => array_keys($request->allFiles()),
                'errors' => $validator->errors()->toArray()
            ]);
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
}
