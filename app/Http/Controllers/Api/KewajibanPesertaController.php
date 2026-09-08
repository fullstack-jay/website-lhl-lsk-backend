<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesi;
use App\Models\AsesiAsesmen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Kewajiban Peserta (Pemeliharaan & Evaluasi Sertifikasi)
 * GET/POST /api/v1/peserta/kewajiban... (auth:sanctum, role peserta)
 * Sesuai docs/ALUR_LOGIC_KEWAJIBAN_PESERTA.md:
 *
 * - Sertifikat derived dari asesi_asesmen (status_asesmen='K' → no_serisertifikat,
 *   masa_berlaku, foto_sertifikat) — tanpa tabel baru
 * - PKB tahunan: 4 dokumen wajib / Form PKB rows
 * - Evaluasi 3 tahunan: 1 dokumen AMDAL (cover/tim/pengesahan)
 * - Logbook: Form ATPA 10-field + 3 bukti wajib per row
 * - Notifikasi: daftar + tandai dibaca
 *
 * State machine status DI-DERIVE saat GET (tempo berjalan otomatis walau
 * scheduler tidak jalan) + sinkron ke DB.
 */
class KewajibanPesertaController extends Controller
{
    private const UPLOAD_DIR = 'foto_kewajiban';

    /** Tempo pemeliharaan: 31 Des tahun kewajiban. */
    private const BATAS_AKAN_JATUH_TEMPO_BULAN = 3;   // ≤3 bulan sebelum tempo

    // ════════════════════════════════════════════════════════════════
    // GET / — DASHBOARD (sertifikat + summary + 3 tab + notifikasi)
    // ════════════════════════════════════════════════════════════════

