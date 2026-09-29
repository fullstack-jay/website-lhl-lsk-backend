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

        $verifDok = is_array($asesi->verifikasi_dokumen)
            ? $asesi->verifikasi_dokumen
            : (is_string($asesi->verifikasi_dokumen) ? (json_decode($asesi->verifikasi_dokumen, true) ?: []) : []);

        $hasVerifiedSertifikat = ($asesi->status_sertifikat === 'VALID');

        if (!$sertifikatAsesmen && !$hasVerifiedSertifikat) {
            return response()->json([
                'success' => true,
                'data' => [
                    'sertifikat' => [
                        'ada' => false,
                        'status_sertifikat' => $asesi->status_sertifikat ?: 'BELUM_UPLOAD',
                        'catatan_sertifikat' => $asesi->catatan_sertifikat,
                        'no_sertifikat' => $asesi->no_sertifikat,
                        'jenis_sertifikat' => $asesi->jenis_sertifikat,
                    ],
                    'summary' => null,
                    'pemeliharaan' => [],
                    'evaluasi' => [],
                    'logbook_tahun_tersedia' => [],
                    'notifikasi' => ['belum_dibaca' => 0, 'daftar' => []],
                ],
            ]);
        }

        $currentYear = (int) now()->year;
        $sertifikatNo = $sertifikatAsesmen?->no_serisertifikat
            ?: ($asesi->no_sertifikat ?: ('SERT/' . $currentYear . '/' . ($asesi->jenis_sertifikat ?: 'ATPA') . '/' . substr($asesi->no_pendaftaran, -4)));

        $tglTerbit = $this->resolveTanggalSertifikat($asesi, $sertifikatAsesmen);
        $carbonTerbit = \Carbon\Carbon::parse($tglTerbit)->startOfDay();
        $today = now()->startOfDay();

        // Auto-create pemeliharaan 5 tahun + evaluasi berikutnya (lazy §7)
        $this->ensureRecords($asesi->no_pendaftaran, $sertifikatNo, $sertifikatAsesmen, $asesi, $tglTerbit);

        // ── Pemeliharaan + derive status berdasarkan periode kronologis ──
        $pemeliharaanRecords = DB::table('asesi_pemeliharaan')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->orderBy('tahun', 'asc')
            ->get();

        $processedPemeliharaan = [];
        $approvedYearsMap = [];

        foreach ($pemeliharaanRecords as $p) {
            $tahunInt = (int) $p->tahun;
            $periode = $this->calculatePeriodePemeliharaan($tglTerbit, $tahunInt);
            $mulaiCarbon = \Carbon\Carbon::parse($periode['tanggal_mulai'])->startOfDay();
            $tempoCarbon = \Carbon\Carbon::parse($periode['tanggal_jatuh_tempo'])->startOfDay();

            $isApproved = ($p->status === 'DISETUJUI' || $p->status_pkb === 'DISETUJUI' || (!empty($p->ttd_rusdani) && !empty($p->ttd_nina)));
            $adaFile = !empty($p->tanggal_upload) || !empty($p->file_penunjukan) || !empty($p->file_logbook);

            $derivedStatus = $p->status;
            $bisaUpload = true;
            $pesanKunci = null;
            $keteranganPemenuhan = null;

            if ($isApproved) {
                $derivedStatus = 'DISETUJUI';
                $bisaUpload = false;
                $keteranganPemenuhan = "Pemeliharaan masih memenuhi untuk {$periode['tahun_ke_label']}";
                $approvedYearsMap[$tahunInt] = true;

                if ($p->status !== 'DISETUJUI') {
                    DB::table('asesi_pemeliharaan')->where('id', $p->id)->update(['status' => 'DISETUJUI']);
                    $p->status = 'DISETUJUI';
                }
            } else {
                $approvedYearsMap[$tahunInt] = false;

                if ($periode['tahun_ke'] > 1) {
                    $prevTahun = $tahunInt - 1;
                    $isPrevApproved = !empty($approvedYearsMap[$prevTahun]);
                    $prevLabel = $this->calculatePeriodePemeliharaan($tglTerbit, $prevTahun)['tahun_ke_label'];

                    if ($isPrevApproved) {
                        // Tahun sebelumnya sudah di-ACC: tahun ini terbuka untuk pengajuan pemeliharaan
                        $derived = $this->deriveStatusWithDates($p->status, $tempoCarbon, false, $adaFile, null);
                        $derivedStatus = $derived['status'];
                        $bisaUpload = $derived['bisa_upload'];
                        $pesanKunci = null;
                    } else {
                        // Tahun sebelumnya belum di-ACC
                        $derivedStatus = 'BELUM_WAKTUNYA';
                        $bisaUpload = false;
                        $pesanKunci = "Harap selesaikan kewajiban pemeliharaan {$prevLabel} terlebih dahulu.";
                    }
                } else {
                    // Tahun Pertama
                    $derived = $this->deriveStatusWithDates($p->status, $tempoCarbon, false, $adaFile, $mulaiCarbon);
                    $derivedStatus = $derived['status'];
                    $bisaUpload = $derived['bisa_upload'];
                }

                if ($derivedStatus !== $p->status && in_array($derivedStatus, ['DISETUJUI', 'SUDAH_UPLOAD', 'BELUM_WAKTUNYA', 'AKAN_JATUH_TEMPO', 'TERLAMBAT', 'BELUM'])) {
                    DB::table('asesi_pemeliharaan')->where('id', $p->id)->update(['status' => $derivedStatus]);
                    $p->status = $derivedStatus;
                }
            }

            $processedPemeliharaan[] = [
                'id' => $p->id,
                'tahun' => $tahunInt,
                'tahun_ke' => $periode['tahun_ke'],
                'tahun_ke_label' => $periode['tahun_ke_label'],
                'sertifikat_no' => $p->sertifikat_no,
                'status' => $derivedStatus,
                'status_label' => $this->statusLabel($derivedStatus),
                'status_pkb' => $p->status_pkb ?: ($isApproved ? 'DISETUJUI' : 'BELUM'),
                'catatan_pkb' => $p->catatan_pkb,
                'ttd_rusdani' => $p->ttd_rusdani,
                'tgl_ttd_rusdani' => $p->tgl_ttd_rusdani,
                'ttd_nina' => $p->ttd_nina,
                'tgl_ttd_nina' => $p->tgl_ttd_nina,
                'tanggal_mulai' => $periode['tanggal_mulai'],
                'tanggal_jatuh_tempo' => $periode['tanggal_jatuh_tempo'],
                'tanggal_upload' => $this->fmtDateTime($p->tanggal_upload),
                'tanggal_evaluasi' => $this->fmtDateTime($p->tanggal_evaluasi),
                'catatan_evaluator' => $p->catatan_evaluator,
                'keterangan_pemenuhan' => $keteranganPemenuhan,
                'pesan_kunci' => $pesanKunci,
                'dokumen' => [
                    'penunjukan' => $p->file_penunjukan ? asset(self::dir() . '/' . $p->file_penunjukan) : null,
                    'logbook' => $p->file_logbook ? asset(self::dir() . '/' . $p->file_logbook) : null,
                    'cover_tim' => $p->file_cover_tim ? asset(self::dir() . '/' . $p->file_cover_tim) : null,
                    'ka_andal' => $p->file_ka_andal ? asset(self::dir() . '/' . $p->file_ka_andal) : null,
                    'link_dokumen_lengkap' => $p->link_dokumen_lengkap ?? null,
                ],
                'link_dokumen_lengkap' => $p->link_dokumen_lengkap ?? null,
                'bisa_upload' => $bisaUpload,
            ];
        }

        // Tampilkan pemeliharaan terurut tahun asc (atau desc)
        $pemeliharaan = collect($processedPemeliharaan)->sortBy('tahun')->values()->all();

        // ── Evaluasi + derive status (Jatuh tempo 3 tahun dari terbit) ──
        $tglEvaluasiTempo = $carbonTerbit->copy()->addYears(3)->toDateString();
        $evaluasi = DB::table('asesi_evaluasi')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->orderBy('tahun_ke', 'asc')
            ->get()
            ->map(function ($e) use ($carbonTerbit, $today, $tglEvaluasiTempo) {
                $evalTempoCarbon = \Carbon\Carbon::parse($tglEvaluasiTempo)->startOfDay();
                $derived = $this->deriveStatusWithDates($e->status, $evalTempoCarbon, true, !empty($e->file_dokumen));
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
                    'tanggal_jatuh_tempo' => $tglEvaluasiTempo,
                    'tanggal_upload' => $this->fmtDateTime($e->tanggal_upload),
                    'jenis_dokumen' => $e->jenis_dokumen,
                    'dokumen_url' => $e->file_dokumen ? asset(self::dir() . '/' . $e->file_dokumen) : null,
                    'catatan_evaluator' => $e->catatan_evaluator,
                    'bisa_upload' => $derived['bisa_upload'],
                ];
            })->all();

        // ── Summary cards (derivasi §5) ──
        // Periode berjalan saat ini (rentang tanggal_mulai <= hari ini <= tanggal_jatuh_tempo)
        $currentPem = collect($processedPemeliharaan)->first(function ($item) use ($today) {
            $mulai = \Carbon\Carbon::parse($item['tanggal_mulai'])->startOfDay();
            $tempo = \Carbon\Carbon::parse($item['tanggal_jatuh_tempo'])->startOfDay();
            return $item['status'] !== 'DISETUJUI' && $today->gte($mulai) && $today->lte($tempo);
        }) ?? collect($processedPemeliharaan)->first(fn ($item) => $item['status'] !== 'DISETUJUI')
           ?? collect($processedPemeliharaan)->first();

        $jatuhTempoBerikutnya = $currentPem['tanggal_jatuh_tempo'] ?? $carbonTerbit->copy()->addYears(1)->toDateString();

        $summary = [
            'pemeliharaan_aktif_tahun' => $currentPem['tahun'] ?? $carbonTerbit->year,
            'tahun_ke_label' => $currentPem['tahun_ke_label'] ?? 'Tahun Pertama',
            'status_pemeliharaan' => $currentPem['status'] ?? 'BELUM',
            'status_pemeliharaan_label' => $currentPem['status_label'] ?? 'Belum Upload',
            'keterangan_pemenuhan' => $currentPem['keterangan_pemenuhan'] ?? ($currentPem['status'] === 'DISETUJUI' ? "Pemeliharaan masih memenuhi untuk {$currentPem['tahun_ke_label']}" : null),
            'jatuh_tempo_berikutnya' => $jatuhTempoBerikutnya,
            'evaluasi_berikutnya_tahun' => $carbonTerbit->copy()->addYears(3)->year,
            'tanggal_jatuh_tempo_evaluasi' => $tglEvaluasiTempo,
        ];

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

        $masaBerlaku = $sertifikatAsesmen?->masa_berlaku 
            ?: ($asesi->masa_berlaku_sertifikat ? date('Y-m-d', strtotime($asesi->masa_berlaku_sertifikat)) : date('Y-m-d', strtotime('+5 years')));
        $fotoSertifikatUrl = !empty($sertifikatAsesmen?->foto_sertifikat)
            ? asset('foto_sertifikat/' . $sertifikatAsesmen->foto_sertifikat)
            : ($asesi->sertifikat_atpa_ktpa ? asset('storage/foto_asesi/' . $asesi->sertifikat_atpa_ktpa) : null);

        return response()->json([
            'success' => true,
            'data' => [
                'sertifikat' => [
                    'ada' => true,
                    'nama_peserta' => $asesi->nama ?: ($asesi->nama_lengkap ?: ($request->user()?->nama_lengkap ?: 'Peserta LSK')),
                    'nik' => $asesi->no_ktp ?: ($request->user()?->no_ktp ?: '-'),
                    'jenis_sertifikat' => $asesi->jenis_sertifikat ?: 'ATPA',
                    'no_sertifikat' => $sertifikatNo,
                    'tgl_sertifikat' => $tglTerbit,
                    'masa_berlaku' => $masaBerlaku,
                    'foto_sertifikat_url' => $fotoSertifikatUrl,
                    'status_masa_berlaku' => $this->statusMasaBerlaku($masaBerlaku),
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

        $tglTerbit = $this->resolveTanggalSertifikat($asesi);
        $today = now()->startOfDay();

        // Evaluasi approval dan metadata per tahun berurutan
        $allRecords = DB::table('asesi_pemeliharaan')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->orderBy('tahun', 'asc')
            ->get();

        $approvedMap = [];
        $metaPerTahun = [];
        foreach ($allRecords as $item) {
            $thInt = (int) $item->tahun;
            $periode = $this->calculatePeriodePemeliharaan($tglTerbit, $thInt);
            $mulaiCarbon = \Carbon\Carbon::parse($periode['tanggal_mulai'])->startOfDay();
            $tempoCarbon = \Carbon\Carbon::parse($periode['tanggal_jatuh_tempo'])->startOfDay();
            $isApp = ($item->status === 'DISETUJUI' || $item->status_pkb === 'DISETUJUI' || (!empty($item->ttd_rusdani) && !empty($item->ttd_nina)));
            $approvedMap[$thInt] = $isApp;

            $bisaUpload = true;
            $pesanKunci = null;
            $keteranganPemenuhan = null;
            $derivedStatus = $item->status;

            if ($isApp) {
                $derivedStatus = 'DISETUJUI';
                $bisaUpload = false;
                $keteranganPemenuhan = "Pemeliharaan masih memenuhi untuk {$periode['tahun_ke_label']}";
            } else {
                if ($periode['tahun_ke'] > 1) {
                    $prevTahun = $thInt - 1;
                    $isPrevApp = !empty($approvedMap[$prevTahun]);
                    $prevLabel = $this->calculatePeriodePemeliharaan($tglTerbit, $prevTahun)['tahun_ke_label'];
                    if ($isPrevApp) {
                        // Tahun sebelumnya sudah di-ACC: tahun ini terbuka untuk pengajuan pemeliharaan
                        $pesanKunci = null;
                    } else {
                        $derivedStatus = 'BELUM_WAKTUNYA';
                        $bisaUpload = false;
                        $pesanKunci = "Harap selesaikan kewajiban pemeliharaan {$prevLabel} terlebih dahulu.";
                    }
                }
            }

            $metaPerTahun[$thInt] = [
                'periode' => $periode,
                'status' => $derivedStatus,
                'bisa_upload' => $bisaUpload,
                'pesan_kunci' => $pesanKunci,
                'keterangan_pemenuhan' => $keteranganPemenuhan,
                'is_approved' => $isApp,
            ];
        }

        $rows = DB::table('asesi_pemeliharaan as p')
            ->leftJoin('asesi_pemeliharaan_pkb as pk', 'pk.id_pemeliharaan', '=', 'p.id')
            ->where('p.id_asesi', $asesi->no_pendaftaran)
            ->orderBy('p.tahun', 'desc')
            ->get([
                'p.id', 'p.tahun', 'p.status', 'p.file_penunjukan', 'p.file_logbook',
                'p.file_cover_tim', 'p.link_dokumen_lengkap', 'p.file_ka_andal',
                'p.ttd_rusdani', 'p.tgl_ttd_rusdani', 'p.ttd_nina', 'p.tgl_ttd_nina', 'p.status_pkb', 'p.catatan_pkb',
                'pk.id as pkb_id', 'pk.bentuk_kegiatan', 'pk.bentuk_lainnya', 'pk.tema',
                'pk.penyelenggara', 'pk.lokasi', 'pk.waktu as pkb_waktu',
                'pk.deskripsi_singkat', 'pk.file_bukti',
            ]);

        // Group PKB rows per pemeliharaan
        $grouped = [];
        foreach ($rows as $r) {
            $thInt = (int) $r->tahun;
            $meta = $metaPerTahun[$thInt] ?? null;
            $periode = $meta['periode'] ?? $this->calculatePeriodePemeliharaan($tglTerbit, $thInt);
            $effectiveStatus = $meta['status'] ?? $r->status;

            if (!isset($grouped[$r->id])) {
                $grouped[$r->id] = [
                    'id' => $r->id,
                    'tahun' => $thInt,
                    'tahun_ke' => $periode['tahun_ke'],
                    'tahun_ke_label' => $periode['tahun_ke_label'],
                    'status' => $effectiveStatus,
                    'status_label' => $this->statusLabel($effectiveStatus),
                    'status_pkb' => $r->status_pkb ?: ($meta['is_approved'] ?? false ? 'DISETUJUI' : 'BELUM'),
                    'catatan_pkb' => $r->catatan_pkb,
                    'ttd_rusdani' => $r->ttd_rusdani,
                    'tgl_ttd_rusdani' => $r->tgl_ttd_rusdani,
                    'ttd_nina' => $r->ttd_nina,
                    'tgl_ttd_nina' => $r->tgl_ttd_nina,
                    'tanggal_mulai' => $periode['tanggal_mulai'],
                    'tanggal_jatuh_tempo' => $periode['tanggal_jatuh_tempo'],
                    'keterangan_pemenuhan' => $meta['keterangan_pemenuhan'] ?? null,
                    'pesan_kunci' => $meta['pesan_kunci'] ?? null,
                    'bisa_upload' => $meta['bisa_upload'] ?? true,
                    'dokumen' => [
                        'penunjukan' => $r->file_penunjukan ? asset(self::dir() . '/' . $r->file_penunjukan) : null,
                        'logbook' => $r->file_logbook ? asset(self::dir() . '/' . $r->file_logbook) : null,
                        'cover_tim' => $r->file_cover_tim ? asset(self::dir() . '/' . $r->file_cover_tim) : null,
                        'ka_andal' => $r->file_ka_andal ? asset(self::dir() . '/' . $r->file_ka_andal) : null,
                        'link_dokumen_lengkap' => $r->link_dokumen_lengkap ?? null,
                    ],
                    'link_dokumen_lengkap' => $r->link_dokumen_lengkap ?? null,
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
            'link_dokumen_lengkap' => 'nullable|string|max:1000',
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
        if (!$asesmen && $asesi->status_sertifikat !== 'VALID' && empty($asesi->no_sertifikat)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum memiliki sertifikat aktif',
            ], 422);
        }

        $tglTerbit = $this->resolveTanggalSertifikat($asesi, $asesmen);
        $periode = $this->calculatePeriodePemeliharaan($tglTerbit, (int) $tahun);
        $mulaiCarbon = \Carbon\Carbon::parse($periode['tanggal_mulai'])->startOfDay();

        // Guard: Jika tahun > 1 dan hari ini belum mencapai tanggal mulai
        if ($periode['tahun_ke'] > 1) {
            $prevTahun = (int) $tahun - 1;
            $prevPem = DB::table('asesi_pemeliharaan')
                ->where('id_asesi', $asesi->no_pendaftaran)
                ->where('tahun', $prevTahun)
                ->first();
            $prevApproved = $prevPem && ($prevPem->status === 'DISETUJUI' || $prevPem->status_pkb === 'DISETUJUI' || (!empty($prevPem->ttd_rusdani) && !empty($prevPem->ttd_nina)));

            if ($prevApproved && now()->startOfDay()->lt($mulaiCarbon)) {
                $prevLabel = $this->calculatePeriodePemeliharaan($tglTerbit, $prevTahun)['tahun_ke_label'];
                $tglMulaiIndo = $this->formatTanggalIndo($periode['tanggal_mulai']);
                return response()->json([
                    'success' => false,
                    'message' => "Pemeliharaan masih memenuhi untuk {$prevLabel}. Pengajuan Pemeliharaan {$periode['tahun_ke_label']} baru dapat dilakukan mulai {$tglMulaiIndo} (setelah periode {$prevLabel} selesai).",
                ], 422);
            }

            if (!$prevApproved) {
                $prevLabel = $this->calculatePeriodePemeliharaan($tglTerbit, $prevTahun)['tahun_ke_label'];
                return response()->json([
                    'success' => false,
                    'message' => "Harap selesaikan kewajiban pemeliharaan {$prevLabel} terlebih dahulu.",
                ], 422);
            }
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
        $tempoCarbon = \Carbon\Carbon::parse($periode['tanggal_jatuh_tempo'])->startOfDay();
        $derived = $this->deriveStatusWithDates($record->status, $tempoCarbon, false, false, $mulaiCarbon);
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

            $link = $request->input('link_dokumen_lengkap', $request->input('link_cover_tim'));
            if ($link !== null) {
                $updates['link_dokumen_lengkap'] = $link;
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

        $tglTerbit = $this->resolveTanggalSertifikat($asesi);
        $periode = $this->calculatePeriodePemeliharaan($tglTerbit, (int) $tahun);
        $mulaiCarbon = \Carbon\Carbon::parse($periode['tanggal_mulai'])->startOfDay();

        // Guard: Jika tahun > 1 dan periode tahun berjalan masih memenuhi (belum jatuh tempo)
        if ($periode['tahun_ke'] > 1) {
            $prevTahun = (int) $tahun - 1;
            $prevPem = DB::table('asesi_pemeliharaan')
                ->where('id_asesi', $asesi->no_pendaftaran)
                ->where('tahun', $prevTahun)
                ->first();
            $prevApproved = $prevPem && ($prevPem->status === 'DISETUJUI' || $prevPem->status_pkb === 'DISETUJUI' || (!empty($prevPem->ttd_rusdani) && !empty($prevPem->ttd_nina)));

            if ($prevApproved && now()->startOfDay()->lt($mulaiCarbon)) {
                $prevLabel = $this->calculatePeriodePemeliharaan($tglTerbit, $prevTahun)['tahun_ke_label'];
                $tglMulaiIndo = $this->formatTanggalIndo($periode['tanggal_mulai']);
                return response()->json([
                    'success' => false,
                    'message' => "Pemeliharaan masih memenuhi untuk {$prevLabel}. Pengajuan Form PKB {$periode['tahun_ke_label']} baru dapat dilakukan mulai {$tglMulaiIndo} (setelah periode {$prevLabel} selesai).",
                ], 422);
            }

            if (!$prevApproved) {
                $prevLabel = $this->calculatePeriodePemeliharaan($tglTerbit, $prevTahun)['tahun_ke_label'];
                return response()->json([
                    'success' => false,
                    'message' => "Harap selesaikan kewajiban pemeliharaan {$prevLabel} terlebih dahulu.",
                ], 422);
            }
        }

        $record = DB::table('asesi_pemeliharaan')
            ->where('id_asesi', $asesi->no_pendaftaran)->where('tahun', $tahun)
            ->first();

        if (!$record) {
            $pemId = DB::table('asesi_pemeliharaan')->insertGetId([
                'id_asesi' => $asesi->no_pendaftaran,
                'sertifikat_no' => $asesi->no_sertifikat ?: ('SERT/' . $tahun . '/ATPA/' . substr($asesi->no_pendaftaran, -4)),
                'tahun' => (int) $tahun,
                'status' => 'BELUM',
                'waktu' => now(),
            ]);
            $record = DB::table('asesi_pemeliharaan')->where('id', $pemId)->first();
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

            // Validasi tanggal kegiatan PKB:
            // Khusus tahun kedua dst: kegiatan harus dilaksanakan pada atau setelah periode dimulai
            $waktuParsed = null;
            if (!empty($row['waktu'])) {
                try {
                    $waktuParsed = \Carbon\Carbon::parse($row['waktu'])->startOfDay();
                } catch (\Throwable $e) {}
            }

            if ($waktuParsed && $waktuParsed->lt($mulaiCarbon)) {
                $tglMulaiIndo = $this->formatTanggalIndo($periode['tanggal_mulai']);
                $prevLabel = $periode['tahun_ke'] > 1 ? $this->calculatePeriodePemeliharaan($tglTerbit, (int) $tahun - 1)['tahun_ke_label'] : 'periode sebelumnya';
                return response()->json([
                    'success' => false,
                    'message' => "Baris " . ($i + 1) . " ({$row['tema']}): Tanggal pelaksanaan ({$this->formatTanggalIndo($row['waktu'])}) tidak valid. Kegiatan untuk {$periode['tahun_ke_label']} harus dilaksanakan mulai tanggal {$tglMulaiIndo} (setelah periode {$prevLabel} selesai).",
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

                $waktuVal = now()->toDateString();
                if (!empty($row['waktu'])) {
                    try {
                        $waktuVal = \Carbon\Carbon::parse($row['waktu'])->toDateString();
                    } catch (\Throwable $e) {
                        if (preg_match('/(\d{4})/', $row['waktu'], $m)) {
                            $waktuVal = "{$m[1]}-01-01";
                        }
                    }
                }

                DB::table('asesi_pemeliharaan_pkb')->insert([
                    'id_pemeliharaan' => $record->id,
                    'bentuk_kegiatan' => $row['bentuk_kegiatan'],
                    'bentuk_lainnya' => $row['bentuk_lainnya'] ?? null,
                    'tema' => $row['tema'],
                    'penyelenggara' => $row['penyelenggara'],
                    'lokasi' => $row['lokasi'],
                    'waktu' => $waktuVal,
                    'deskripsi_singkat' => $row['deskripsi_singkat'] ?? null,
                    'file_bukti' => $fileName ?: ($row['dokumen_bukti'] ?? null),
                ]);
            }

            // Pengisian Form PKB hanya mencatat/memperbarui daftar kegiatan PKB di asesi_pemeliharaan_pkb.
            // Jangan mengubah status pemeliharaan ataupun mengisi tanggal_upload, karena upload berkas resmi
            // (penunjukan, logbook, cover_tim, ka_andal) dilakukan melalui tombol "Upload PKB" (submitPemeliharaan).
            $updatePkb = [];
            if ($record->status_pkb === 'PERLU_PERBAIKAN') {
                $updatePkb['status_pkb'] = 'REVISI_TERKIRIM';
            } elseif (empty($record->status_pkb) || $record->status_pkb === 'BELUM') {
                $updatePkb['status_pkb'] = 'TERISI';
            }
            if (!empty($updatePkb)) {
                DB::table('asesi_pemeliharaan')->where('id', $record->id)->update($updatePkb);
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

        // ── Penomoran berlanjut antar-tahun (sequential continuity) ──
        // Nomor urut dihitung kronologis atas SELURUH tahun (2026 → 2027 → dst),
        // jadi dokumen pertama tahun baru otomatis melanjutkan nomor tahun lalu.
        // Filter ?tahun= hanya menyaring tampilan, nomor urut tetap global.
        $all = DB::table('asesi_logbook')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->orderBy('tahun')
            ->orderBy('tanggal_mulai')
            ->orderBy('id')
            ->get()
            ->values();

        $rows = $all->map(function ($r, $i) {
            $r->db_id = $r->id;            // untuk edit (update in-place) & delete
            $r->nomor_urut = $i + 1;       // nomor dokumen berlanjut lintas tahun
            $r->file_surat_tugas_lpjp_url = $r->file_surat_tugas_lpjp
                ? asset(self::dir() . '/' . $r->file_surat_tugas_lpjp) : null;
            $r->file_referensi_pemrakarsa_url = $r->file_referensi_pemrakarsa
                ? asset(self::dir() . '/' . $r->file_referensi_pemrakarsa) : null;
            $r->file_ba_persetujuan_kpa_url = $r->file_ba_persetujuan_kpa
                ? asset(self::dir() . '/' . $r->file_ba_persetujuan_kpa) : null;
            return $r;
        });

        if ($request->filled('tahun')) {
            $rows = $rows->filter(fn ($r) => (int) $r->tahun === (int) $request->tahun)->values();
        }

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
            $savedIds = [];
            $filesToDelete = []; // file lama yang digantikan — dihapus setelah commit
            foreach ($rows as $i => $row) {
                // ── MODE EDIT: db_id terisi → update in-place (tanpa duplikasi) ──
                $existing = null;
                if (!empty($row['db_id'])) {
                    $existing = DB::table('asesi_logbook')
                        ->where('id', (int) $row['db_id'])
                        ->where('id_asesi', $asesi->no_pendaftaran)
                        ->first();
                    if (!$existing) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Baris " . ($i + 1) . ": logbook dengan id {$row['db_id']} tidak ditemukan",
                        ], 404);
                    }
                }

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
                    if ($request->hasFile("files.{$i}.{$bf}")) {
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
                        // edit: file baru menggantikan file lama → jadwalkan hapus setelah commit
                        if ($existing && !empty($existing->{$bf}) && $existing->{$bf} !== $fileName) {
                            $filesToDelete[] = $existing->{$bf};
                        }
                        $file->move($dest, $fileName);
                        $buktiData[$bf] = $fileName;
                    } elseif (!empty($row[$bf])) {
                        $buktiData[$bf] = basename((string) $row[$bf]);
                    } else {
                        $buktiData[$bf] = null;
                    }
                }

                // Simpan row (insert baru ATAU update in-place bila db_id terisi)
                $payload = array_merge([
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
                ], $buktiData);

                if ($existing) {
                    // EDIT: update baris yang sama; bukti lama dipertahankan bila
                    // tidak dikirim ulang dan tidak ada file baru
                    $update = $payload;
                    foreach ($buktiFields as $bf) {
                        if ($buktiData[$bf] === null && !empty($existing->{$bf})) {
                            $update[$bf] = $existing->{$bf};
                        }
                    }
                    $update['waktu'] = now();
                    DB::table('asesi_logbook')->where('id', $existing->id)->update($update);
                    $savedIds[] = (int) $existing->id;
                } else {
                    $payload['id_asesi'] = $asesi->no_pendaftaran;
                    $payload['waktu'] = now();
                    DB::table('asesi_logbook')->insert($payload);
                    $savedIds[] = (int) DB::getPdo()->lastInsertId();
                }

                $saved++;
            }

            DB::commit();

            // File lama yang digantikan dihapus setelah commit sukses
            foreach (array_unique($filesToDelete) as $f) {
                $abs = public_path(self::dir() . '/' . $f);
                if (file_exists($abs)) {
                    @unlink($abs);
                }
            }

            return response()->json([
                'success' => true,
                'message' => $saved . ' logbook kegiatan berhasil disimpan',
                'data' => ['ids' => $savedIds],
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

    /**
     * DELETE /logbook/{id} — hapus satu baris logbook milik peserta ini.
     * Nomor urut dokumen pada daftar berikutnya menyesuaikan otomatis
     * (dihitung ulang saat GET /logbook dengan sequential continuity).
     */
    public function destroyLogbook(Request $request, $id): JsonResponse
    {
        [$asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        $row = DB::table('asesi_logbook')
            ->where('id', (int) $id)
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->first();

        if (!$row) {
            return response()->json([
                'success' => false,
                'message' => 'Logbook tidak ditemukan',
            ], 404);
        }

        DB::table('asesi_logbook')->where('id', $row->id)->delete();

        // Bersihkan 3 file bukti milik baris ini
        foreach (array_filter([
            $row->file_surat_tugas_lpjp,
            $row->file_referensi_pemrakarsa,
            $row->file_ba_persetujuan_kpa,
        ]) as $f) {
            $abs = public_path(self::dir() . '/' . $f);
            if (file_exists($abs)) {
                @unlink($abs);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Logbook kegiatan berhasil dihapus',
        ]);
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
     * Resolve tanggal terbit sertifikat asesi (fallback kronologis).
     */
    private function resolveTanggalSertifikat($asesi, $sertifikatAsesmen = null): string
    {
        if (!empty($asesi->tgl_sertifikat)) {
            return date('Y-m-d', strtotime($asesi->tgl_sertifikat));
        }
        if (!empty($sertifikatAsesmen?->tgl_sertifikat)) {
            return date('Y-m-d', strtotime($sertifikatAsesmen->tgl_sertifikat));
        }
        if (!empty($asesi->masa_berlaku_sertifikat)) {
            return date('Y-m-d', strtotime('-5 years', strtotime($asesi->masa_berlaku_sertifikat)));
        }
        if (!empty($sertifikatAsesmen?->masa_berlaku)) {
            return date('Y-m-d', strtotime('-5 years', strtotime($sertifikatAsesmen->masa_berlaku)));
        }
        return now()->format('Y-m-d');
    }

    /**
     * Menghitung tanggal mulai dan tanggal jatuh tempo pemeliharaan per tahun berdasarkan tanggal terbit sertifikat:
     * - Tahun 1 (Tahun Pertama): Mulai = Tanggal Terbit, Jatuh Tempo = +1 Tahun
     * - Tahun 2 (Tahun Kedua): Mulai = +1 Tahun + 1 Hari (1 hari setelah pemeliharaan 1 tahun selesai), Jatuh Tempo = +2 Tahun
     * - Tahun 3 (Tahun Ketiga): Mulai = +2 Tahun + 1 Hari, Jatuh Tempo = +3 Tahun
     * - Tahun 4 (Tahun Keempat): Mulai = +3 Tahun + 1 Hari, Jatuh Tempo = +4 Tahun
     * - Tahun 5 (Tahun Kelima): Mulai = +4 Tahun + 1 Hari, Jatuh Tempo = +5 Tahun
     */
    private function calculatePeriodePemeliharaan(string $tglTerbit, int $tahun): array
    {
        $carbonTerbit = \Carbon\Carbon::parse($tglTerbit)->startOfDay();
        $tahunTerbit = (int) $carbonTerbit->year;
        $tahunKe = max(1, $tahun - $tahunTerbit + 1);

        $labels = [
            1 => 'Tahun Pertama',
            2 => 'Tahun Kedua',
            3 => 'Tahun Ketiga',
            4 => 'Tahun Keempat',
            5 => 'Tahun Kelima',
            6 => 'Tahun Keenam',
            7 => 'Tahun Ketujuh',
            8 => 'Tahun Kedelapan',
            9 => 'Tahun Kesembilan',
            10 => 'Tahun Kesepuluh',
        ];
        $tahunKeLabel = $labels[$tahunKe] ?? "Tahun Ke-{$tahunKe}";

        if ($tahunKe === 1) {
            $mulai = $carbonTerbit->copy()->toDateString();
            $tempo = $carbonTerbit->copy()->addYears(1)->toDateString();
        } else {
            $mulai = $carbonTerbit->copy()->addYears($tahunKe - 1)->addDays(1)->toDateString();
            $tempo = $carbonTerbit->copy()->addYears($tahunKe)->toDateString();
        }

        return [
            'tahun_ke' => $tahunKe,
            'tahun_ke_label' => $tahunKeLabel,
            'tanggal_mulai' => $mulai,
            'tanggal_jatuh_tempo' => $tempo,
            'tahun_terbit' => $tahunTerbit,
        ];
    }

    /**
     * Format tanggal Indonesia (contoh: 25 September 2027)
     */
    private function formatTanggalIndo(?string $dateStr): string
    {
        if (empty($dateStr)) return '-';
        $bulanIndo = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        try {
            $c = \Carbon\Carbon::parse($dateStr);
            $d = (int) $c->format('j');
            $m = (int) $c->format('n');
            $y = $c->format('Y');
            return "{$d} {$bulanIndo[$m]} {$y}";
        } catch (\Throwable $e) {
            return $dateStr;
        }
    }

    /**
     * Lazy-create records pemeliharaan (rolling window dinamis: selalu sedia 5 tahun aktif kedepan saat tahun sebelumnya di-ACC)
     * + evaluasi 3 tahunan berdasarkan tanggal terbit sertifikat.
     */
    private function ensureRecords(string $noPendaftaran, string $sertifikatNo, $asesmen = null, $asesi = null, ?string $tglTerbit = null): void
    {
        $carbonTerbit = \Carbon\Carbon::parse($tglTerbit ?: $this->resolveTanggalSertifikat($asesi, $asesmen))->startOfDay();
        $tahunTerbit = (int) $carbonTerbit->year;

        // Hitung berapa tahun pemeliharaan yang sudah di-ACC / DISETUJUI
        $approvedCount = DB::table('asesi_pemeliharaan')
            ->where('id_asesi', $noPendaftaran)
            ->where(function ($q) {
                $q->where('status', 'DISETUJUI')
                  ->orWhere('status_pkb', 'DISETUJUI')
                  ->orWhere(function ($sub) {
                      $sub->whereNotNull('ttd_rusdani')->whereNotNull('ttd_nina');
                  });
            })
            ->count();

        // Total tahun = minimal 5 tahun, atau jika sudah di-ACC bertambah dinamis (+5 tahun horizon)
        // Contoh: Tahun Pertama di-ACC ($approvedCount=1) -> $totalYears=6 (Tahun Keenam otomatis terbuka/dibuat)
        $totalYears = max(5, $approvedCount + 5);

        for ($k = 1; $k <= $totalYears; $k++) {
            $th = $tahunTerbit + $k - 1;
            $exists = DB::table('asesi_pemeliharaan')
                ->where('id_asesi', $noPendaftaran)->where('tahun', $th)->exists();
            if (!$exists) {
                DB::table('asesi_pemeliharaan')->insert([
                    'id_asesi' => $noPendaftaran,
                    'id_asesmen' => $asesmen?->id,
                    'sertifikat_no' => $sertifikatNo,
                    'tahun' => $th,
                    'status' => 'BELUM',
                    'waktu' => now(),
                ]);
            }
        }

        // Evaluasi 3 tahunan (jatuh tempo = tahun terbit + 3)
        $tahunEvaluasi = $tahunTerbit + 3;
        $existsE = DB::table('asesi_evaluasi')
            ->where('id_asesi', $noPendaftaran)->where('tahun_ke', 3)->exists();
        if (!$existsE) {
            DB::table('asesi_evaluasi')->insert([
                'id_asesi' => $noPendaftaran,
                'sertifikat_no' => $sertifikatNo,
                'tahun_ke' => 3,
                'tahun_jatuh_tempo' => $tahunEvaluasi,
                'status' => 'BELUM_WAKTUNYA',
                'waktu' => now(),
            ]);
        }
    }

    /**
     * Derive status dengan tanggal Carbon akurat (tempo & mulai).
     * Return ['status' => ..., 'bisa_upload' => bool]
     */
    private function deriveStatusWithDates($storedStatus, \Carbon\Carbon $tempo, bool $isEvaluasi, bool $adaFile, ?\Carbon\Carbon $mulai = null): array
    {
        $today = now()->startOfDay();

        // 1. Jika mulai ditentukan dan hari ini < mulai -> BELUM_WAKTUNYA
        if ($mulai && $today->lt($mulai)) {
            return ['status' => 'BELUM_WAKTUNYA', 'bisa_upload' => false];
        }

        // 2. Evaluasi: hari ini < tahun tempo -> BELUM_WAKTUNYA
        if ($isEvaluasi && $today->year < $tempo->year) {
            return ['status' => 'BELUM_WAKTUNYA', 'bisa_upload' => false];
        }

        // 3. Sudah DISETUJUI / DITOLAK -> tetap
        if ($storedStatus === 'DISETUJUI') {
            return ['status' => 'DISETUJUI', 'bisa_upload' => false];
        }

        // 4. Ada file (sudah upload) & hari ini <= tempo -> SUDAH_UPLOAD
        if ($adaFile && $today->lte($tempo)) {
            return ['status' => 'SUDAH_UPLOAD', 'bisa_upload' => false];
        }

        // 5. PERLU_PERBAIKAN tetap sampai upload ulang
        if ($storedStatus === 'PERLU_PERBAIKAN') {
            return ['status' => 'PERLU_PERBAIKAN', 'bisa_upload' => true];
        }

        // 6. Lewat tempo & belum DISETUJUI -> TERLAMBAT (override)
        if ($today->gt($tempo)) {
            if ($storedStatus === 'DITOLAK') {
                return ['status' => 'DITOLAK', 'bisa_upload' => false];
            }
            if ($storedStatus === 'DALAM_EVALUASI') {
                return ['status' => 'DALAM_EVALUASI', 'bisa_upload' => false];
            }
            return ['status' => 'TERLAMBAT', 'bisa_upload' => true];
        }

        // 7. <= 3 bulan sebelum tempo -> AKAN_JATUH_TEMPO
        $bulanMenujuTempo = $today->diffInMonths($tempo, false);
        if ($bulanMenujuTempo !== false && $bulanMenujuTempo >= 0 && $bulanMenujuTempo <= self::BATAS_AKAN_JATUH_TEMPO_BULAN) {
            return ['status' => 'AKAN_JATUH_TEMPO', 'bisa_upload' => true];
        }

        // 8. Belum ada file -> BELUM / BELUM_UPLOAD
        if (!$adaFile) {
            return ['status' => $isEvaluasi ? 'BELUM_UPLOAD' : 'BELUM', 'bisa_upload' => true];
        }

        return ['status' => 'SUDAH_UPLOAD', 'bisa_upload' => false];
    }

    /**
     * Fallback deriveStatus untuk pemanggilan lama.
     */
    private function deriveStatus($storedStatus, int $tahun, bool $isEvaluasi, bool $adaFile): array
    {
        $tempo = \Carbon\Carbon::create($tahun, 12, 31)->startOfDay();
        return $this->deriveStatusWithDates($storedStatus, $tempo, $isEvaluasi, $adaFile);
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
        if ($bulan !== null && $bulan <= 2) return 'KADALUARSA';   // <=2 bln -> merah
        if ($bulan !== null && $bulan <= 6) return 'SEGERA BERAKHIR'; // <=6 bln -> kuning
        return 'AKTIF';
    }

    private function fmtDateTime($dt): ?string
    {
        return $dt ? date('Y-m-d H:i', strtotime($dt)) : null;
    }
}
