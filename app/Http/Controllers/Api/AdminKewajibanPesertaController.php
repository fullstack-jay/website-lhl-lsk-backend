<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminKewajibanPesertaController extends Controller
{
    private const UPLOAD_DIR = 'foto_kewajiban';

    /**
     * GET /api/v1/admin/kewajiban-peserta
     * Mengambil daftar peserta bersertifikat beserta status kewajiban tahunan real dari database
     */
    public function index(Request $request): JsonResponse
    {
        $tahun = (int) ($request->query('tahun') ?: now()->year);
        $status = $request->query('status');
        $search = $request->query('search');
        $jenis = $request->query('jenis');

        // Peserta yang memiliki sertifikat aktif atau berstatus VALID
        $query = Asesi::query();
        $query->where(function ($q) {
            $q->where('status_sertifikat', 'VALID')
              ->orWhereNotNull('no_sertifikat');
        });

        if (!empty($jenis) && $jenis !== 'ALL') {
            $query->where('jenis_sertifikat', $jenis);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('no_pendaftaran', 'like', "%{$search}%")
                  ->orWhere('no_ktp', 'like', "%{$search}%")
                  ->orWhere('no_sertifikat', 'like', "%{$search}%");
            });
        }

        $allAsesi = $query->orderBy('id', 'desc')->get();
        $baseUrl = url('/');

        // Ambil semua record pemeliharaan untuk tahun terkait
        $pemeliharaanMap = DB::table('asesi_pemeliharaan')
            ->where('tahun', $tahun)
            ->get()
            ->keyBy('id_asesi');

        // Ambil counter PKB per pemeliharaan
        $pkbCounts = DB::table('asesi_pemeliharaan_pkb')
            ->select('id_pemeliharaan', DB::raw('count(*) as total'))
            ->groupBy('id_pemeliharaan')
            ->pluck('total', 'id_pemeliharaan');

        // Ambil counter Logbook per asesi untuk tahun terkait
        $logbookCounts = DB::table('asesi_logbook')
            ->where('tahun', $tahun)
            ->select('id_asesi', DB::raw('count(*) as total'))
            ->groupBy('id_asesi')
            ->pluck('total', 'id_asesi');

        // Ambil evaluasi map
        $evaluasiAsesiIds = DB::table('asesi_evaluasi')
            ->pluck('id_asesi')
            ->flip();

        $transformed = [];
        foreach ($allAsesi as $asesi) {
            $pem = $pemeliharaanMap->get($asesi->no_pendaftaran);
            $pemId = $pem ? $pem->id : null;
            $pemStatus = $pem ? $pem->status : 'BELUM';

            // Filter status jika dipilih
            if (!empty($status) && $status !== 'ALL') {
                if ($pemStatus !== $status) {
                    continue;
                }
            }

            $statusLabel = match ($pemStatus) {
                'DISETUJUI' => 'Disetujui',
                'SUDAH_UPLOAD' => 'Menunggu Verifikasi',
                'PERLU_PERBAIKAN' => 'Perlu Perbaikan',
                'DITOLAK' => 'Ditolak',
                default => 'Belum Upload',
            };

            $filePenunjukan = $pem ? $pem->file_penunjukan : null;
            $fileLogbook = $pem ? $pem->file_logbook : null;
            $fileCoverTim = $pem ? $pem->file_cover_tim : null;
            $fileKaAndal = $pem ? $pem->file_ka_andal : null;

            $transformed[] = [
                'id' => $asesi->id,
                'no_pendaftaran' => $asesi->no_pendaftaran,
                'nama' => $asesi->nama,
                'no_ktp' => $asesi->no_ktp,
                'nohp' => $asesi->nohp,
                'email' => $asesi->email,
                'jenis_sertifikat' => $asesi->jenis_sertifikat ?: 'ATPA',
                'no_sertifikat' => $asesi->no_sertifikat ?: 'SERT/' . $tahun . '/' . ($asesi->jenis_sertifikat ?: 'ATPA') . '/0001',
                'sertifikat_url' => $asesi->file_sertifikat_aktif ? "{$baseUrl}/storage/foto_asesi/" . $asesi->file_sertifikat_aktif : null,
                'status_sertifikat' => $asesi->status_sertifikat,

                'pemeliharaan_id' => $pemId,
                'tahun_pemeliharaan' => $tahun,
                'status_pemeliharaan' => $pemStatus,
                'status_pemeliharaan_label' => $statusLabel,
                'tanggal_jatuh_tempo' => "{$tahun}-12-31",
                'tanggal_upload_pemeliharaan' => $pem && $pem->tanggal_upload ? date('Y-m-d H:i', strtotime($pem->tanggal_upload)) : null,
                'catatan_evaluator' => $pem ? $pem->catatan_evaluator : null,
                'dokumen_pemeliharaan' => [
                    'penunjukan' => $filePenunjukan,
                    'logbook' => $fileLogbook,
                    'cover_tim' => $fileCoverTim,
                    'ka_andal' => $fileKaAndal,
                ],

                'jumlah_pkb' => $pemId && isset($pkbCounts[$pemId]) ? (int) $pkbCounts[$pemId] : 0,
                'jumlah_logbook' => isset($logbookCounts[$asesi->no_pendaftaran]) ? (int) $logbookCounts[$asesi->no_pendaftaran] : 0,
                'evaluasi_tercatat' => isset($evaluasiAsesiIds[$asesi->no_pendaftaran]),
            ];
        }

        // Statistik
        $allPem = DB::table('asesi_pemeliharaan')->where('tahun', $tahun)->get();
        $stats = [
            'total_peserta' => count($allAsesi),
            'menunggu_verifikasi' => $allPem->where('status', 'SUDAH_UPLOAD')->count(),
            'disetujui' => $allPem->where('status', 'DISETUJUI')->count(),
            'perlu_perbaikan' => $allPem->where('status', 'PERLU_PERBAIKAN')->count(),
            'belum_upload' => max(0, count($allAsesi) - $allPem->whereIn('status', ['SUDAH_UPLOAD', 'DISETUJUI', 'PERLU_PERBAIKAN', 'DITOLAK'])->count()),
        ];

                $page = max(1, (int) ($request->query('page') ?: 1));
        $perPage = max(1, (int) ($request->query('per_page') ?: 10));
        $total = count($transformed);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $sliced = array_slice($transformed, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'success' => true,
            'data' => array_values($sliced),
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage + 1),
                'to' => min($page * $perPage, $total),
            ],
            'statistics' => $stats,
        ]);
    }

    /**
     * GET /api/v1/admin/kewajiban-peserta/{noPendaftaran}
     * Mengambil detail lengkap baris PKB, baris Logbook AMDAL, dan dokumen evaluasi real
     */
    public function show(Request $request, $noPendaftaran): JsonResponse
    {
        $tahun = (int) ($request->query('tahun') ?: now()->year);

        $asesi = Asesi::where('no_pendaftaran', $noPendaftaran)
            ->orWhere('id', $noPendaftaran)
            ->first();

        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        $baseUrl = url('/');

        // 1. Data Pemeliharaan
        $pem = DB::table('asesi_pemeliharaan')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->where('tahun', $tahun)
            ->first();

        // 2. Baris PKB real
        $pkbRows = [];
        if ($pem) {
            $pkbRows = DB::table('asesi_pemeliharaan_pkb')
                ->where('id_pemeliharaan', $pem->id)
                ->orderBy('id', 'asc')
                ->get()
                ->map(function ($r) use ($baseUrl) {
                    return [
                        'id' => $r->id,
                        'bentuk_kegiatan' => $r->bentuk_kegiatan,
                        'bentuk_lainnya' => $r->bentuk_lainnya,
                        'tema' => $r->tema,
                        'penyelenggara' => $r->penyelenggara,
                        'lokasi' => $r->lokasi,
                        'waktu' => $r->waktu,
                        'deskripsi_singkat' => $r->deskripsi_singkat,
                        'file_bukti' => $r->file_bukti,
                        'file_bukti_url' => $r->file_bukti ? "{$baseUrl}/foto_kewajiban/" . $r->file_bukti : null,
                    ];
                });
        }

        // 3. Baris Logbook AMDAL real
        $logbookRows =  DB::table('asesi_logbook')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->where('tahun', $tahun)
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($lb) use ($baseUrl) {
                return [
                    'id' => $lb->id,
                    'tahun' => (int) $lb->tahun,
                    'nama_kegiatan' => $lb->nama_kegiatan,
                    'lokasi_kegiatan' => $lb->lokasi_kegiatan,
                    'tanggal_mulai' => $lb->tanggal_mulai,
                    'tanggal_selesai' => $lb->tanggal_selesai,
                    'tipe_penyusun' => $lb->tipe_penyusun,
                    'nama_lpjp' => $lb->nama_lpjp,
                    'nomor_lpjp' => $lb->nomor_lpjp,
                    'telepon_email_lpjp' => $lb->telepon_email_lpjp,
                    'nama_pemrakarsa' => $lb->nama_pemrakarsa,
                    'kpa_tingkat' => $lb->kpa_tingkat,
                    'kpa_wilayah' => $lb->kpa_wilayah,
                    'status_dokumen' => $lb->status_dokumen,
                    'nomor_persetujuan' => $lb->nomor_persetujuan,
                    'tanggal_persetujuan' => $lb->tanggal_persetujuan,
                    'tahun_persetujuan' => $lb->tahun_persetujuan,
                    'jabatan' => $lb->jabatan,
                    'ahli_bidang' => $lb->ahli_bidang,
                    'spesifikasi_tenaga_ahli' => $lb->spesifikasi_tenaga_ahli,
                    'file_surat_tugas_lpjp' => $lb->file_surat_tugas_lpjp,
                    'file_surat_tugas_lpjp_url' => $lb->file_surat_tugas_lpjp ? "{$baseUrl}/foto_kewajiban/" . $lb->file_surat_tugas_lpjp : null,
                    'file_referensi_pemrakarsa' => $lb->file_referensi_pemrakarsa,
                    'file_referensi_pemrakarsa_url' => $lb->file_referensi_pemrakarsa ? "{$baseUrl}/foto_kewajiban/" . $lb->file_referensi_pemrakarsa : null,
                    'file_ba_persetujuan_kpa' => $lb->file_ba_persetujuan_kpa,
                    'file_ba_persetujuan_kpa_url' => $lb->file_ba_persetujuan_kpa ? "{$baseUrl}/foto_kewajiban/" . $lb->file_ba_persetujuan_kpa : null,
                ];
            });

        // 4. Baris Evaluasi real
        $evaluasiRows = DB::table('asesi_evaluasi')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->orderBy('tahun_ke', 'asc')
            ->get()
            ->map(function ($e) use ($baseUrl) {
                return [
                    'id' => $e->id,
                    'tahun_ke' => (int) $e->tahun_ke,
                    'tahun_jatuh_tempo' => (int) $e->tahun_jatuh_tempo,
                    'sertifikat_no' => $e->sertifikat_no,
                    'status' => $e->status,
                    'tanggal_upload' => $e->tanggal_upload,
                    'jenis_dokumen' => $e->jenis_dokumen,
                    'dokumen_url' => $e->file_dokumen ? "{$baseUrl}/foto_kewajiban/" . $e->file_dokumen : null,
                    'catatan_evaluator' => $e->catatan_evaluator,
                ];
            });

        $statusLabel = $pem ? match ($pem->status) {
            'DISETUJUI' => 'Disetujui',
            'SUDAH_UPLOAD' => 'Menunggu Verifikasi',
            'PERLU_PERBAIKAN' => 'Perlu Perbaikan',
            'DITOLAK' => 'Ditolak',
            default => 'Belum Upload',
        } : 'Belum Upload';

        return response()->json([
            'success' => true,
            'data' => [
                'peserta' => [
                    'id' => $asesi->id,
                    'no_pendaftaran' => $asesi->no_pendaftaran,
                    'nama' => $asesi->nama,
                    'no_ktp' => $asesi->no_ktp,
                    'nohp' => $asesi->nohp,
                    'email' => $asesi->email,
                    'jenis_sertifikat' => $asesi->jenis_sertifikat ?: 'ATPA',
                    'no_sertifikat' => $asesi->no_sertifikat,
                    'status_sertifikat' => $asesi->status_sertifikat,
                    'pemeliharaan_id' => $pem ? $pem->id : null,
                    'tahun_pemeliharaan' => $tahun,
                    'status_pemeliharaan' => $pem ? $pem->status : 'BELUM',
                    'status_pemeliharaan_label' => $statusLabel,
                    'tanggal_jatuh_tempo' => "{$tahun}-12-31",
                    'tanggal_upload_pemeliharaan' => $pem ? $pem->tanggal_upload : null,
                    'catatan_evaluator' => $pem ? $pem->catatan_evaluator : null,
                    'dokumen_pemeliharaan' => [
                        'penunjukan' => $pem ? $pem->file_penunjukan : null,
                        'logbook' => $pem ? $pem->file_logbook : null,
                        'cover_tim' => $pem ? $pem->file_cover_tim : null,
                        'ka_andal' => $pem ? $pem->file_ka_andal : null,
                    ],
                ],
                'pkb_rows' => $pkbRows,
                'logbook_rows' => $logbookRows,
                'evaluasi_rows' => $evaluasiRows,
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/kewajiban-peserta/pemeliharaan/{id}/verifikasi
     * Admin melakukan verifikasi atau persetujuan pemeliharaan dokumen tahunan
     */
    public function verifikasiPemeliharaan(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:DISETUJUI,PERLU_PERBAIKAN,DITOLAK',
            'catatan' => $request->status === 'PERLU_PERBAIKAN' ? 'required|string|max:500' : 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => collect($validator->errors()->all())->first() ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Cari pemeliharaan berdasarkan ID atau no_pendaftaran
        $record = DB::table('asesi_pemeliharaan')->where('id', $id)->first();
        if (!$record) {
            $record = DB::table('asesi_pemeliharaan')
                ->where('id_asesi', $id)
                ->orderBy('tahun', 'desc')
                ->first();
        }

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Data pemeliharaan tidak ditemukan',
            ], 404);
        }

        DB::table('asesi_pemeliharaan')->where('id', $record->id)->update([
            'status' => $request->status,
            'catatan_evaluator' => $request->catatan,
            'tanggal_evaluasi' => now(),
        ]);

        // Kirim notifikasi ke peserta
        DB::table('asesi_notifikasi')->insert([
            'id_asesi' => $record->id_asesi,
            'tipe' => $request->status === 'DISETUJUI' ? 'success' : ($request->status === 'PERLU_PERBAIKAN' ? 'warning' : 'error'),
            'judul' => 'Hasil Evaluasi Pemeliharaan Tahun ' . $record->tahun,
            'pesan' => 'Status pemeliharaan sertifikat Anda telah dievaluasi: ' . $request->status . ($request->catatan ? '. Catatan: ' . $request->catatan : ''),
            'kategori' => 'pemeliharaan',
            'dibaca' => 0,
            'waktu' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status pemeliharaan tahun ' . $record->tahun . ' berhasil diperbarui menjadi ' . $request->status,
        ]);
    }
}