    public function index(Request $request): JsonResponse
    {
        [$asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        // ── Sertifikat (derived: asesmen K dgn no_serisertifikat) ──
        $sertifikatAsesmen = AsesiAsesmen::where('id_asesi', $asesi->no_pendaftaran)
            ->where('status_asesmen', 'K')
            ->whereNotNull('no_serisertifikat')
            ->orderBy('id', 'desc')
            ->first();

        if (!$sertifikatAsesmen) {
            return response()->json([
                'success' => true,
                'data' => [
                    'sertifikat' => ['ada' => false],
                    'summary' => null,
                    'pemeliharaan' => [],
                    'evaluasi' => [],
                    'logbook_tahun_tersedia' => [],
                    'notifikasi' => ['belum_dibaca' => 0, 'daftar' => []],
                ],
            ]);
        }

        $sertifikatNo = $sertifikatAsesmen->no_serisertifikat;

        // Auto-create pemeliharaan tahun berjalan + evaluasi berikutnya (lazy §7)
        $this->ensureRecords($asesi->no_pendaftaran, $sertifikatNo, $sertifikatAsesmen);

        // ── Pemeliharaan + derive status ──
        $pemeliharaan = DB::table('asesi_pemeliharaan')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->orderBy('tahun', 'desc')
            ->get()
            ->map(function ($p) {
                $derived = $this->deriveStatus($p->status, $p->tahun, false, !empty($p->tanggal_upload));
                // Sinkronkan derived ke DB bila berubah
                if ($derived['status'] !== $p->status) {
                    DB::table('asesi_pemeliharaan')->where('id', $p->id)
                        ->update(['status' => $derived['status']]);
                    $p->status = $derived['status'];
                }
                return [
                    'id' => $p->id,
                    'tahun' => (int) $p->tahun,
                    'sertifikat_no' => $p->sertifikat_no,
                    'status' => $p->status,
                    'status_label' => $this->statusLabel($p->status),
                    'tanggal_upload' => $this->fmtDateTime($p->tanggal_upload),
                    'tanggal_jatuh_tempo' => "{$p->tahun}-12-31",
                    'tanggal_evaluasi' => $this->fmtDateTime($p->tanggal_evaluasi),
                    'catatan_evaluator' => $p->catatan_evaluator,
                    'dokumen' => [
                        'penunjukan' => $p->file_penunjukan ? asset(self::dir() . '/' . $p->file_penunjukan) : null,
                        'logbook' => $p->file_logbook ? asset(self::dir() . '/' . $p->file_logbook) : null,
                        'cover_tim' => $p->file_cover_tim ? asset(self::dir() . '/' . $p->file_cover_tim) : null,
                        'ka_andal' => $p->file_ka_andal ? asset(self::dir() . '/' . $p->file_ka_andal) : null,
                    ],
                    'bisa_upload' => $derived['bisa_upload'],
                ];
            })->all();

        // ── Evaluasi + derive status ──
        $evaluasi = DB::table('asesi_evaluasi')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->orderBy('tahun_ke', 'asc')
            ->get()
            ->map(function ($e) {
                $derived = $this->deriveStatus($e->status, (int) $e->tahun_jatuh_tempo, true, !empty($e->file_dokumen));
                if ($derived['status'] !== $e->status) {
                    DB::table('asesi_evaluasi')->where('id', $e->id)
                        ->update(['status' => $derived['status']]);
                    $e->status = $derived['status'];
                }
                return [
                    'id' => $e->id,
                    'tahun_ke' => (int) $e->tahun_ke,
                    'sertifikat_no' => $e->sertifikat_no,
                    'status' => $e->status,
                    'status_label' => $this->statusLabel($e->status),
                    'tanggal_jatuh_tempo' => "{$e->tahun_jatuh_tempo}-12-31",
                    'tanggal_upload' => $this->fmtDateTime($e->tanggal_upload),
                    'jenis_dokumen' => $e->jenis_dokumen,
                    'dokumen_url' => $e->file_dokumen ? asset(self::dir() . '/' . $e->file_dokumen) : null,
                    'catatan_evaluator' => $e->catatan_evaluator,
                    'bisa_upload' => $derived['bisa_upload'],
                ];
            })->all();

        // ── Summary cards (derivasi §5) ──
        $summary = [
            'pemeliharaan_aktif_tahun' => DB::table('asesi_pemeliharaan')
                ->where('id_asesi', $asesi->no_pendaftaran)
                ->where('status', 'DISETUJUI')
                ->orderBy('tahun', 'desc')->value('tahun'),
            'status_pemeliharaan' => null,
            'status_pemeliharaan_label' => null,
            'jatuh_tempo_berikutnya' => null,
            'evaluasi_berikutnya_tahun' => DB::table('asesi_evaluasi')
                ->where('id_asesi', $asesi->no_pendaftaran)
                ->where('status', '!=', 'DISETUJUI')
                ->orderBy('tahun_jatuh_tempo')->value('tahun_jatuh_tempo'),
        ];

        $tahunIni = (int) now()->year;
        $current = collect($pemeliharaan)->firstWhere('tahun', $tahunIni)
            ?? collect($pemeliharaan)->first();   // fallback: record terakhir
        if ($current) {
            $summary['status_pemeliharaan'] = $current['status'];
            $summary['status_pemeliharaan_label'] = $current['status_label'];
        }
        $summary['jatuh_tempo_berikutnya'] = collect($pemeliharaan)
            ->where('status', '!=', 'DISETUJUI')
            ->sortBy('tanggal_jatuh_tempo')
            ->first()['tanggal_jatuh_tempo'] ?? null;

        // ── Notifikasi ──
        $notifRows = DB::table('asesi_notifikasi')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->orderBy('waktu', 'desc')
            ->limit(30)
            ->get();

        $notifikasi = [
            'belum_dibaca' => $notifRows->where('dibaca', 0)->count(),
            'daftar' => $notifRows->map(fn ($n) => [
                'id' => $n->id,
                'tipe' => $n->tipe,
                'judul' => $n->judul,
                'pesan' => $n->pesan,
                'tanggal' => $n->waktu ? date('Y-m-d', strtotime($n->waktu)) : null,
                'dibaca' => (bool) $n->dibaca,
            ]),
        ];

        // ── Logbook tahun tersedia ──
        $logbookTahun = DB::table('asesi_logbook')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->distinct()->orderBy('tahun', 'desc')
            ->pluck('tahun');

        return response()->json([
            'success' => true,
            'data' => [
                'sertifikat' => [
                    'ada' => true,
                    'no_sertifikat' => $sertifikatAsesmen->no_serisertifikat,
                    'masa_berlaku' => $sertifikatAsesmen->masa_berlaku
                        ? date('Y-m-d', strtotime($sertifikatAsesmen->masa_berlaku)) : null,
                    'foto_sertifikat_url' => !empty($sertifikatAsesmen->foto_sertifikat)
                        ? asset('foto_sertifikat/' . $sertifikatAsesmen->foto_sertifikat) : null,
                    'status_masa_berlaku' => $this->statusMasaBerlaku($sertifikatAsesmen->masa_berlaku),
                ],
                'summary' => $summary,
                'pemeliharaan' => $pemeliharaan,
                'evaluasi' => $evaluasi,
                'logbook_tahun_tersedia' => $logbookTahun,
                'notifikasi' => $notifikasi,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // PEMELIHARAAN — riwayat + PKB rows
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /pemeliharaan — riwayat + rows PKB per tahun (pre-fill Form PKB)
     */
    public function pemeliharaan(Request $request): JsonResponse
    {
        [$asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        $rows = DB::table('asesi_pemeliharaan as p')
            ->leftJoin('asesi_pemeliharaan_pkb as pk', 'pk.id_pemeliharaan', '=', 'p.id')
            ->where('p.id_asesi', $asesi->no_pendaftaran)
            ->orderBy('p.tahun', 'desc')
            ->get([
                'p.id', 'p.tahun', 'p.status', 'p.file_penunjukan', 'p.file_logbook',
                'p.file_cover_tim', 'p.file_ka_andal',
                'pk.id as pkb_id', 'pk.bentuk_kegiatan', 'pk.bentuk_lainnya', 'pk.tema',
                'pk.penyelenggara', 'pk.lokasi', 'pk.waktu as pkb_waktu',
                'pk.deskripsi_singkat', 'pk.file_bukti',
            ]);

        // Group PKB rows per pemeliharaan
        $grouped = [];
        foreach ($rows as $r) {
            if (!isset($grouped[$r->id])) {
                $grouped[$r->id] = [
                    'id' => $r->id,
                    'tahun' => (int) $r->tahun,
                    'status' => $r->status,
                    'status_label' => $this->statusLabel($r->status),
                    'dokumen' => [
                        'penunjukan' => $r->file_penunjukan ? asset(self::dir() . '/' . $r->file_penunjukan) : null,
                        'logbook' => $r->file_logbook ? asset(self::dir() . '/' . $r->file_logbook) : null,
                        'cover_tim' => $r->file_cover_tim ? asset(self::dir() . '/' . $r->file_cover_tim) : null,
                        'ka_andal' => $r->file_ka_andal ? asset(self::dir() . '/' . $r->file_ka_andal) : null,
                    ],
                    'pkb_rows' => [],
                ];
            }
            if ($r->pkb_id) {
                $grouped[$r->id]['pkb_rows'][] = [
                    'id' => $r->pkb_id,
                    'bentuk_kegiatan' => $r->bentuk_kegiatan,
                    'bentuk_lainnya' => $r->bentuk_lainnya,
                    'tema' => $r->tema,
                    'penyelenggara' => $r->penyelenggara,
                    'lokasi' => $r->lokasi,
                    'waktu' => $r->pkb_waktu,
                    'deskripsi_singkat' => $r->deskripsi_singkat,
                    'file_bukti_url' => $r->file_bukti ? asset(self::dir() . '/' . $r->file_bukti) : null,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => array_values($grouped),
        ]);
    }

    /**
     * POST /pemeliharaan/{tahun} — submit 4 dokumen wajib (multipart)
     * Status → SUDAH_UPLOAD. Revisi (PERLU_PERBAIKAN) → menimpa + kembali SUDAH_UPLOAD.
     */
    public function submitPemeliharaan(Request $request, $tahun): JsonResponse
    {
        [$asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'file_penunjukan' => 'required|file|mimes:pdf,jpg,jpeg|max:5120',
            'file_logbook' => 'required|file|mimes:pdf,jpg,jpeg|max:5120',
            'file_cover_tim' => 'required|file|mimes:pdf,jpg,jpeg|max:5120',
            'file_ka_andal' => 'required|file|mimes:pdf,jpg,jpeg|max:5120',
        ], [
            'file_penunjukan.required' => 'Dokumen Penunjukan/Surat Tugas wajib diunggah',
            'file_logbook.required' => 'Dokumen Logbook Kegiatan wajib diunggah',
            'file_cover_tim.required' => 'Dokumen Cover & Susunan Tim wajib diunggah',
            'file_ka_andal.required' => 'Dokumen KAA Andal & Rekomendasi KA wajib diunggah',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Sertifikat wajib ada
        $asesmen = AsesiAsesmen::where('id_asesi', $asesi->no_pendaftaran)
            ->where('status_asesmen', 'K')->whereNotNull('no_serisertifikat')
            ->orderBy('id', 'desc')->first();
        if (!$asesmen) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum memiliki sertifikat aktif',
            ], 422);
        }

        $record = DB::table('asesi_pemeliharaan')
            ->where('id_asesi', $asesi->no_pendaftaran)->where('tahun', $tahun)
            ->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Record pemeliharaan tahun ' . $tahun . ' belum tersedia',
            ], 404);
        }

        // bisa_upload guard
        $derived = $this->deriveStatus($record->status, (int) $tahun, false, false);
        if (!$derived['bisa_upload'] && $record->status !== 'PERLU_PERBAIKAN') {
            return response()->json([
                'success' => false,
                'message' => 'Upload tidak diizinkan pada status ' . $this->statusLabel($record->status),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $updates = [
                'status' => 'SUDAH_UPLOAD',
                'tanggal_upload' => now(),
            ];
            foreach (['file_penunjukan', 'file_logbook', 'file_cover_tim', 'file_ka_andal'] as $field) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $fileName = time() . '_pmlh_' . $tahun . '_' . $field . '_' . uniqid() . '.' .
                        strtolower($file->getClientOriginalExtension());
                    $dest = public_path(self::dir());
                    if (!file_exists($dest)) mkdir($dest, 0755, true);
                    // Timpa file lama (revisi)
                    $old = public_path(self::dir() . '/' . $record->{$field});
                    if (!empty($record->{$field}) && file_exists($old)) @unlink($old);
                    $file->move($dest, $fileName);
                    $updates[$field] = $fileName;
                }
            }

            DB::table('asesi_pemeliharaan')->where('id', $record->id)->update($updates);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dokumen pemeliharaan berhasil dikirim',
                'data' => ['tahun' => (int) $tahun, 'status' => 'SUDAH_UPLOAD'],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan dokumen pemeliharaan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /pemeliharaan/{tahun}/pkb — simpan rows Form PKB (replace-all)
     * Body: rows[] JSON + files multipart (key = index row)
     */
    public function storePkb(Request $request, $tahun): JsonResponse
    {
        [$asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        $rows = $request->input('rows', []);
        if (is_string($rows)) {
            $rows = json_decode($rows, true) ?: [];
        }

        if (empty($rows) || !is_array($rows)) {
            return response()->json([
                'success' => false,
                'message' => 'Minimal 1 baris kegiatan PKB wajib diisi',
            ], 422);
        }

        $record = DB::table('asesi_pemeliharaan')
            ->where('id_asesi', $asesi->no_pendaftaran)->where('tahun', $tahun)
            ->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Record pemeliharaan tahun ' . $tahun . ' tidak ditemukan',
            ], 404);
        }

        // Validasi per row (kontrak interface KegiatanPKbItem frontend)
        foreach ($rows as $i => $row) {
            $required = ['bentuk_kegiatan', 'tema', 'penyelenggara', 'lokasi', 'waktu'];
            foreach ($required as $f) {
                if (empty($row[$f])) {
                    return response()->json([
                        'success' => false,
                        'message' => "Baris " . ($i + 1) . ": field {$f} wajib diisi",
                    ], 422);
                }
            }
            if (!in_array($row['bentuk_kegiatan'], ['Bimtek', 'Seminar', 'Workshop', 'Lainnya'])) {
                return response()->json([
                    'success' => false,
                    'message' => "Baris " . ($i + 1) . ": bentuk kegiatan tidak valid",
                ], 422);
            }
            if ($row['bentuk_kegiatan'] === 'Lainnya' && empty($row['bentuk_lainnya'])) {
                return response()->json([
                    'success' => false,
                    'message' => "Baris " . ($i + 1) . ": sebutkan bentuk kegiatan lainnya",
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            // Replace-all per tahun (idem pola revisi)
            DB::table('asesi_pemeliharaan_pkb')->where('id_pemeliharaan', $record->id)->delete();

            foreach ($rows as $i => $row) {
                $fileName = null;
                if ($request->hasFile("files.{$i}") || $request->hasFile("files_{$i}")) {
                    $file = $request->hasFile("files.{$i}")
                        ? $request->file("files.{$i}")
                        : $request->file("files_{$i}");
                    $fileName = time() . '_pkb_' . uniqid() . '.' . strtolower($file->getClientOriginalExtension());
                    $dest = public_path(self::dir());
                    if (!file_exists($dest)) mkdir($dest, 0755, true);
                    $file->move($dest, $fileName);
                }

                DB::table('asesi_pemeliharaan_pkb')->insert([
                    'id_pemeliharaan' => $record->id,
                    'bentuk_kegiatan' => $row['bentuk_kegiatan'],
                    'bentuk_lainnya' => $row['bentuk_lainnya'] ?? null,
                    'tema' => $row['tema'],
                    'penyelenggara' => $row['penyelenggara'],
                    'lokasi' => $row['lokasi'],
                    'waktu' => $row['waktu'],
                    'deskripsi_singkat' => $row['deskripsi_singkat'] ?? null,
                    'file_bukti' => $fileName,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($rows) . ' kegiatan PKB berhasil disimpan',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan PKB',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // EVALUASI 3 TAHUNAN
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /evaluasi — riwayat evaluasi 3 tahunan
     */
    public function evaluasi(Request $request): JsonResponse
    {
        [$asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        $rows = DB::table('asesi_evaluasi')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->orderBy('tahun_ke', 'asc')
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'tahun_ke' => (int) $e->tahun_ke,
                'tahun_jatuh_tempo' => (int) $e->tahun_jatuh_tempo,
                'sertifikat_no' => $e->sertifikat_no,
                'status' => $e->status,
                'status_label' => $this->statusLabel($e->status),
                'jenis_dokumen' => $e->jenis_dokumen,
                'dokumen_url' => $e->file_dokumen ? asset(self::dir() . '/' . $e->file_dokumen) : null,
                'catatan_evaluator' => $e->catatan_evaluator,
            ]);

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * POST /evaluasi/{tahunKe} — submit 1 dokumen AMDAL (multipart)
     * jenis_dokumen: cover | tim | pengesahan — file pdf max 10MB
     */
    public function submitEvaluasi(Request $request, $tahunKe): JsonResponse
    {
        [$asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'jenis_dokumen' => 'required|in:cover,tim,pengesahan',
            'file_dokumen' => 'required|file|mimes:pdf|max:10240',
        ], [
            'jenis_dokumen.required' => 'Pilih jenis dokumen AMDAL (cover/tim/pengesahan)',
            'file_dokumen.required' => 'Dokumen AMDAL wajib diunggah (PDF, maks 10MB)',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $record = DB::table('asesi_evaluasi')
            ->where('id_asesi', $asesi->no_pendaftaran)->where('tahun_ke', $tahunKe)
            ->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Record evaluasi ke-' . $tahunKe . ' belum tersedia',
            ], 404);
        }

        $derived = $this->deriveStatus($record->status, (int) $record->tahun_jatuh_tempo, true, false);
        if (!$derived['bisa_upload'] && $record->status !== 'PERLU_PERBAIKAN') {
            return response()->json([
                'success' => false,
                'message' => 'Upload tidak diizinkan pada status ' . $this->statusLabel($record->status),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $file = $request->file('file_dokumen');
            $fileName = time() . '_eval_' . $tahunKe . '_' . uniqid() . '.pdf';
            $dest = public_path(self::dir());
            if (!file_exists($dest)) mkdir($dest, 0755, true);
            $old = public_path(self::dir() . '/' . $record->file_dokumen);
            if (!empty($record->file_dokumen) && file_exists($old)) @unlink($old);
            $file->move($dest, $fileName);

            DB::table('asesi_evaluasi')->where('id', $record->id)->update([
                'jenis_dokumen' => $request->jenis_dokumen,
                'file_dokumen' => $fileName,
                'status' => 'SUDAH_UPLOAD',
                'tanggal_upload' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dokumen evaluasi berhasil dikirim',
                'data' => ['tahun_ke' => (int) $tahunKe, 'status' => 'SUDAH_UPLOAD'],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan dokumen evaluasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // LOGBOOK
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /logbook?tahun= — rows logbook per tahun (semua tahun bila kosong)
     */
    public function logbook(Request $request): JsonResponse
    {
        [$asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        $query = DB::table('asesi_logbook')
            ->where('id_asesi', $asesi->no_pendaftaran);

        if ($request->filled('tahun')) {
            $query->where('tahun', $request->tahun);
        }

        $rows = $query->orderBy('tanggal_mulai', 'desc')->get()->map(function ($r) {
            $r->file_surat_tugas_lpjp_url = $r->file_surat_tugas_lpjp
                ? asset(self::dir() . '/' . $r->file_surat_tugas_lpjp) : null;
            $r->file_referensi_pemrakarsa_url = $r->file_referensi_pemrakarsa
                ? asset(self::dir() . '/' . $r->file_referensi_pemrakarsa) : null;
            $r->file_ba_persetujuan_kpa_url = $r->file_ba_persetujuan_kpa
                ? asset(self::dir() . '/' . $r->file_ba_persetujuan_kpa) : null;
            return $r;
        });

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * POST /logbook — simpan rows logbook (multipart: rows JSON + files)
     * Validasi 10-field ATPA + 3 bukti wajib per row (pdf max 2MB).
     */
    public function storeLogbook(Request $request): JsonResponse
    {
        [$asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        $rows = $request->input('rows', []);
        if (is_string($rows)) {
            $rows = json_decode($rows, true) ?: [];
        }
        if (empty($rows) || !is_array($rows)) {
            return response()->json([
                'success' => false,
                'message' => 'Minimal 1 baris logbook wajib diisi',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $saved = 0;
            foreach ($rows as $i => $row) {
                // ── Validasi inti ──
                $required = ['tahun', 'nama_kegiatan', 'tipe_penyusun', 'nama_lpjp',
                    'telepon_email_lpjp', 'nama_pemrakarsa', 'kpa_tingkat',
                    'status_dokumen', 'jabatan', 'ahli_bidang', 'spesifikasi_tenaga_ahli'];
                foreach ($required as $f) {
                    if (empty($row[$f])) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Baris " . ($i + 1) . ": field {$f} wajib diisi",
                        ], 422);
                    }
                }

                // tipe_penyusun menentukan nomor_lpjp opsional (Perorangan → kosong)
                if (!in_array($row['tipe_penyusun'], ['LPJP', 'Perorangan'])) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Baris " . ($i + 1) . ": tipe penyusun harus LPJP atau Perorangan",
                    ], 422);
                }

                // DISETUJUI → nomor/tanggal/tahun persetujuan wajib
                if ($row['status_dokumen'] === 'DISETUJUI') {
                    foreach (['nomor_persetujuan', 'tanggal_persetujuan', 'tahun_persetujuan'] as $f) {
                        if (empty($row[$f])) {
                            DB::rollBack();
                            return response()->json([
                                'success' => false,
                                'message' => "Baris " . ($i + 1) . ": status DISETUJUI wajib mengisi {$f}",
                            ], 422);
                        }
                    }
                }

                // ahli_bidang CSV min 1 + lainnya → ahli_bidang_lainnya wajib
                $ahli = array_filter(array_map('trim', explode(',', (string) $row['ahli_bidang'])));
                if (empty($ahli)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Baris " . ($i + 1) . ": pilih minimal 1 ahli bidang",
                    ], 422);
                }
                if (in_array('lainnya', $ahli) && empty($row['ahli_bidang_lainnya'])) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Baris " . ($i + 1) . ": sebutkan ahli bidang lainnya",
                    ], 422);
                }

                // ── 3 bukti wajib per row (pdf max 2MB) ──
                $buktiFields = [
                    'file_surat_tugas_lpjp',
                    'file_referensi_pemrakarsa',
                    'file_ba_persetujuan_kpa',
                ];
                $buktiData = [];
                foreach ($buktiFields as $bf) {
                    if (!$request->hasFile("files.{$i}.{$bf}")) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Baris " . ($i + 1) . ": bukti {$bf} wajib diunggah (PDF, maks 2MB)",
                        ], 422);
                    }
                    $file = $request->file("files.{$i}.{$bf}");
                    $ext = strtolower($file->getClientOriginalExtension());
                    if ($ext !== 'pdf') {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Baris " . ($i + 1) . ": bukti {$bf} harus PDF",
                        ], 422);
                    }
                    $fileName = time() . '_logbook_' . uniqid() . '.pdf';
                    $dest = public_path(self::dir());
                    if (!file_exists($dest)) mkdir($dest, 0755, true);
                    $file->move($dest, $fileName);
                    $buktiData[$bf] = $fileName;
                }

                // Insert row
                DB::table('asesi_logbook')->insert(array_merge([
                    'id_asesi' => $asesi->no_pendaftaran,
                    'tahun' => (int) $row['tahun'],
                    'nama_kegiatan' => $row['nama_kegiatan'],
                    'lokasi_kegiatan' => $row['lokasi_kegiatan'] ?? null,
                    'tanggal_mulai' => $row['tanggal_mulai'] ?? null,
                    'tanggal_selesai' => $row['tanggal_selesai'] ?? null,
                    'tipe_penyusun' => $row['tipe_penyusun'],
                    'nama_lpjp' => $row['nama_lpjp'],
                    'alamat_lpjp' => $row['alamat_lpjp'] ?? null,
                    'nomor_lpjp' => $row['nomor_lpjp'] ?? null,
                    'telepon_email_lpjp' => $row['telepon_email_lpjp'],
                    'nama_pemrakarsa' => $row['nama_pemrakarsa'],
                    'alamat_pemrakarsa' => $row['alamat_pemrakarsa'] ?? null,
                    'telepon_pemrakarsa' => $row['telepon_pemrakarsa'] ?? null,
                    'kpa_tingkat' => $row['kpa_tingkat'],
                    'kpa_wilayah' => $row['kpa_wilayah'] ?? null,
                    'kpa_alamat' => $row['kpa_alamat'] ?? null,
                    'kpa_telepon' => $row['kpa_telepon'] ?? null,
                    'status_dokumen' => $row['status_dokumen'],
                    'nomor_persetujuan' => $row['nomor_persetujuan'] ?? null,
                    'tanggal_persetujuan' => $row['tanggal_persetujuan'] ?? null,
                    'tahun_persetujuan' => $row['tahun_persetujuan'] ?? null,
                    'jabatan' => $row['jabatan'],
                    'ahli_bidang' => implode(',', $ahli),
                    'ahli_bidang_lainnya' => $row['ahli_bidang_lainnya'] ?? null,
                    'spesifikasi_tenaga_ahli' => $row['spesifikasi_tenaga_ahli'],
                    'waktu' => now(),
                ], $buktiData));

                $saved++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $saved . ' logbook kegiatan berhasil disimpan',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan logbook',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // NOTIFIKASI
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /notifikasi — daftar notifikasi + belum_dibaca
     */
    public function notifikasi(Request $request): JsonResponse
    {
        [$asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        $rows = DB::table('asesi_notifikasi')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->orderBy('waktu', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'belum_dibaca' => $rows->where('dibaca', 0)->count(),
                'daftar' => $rows->map(fn ($n) => [
                    'id' => $n->id,
                    'tipe' => $n->tipe,
                    'judul' => $n->judul,
                    'pesan' => $n->pesan,
                    'kategori' => $n->kategori,
                    'tanggal' => $n->waktu ? date('Y-m-d', strtotime($n->waktu)) : null,
                    'dibaca' => (bool) $n->dibaca,
                ]),
            ],
        ]);
    }

    /**
     * POST /notifikasi/tandai-dibaca { id? } — tanpa id = tandai semua
     */
    public function tandaiDibaca(Request $request): JsonResponse
    {
        [$asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        $query = DB::table('asesi_notifikasi')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->where('dibaca', 0);

        if ($request->filled('id')) {
            $query->where('id', $request->id);
        }

        $updated = $query->update(['dibaca' => 1, 'waktu_dibaca' => now()]);

        return response()->json([
            'success' => true,
            'message' => $updated . ' notifikasi ditandai dibaca',
            'data' => ['updated' => $updated],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    private function dir(): string
    {
        return self::UPLOAD_DIR;
    }

    /**
     * Resolve peserta dari token (pola /peserta/dashboard).
     */
    private function resolvePeserta(Request $request): array
    {
        $user = $request->user();
        if (!$user) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401)];
        }
        if (!$user->isPeserta()) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus peserta.',
            ], 403)];
        }

        $asesi = Asesi::where('no_ktp', $user->no_ktp)
            ->orWhere('nohp', $user->no_telp)
            ->first();

        if (!$asesi) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Profil peserta belum lengkap.',
            ], 404)];
        }

        return [$asesi, null];
    }

    /**
     * Lazy-create records (§7 fallback): pemeliharaan tahun berjalan +
     * evaluasi berikutnya utk sertifikat aktif.
     */
    private function ensureRecords(string $noPendaftaran, string $sertifikatNo, $asesmen): void
    {
        $tahunIni = (int) now()->year;

        // Pemeliharaan tahun berjalan
        $exists = DB::table('asesi_pemeliharaan')
            ->where('id_asesi', $noPendaftaran)->where('tahun', $tahunIni)->exists();
        if (!$exists) {
            DB::table('asesi_pemeliharaan')->insert([
                'id_asesi' => $noPendaftaran,
                'id_asesmen' => $asesmen->id,
                'sertifikat_no' => $sertifikatNo,
                'tahun' => $tahunIni,
                'status' => 'BELUM',
                'waktu' => now(),
            ]);
        }

        // Evaluasi berikutnya (masa_berlaku - 3 tahun); buat hingga tahun depan
        if (!empty($asesmen->masa_berlaku)) {
            $masaBerlaku = (int) date('Y', strtotime($asesmen->masa_berlaku));
            $tahunEvaluasi = $masaBerlaku - 3;
            if ($tahunEvaluasi > 2000) {
                $tahunKe = max(1, $masaBerlaku - 2024);   // evaluasi pertama ~3 thn setelah terbit
                $existsE = DB::table('asesi_evaluasi')
                    ->where('id_asesi', $noPendaftaran)->where('tahun_ke', $tahunKe)->exists();
                if (!$existsE) {
                    DB::table('asesi_evaluasi')->insert([
                        'id_asesi' => $noPendaftaran,
                        'sertifikat_no' => $sertifikatNo,
                        'tahun_ke' => $tahunKe,
                        'tahun_jatuh_tempo' => $tahunEvaluasi,
                        'status' => 'BELUM_UPLOAD',
                        'waktu' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Derive status (§3.2) — evaluasi berurutan per record.
     * Return ['status' => ..., 'bisa_upload' => bool]
     */
    private function deriveStatus($storedStatus, int $tahun, bool $isEvaluasi, bool $adaFile): array
    {
        $today = now()->startOfDay();
        $tempo = \Carbon\Carbon::create($tahun, 12, 31)->startOfDay();
        $bulanMenujuTempo = $today->diffInMonths($tempo, false);   // negatif = sudah lewat

        // 1. Evaluasi: hari ini < tahun tempo → BELUM_WAKTUNYA
        if ($isEvaluasi && $today->year < $tahun) {
            return ['status' => 'BELUM_WAKTUNYA', 'bisa_upload' => false];
        }

        // 2. Sudah DISETUJUI / DITOLAK → tetap (kecuali TERLAMBAT override tak relevan)
        if ($storedStatus === 'DISETUJUI') {
            return ['status' => 'DISETUJUI', 'bisa_upload' => false];
        }

        // 3. Ada file (sudah upload) & hari ini ≤ tempo → SUDAH_UPLOAD
        if ($adaFile && $today->lte($tempo)) {
            return ['status' => 'SUDAH_UPLOAD', 'bisa_upload' => false];
        }

        // 4. PERLU_PERBAIKAN tetap sampai upload ulang
        if ($storedStatus === 'PERLU_PERBAIKAN') {
            return ['status' => 'PERLU_PERBAIKAN', 'bisa_upload' => true];
        }

        // 5. Lewat tempo & belum DISETUJUI → TERLAMBAT (override)
        if ($today->gt($tempo)) {
            if ($storedStatus === 'DITOLAK') {
                return ['status' => 'DITOLAK', 'bisa_upload' => false];
            }
            if ($storedStatus === 'DALAM_EVALUASI') {
                return ['status' => 'DALAM_EVALUASI', 'bisa_upload' => false];
            }
            return ['status' => 'TERLAMBAT', 'bisa_upload' => true];
        }

        // 6. ≤ 3 bulan sebelum tempo → AKAN_JATUH_TEMPO
        if ($bulanMenujuTempo !== false && $bulanMenujuTempo <= self::BATAS_AKAN_JATUH_TEMPO_BULAN) {
            return ['status' => 'AKAN_JATUH_TEMPO', 'bisa_upload' => true];
        }

        // 7. Belum ada file → BELUM / BELUM_UPLOAD
        if (!$adaFile) {
            return ['status' => $isEvaluasi ? 'BELUM_UPLOAD' : 'BELUM', 'bisa_upload' => true];
        }

        return ['status' => 'SUDAH_UPLOAD', 'bisa_upload' => false];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'BELUM_WAKTUNYA' => 'Belum Waktunya',
            'AKAN_JATUH_TEMPO' => 'Akan Jatuh Tempo',
            'BELUM', 'BELUM_UPLOAD' => 'Belum Upload',
            'SUDAH_UPLOAD' => 'Menunggu Evaluasi',
            'DALAM_EVALUASI' => 'Dalam Evaluasi',
            'PERLU_PERBAIKAN' => 'Perlu Perbaikan',
            'DISETUJUI' => 'Disetujui',
            'DITOLAK' => 'Ditolak',
            'TERLAMBAT' => 'Terlambat',
            default => ucfirst(strtolower($status)),
        };
    }

    private function statusMasaBerlaku($masaBerlaku): string
    {
        if (empty($masaBerlaku) || $masaBerlaku === '0000-00-00') {
            return 'AKTIF';
        }
        $bulan = now()->startOfDay()->diffInMonths(\Carbon\Carbon::parse($masaBerlaku)->startOfDay(), false);
        if ($bulan !== null && $bulan < 0) return 'KADALUARSA';
        if ($bulan !== null && $bulan <= 2) return 'KADALUARSA';   // ≤2 bln → merah
        if ($bulan !== null && $bulan <= 6) return 'SEGERA BERAKHIR'; // ≤6 bln → kuning
        return 'AKTIF';
    }

    private function fmtDateTime($dt): ?string
    {
        return $dt ? date('Y-m-d H:i', strtotime($dt)) : null;
    }
}
