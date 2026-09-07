<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesi;
use App\Models\AsesiAsesmen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Asesmen Saya (Portal Peserta) — GET /api/v1/peserta/asesmen-saya (auth:sanctum)
 * Implementasi modul `asesmen` PHP Native (content.php ROOT baris 2976–3118)
 * versi API. Sesuai docs/BACKEND_ASESMEN_PESERTA.md:
 *
 * Dashboard STATUS pendaftaran uji kompetensi — read-only:
 * - Setiap skema yang diikuti + status pipeline (state machine 2 dimensi)
 * - Matriks: status (P/A/R) × (biaya_asesmen | status_asesmen) → pesan + tombol
 * - Cek kelengkapan dokumen per skema_persyaratan (AKUMULASI — fix bug
 *   loop-overwrite native yang hanya menampilkan syarat terakhir)
 * - Mode PUPR (COUNT user_pupr > 0) mengubah tombol konfirmasi bayar
 *
 * Perbaikan atas native: akumulasi dokumen kurang, fallback status aneh,
 * resolusi identitas dari token (bukan session), guard role peserta.
 */
class AsesmenSayaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // ── Guard role (hanya peserta) ──
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }
        if (!$user->isPeserta()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus peserta.',
            ], 403);
        }

        // ── Resolve asesi (no_ktp, fallback nohp) ──
        $asesi = Asesi::where('no_ktp', $user->no_ktp)
            ->orWhere('nohp', $user->no_telp)
            ->first();

        // Mode PUPR (idem native: COUNT user_pupr)
        $modePupr = false;
        try {
            $modePupr = DB::table('user_pupr')->count() > 0;
        } catch (\Throwable $e) {
            $modePupr = false;
        }

        // ── Empty state: belum daftar skema apapun ──
        if (!$asesi) {
            return response()->json([
                'success' => true,
                'data' => [
                    'peserta' => ['nama' => $user->nama_lengkap, 'no_pendaftaran' => null],
                    'mode_pupr' => $modePupr,
                    'belum_daftar' => true,
                    'asesmen' => [],
                ],
            ]);
        }

        $rows = AsesiAsesmen::where('id_asesi', $asesi->no_pendaftaran)
            ->with(['jadwal.tuk', 'jadwal.asesor'])
            ->orderBy('id', 'desc')
            ->get();

        // ── Empty state idem native: warning + arahkan ke daftar skema ──
        if ($rows->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'peserta' => [
                        'nama' => $asesi->nama,
                        'no_pendaftaran' => $asesi->no_pendaftaran,
                    ],
                    'mode_pupr' => $modePupr,
                    'belum_daftar' => true,
                    'asesmen' => [],
                ],
            ]);
        }

        // ── Render list: state machine per baris ──
        $asesmenList = $rows->map(function ($m) use ($modePupr, $asesi) {
            return $this->transformAsesmen($m, $modePupr, $asesi);
        });

        return response()->json([
            'success' => true,
            'data' => [
                'peserta' => [
                    'nama' => $asesi->nama,
                    'no_pendaftaran' => $asesi->no_pendaftaran,
                ],
                'mode_pupr' => $modePupr,
                'belum_daftar' => false,
                'asesmen' => $asesmenList,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // STATE MACHINE 2 DIMENSI (matriks status → pesan + tombol §3)
    // ════════════════════════════════════════════════════════════════

    private function transformAsesmen(AsesiAsesmen $m, bool $modePupr, ?Asesi $asesi = null): array
    {
        // Label skema (JOIN skema_kkni)
        $skema = null;
        if (!empty($m->id_skemakkni)) {
            $s = DB::table('skema_kkni')->where('id', $m->id_skemakkni)
                ->first(['id', 'kode_skema', 'judul']);
            $skema = $s ? ['id' => (int) $s->id, 'kode_skema' => $s->kode_skema, 'judul' => $s->judul] : null;
        }

        if (!$asesi) {
            $asesi = Asesi::where('no_pendaftaran', $m->id_asesi)
                ->orWhere('id', $m->id_asesi)
                ->first();
        }

        // Cek kelengkapan dokumen persyaratan — sinkron dengan Syarat Dasar / Pokok (WAJIB)
        // yang diambil otomatis dari profil peserta & asesi_doc
        $dokumenKurang = [];
        try {
            $wajib = \App\Models\AsesiPersyaratanpokok::wajib()->aktif()->orderBy('id')->get();
            foreach ($wajib as $p) {
                $hasFile = false;
                if ($asesi) {
                    $hasFile = match ($p->shortcode) {
                        'sertifikat_amdal', 'sertifikat' => !empty($asesi->sertifikat_amdal) || !empty($asesi->sertifikat),
                        'bukti_keterlibatan', 'suket' => !empty($asesi->bukti_keterlibatan) || !empty($asesi->suket),
                        'sertifikat_kompetensi_lain', 'transkrip' => !empty($asesi->sertifikat_kompetensi_lain) || !empty($asesi->transkrip),
                        default => !empty($asesi->{$p->shortcode}),
                    };
                }

                if (!$hasFile) {
                    $hasFile = DB::table('asesi_doc')
                        ->where('id_asesi', $m->id_asesi)
                        ->whereNotNull('file')
                        ->where('file', '!=', '')
                        ->where(function ($q) use ($p) {
                            $q->where('nama_doc', 'like', '%' . $p->persyaratan . '%')
                              ->orWhere('jenis_doc', 'like', '%' . $p->persyaratan . '%')
                              ->orWhere('skema_persyaratan', (string) $p->id);
                        })
                        ->exists();
                }

                if (!$hasFile) {
                    $dokumenKurang[] = $p->persyaratan;
                }
            }
        } catch (\Throwable $e) {
            $dokumenKurang = [];
        }

        // ── Detail Jadwal Asesmen (jika sudah dijadwalkan) ──
        $jadwalData = null;
        if (!empty($m->id_jadwal)) {
            $j = $m->jadwal ?? \App\Models\JadwalAsesmen::with(['tuk', 'asesor'])->find($m->id_jadwal);
            if ($j) {
                $tglAwal = $j->tgl_asesmen ? (is_string($j->tgl_asesmen) ? substr($j->tgl_asesmen, 0, 10) : $j->tgl_asesmen->format('Y-m-d')) : null;
                $tglAkhir = $j->tgl_asesmen_akhir ? (is_string($j->tgl_asesmen_akhir) ? substr($j->tgl_asesmen_akhir, 0, 10) : $j->tgl_asesmen_akhir->format('Y-m-d')) : null;
                $jadwalData = [
                    'id' => (int) $j->id,
                    'nama_kegiatan' => $j->nama_kegiatan,
                    'tgl_asesmen' => $tglAwal,
                    'tgl_asesmen_akhir' => $tglAkhir,
                    'jam_asesmen' => $j->jam_asesmen,
                    'tuk_nama' => $j->tuk ? $j->tuk->nama : ($j->tempat_asesmen ?? null),
                    'tuk_alamat' => $j->tuk ? trim($j->tuk->alamat) : null,
                    'pelaksanaan_uji_label' => $j->pelaksanaan_uji_label,
                    'penguji' => $j->asesor ? $j->asesor->map(function ($a) {
                        $front = $a->gelar_depan ? $a->gelar_depan . ' ' : '';
                        $back = $a->gelar_blk ? ', ' . $a->gelar_blk : '';
                        return [
                            'id' => $a->id,
                            'nama' => $front . $a->nama . $back,
                            'no_reg' => $a->no_reg ?? null,
                        ];
                    })->values()->all() : [],
                ];
            }
        }

        // ── STATE MACHINE: status × (biaya_asesmen | status_asesmen) ──
        [$pesan, $warna, $aksi] = $this->deriveStatusMatriks(
            $m->status,
            $m->biaya_asesmen,
            $m->status_asesmen,
            $m->id_jadwal,
            $modePupr,
            (int) $m->id
        );

        return [
            'id_asesmen' => $m->id,
            'skema' => $skema,
            // Status mentah (untuk filter/expand frontend)
            'status' => $m->status,                       // P | A | R
            'status_asesmen' => $m->status_asesmen,       // P | K | BK | TL
            'biaya_asesmen' => $m->biaya_asesmen,         // P | K | L
            'id_jadwal' => $m->id_jadwal,
            'jadwal' => $jadwalData,
            'biaya' => $m->biaya ? (int) $m->biaya : null,
            'biaya_formatted' => $m->biaya ? number_format((float) $m->biaya, 0, ',', '.') : null,
            // Derived (state machine)
            'pesan' => $pesan,
            'warna' => $warna,            // orange | green | blue | red
            'aksi' => $aksi,              // tombol kontekstual (bisa null / multiple)
            'dokumen_kurang' => $dokumenKurang,           // akumulasi (fix bug native)
            'dokumen_lengkap' => empty($dokumenKurang),
        ];
    }

    /**
     * Matriks status → [pesan, warna, aksi] — replikasi persis nested switch
     * native (§3 Matriks Status). Fallback untuk kombinasi tak terduga
     * (perbaikan atas fallthrough pesan kosong native).
     */
    private function deriveStatusMatriks(
        ?string $status,
        ?string $biaya,
        ?string $statusAsesmen,
        $idJadwal,
        bool $modePupr,
        int $idAsesmen
    ): array {
        // ── CASE R (ditolak) ──
        if ($status === 'R') {
            return [
                'Pendaftaran Anda ditolak. Hubungi admin LSK untuk informasi lebih lanjut.',
                'red',
                null,
            ];
        }

        // ── CASE A (disetujui) → nested status_asesmen ──
        if ($status === 'A') {
            switch ($statusAsesmen) {
                case 'K':
                    return [
                        'Selamat! Anda dinyatakan KOMPETEN.',
                        'green',
                        ['label' => 'Lihat Data Sertifikat', 'url' => '/peserta/sertifikat'],
                    ];
                case 'BK':
                    return [
                        'Anda dinyatakan BELUM KOMPETEN. Anda dapat mengajukan banding.',
                        'red',
                        ['label' => 'Ajukan Banding', 'url' => "/peserta/banding?idass={$idAsesmen}&idj={$idJadwal}"],
                    ];
                case 'TL':
                    return [
                        'BELUM KOMPETEN — Perlu Tindak Lanjut.',
                        'red',
                        // Fix dead-link native: modul 'pelatihan' tidak ada →
                        // diarahkan ke banding (jalur tindak lanjut fungsional)
                        ['label' => 'Ajukan Banding / Tindak Lanjut', 'url' => "/peserta/banding?idass={$idAsesmen}&idj={$idJadwal}"],
                    ];
                case 'P':
                default:
                    $aksi = null;
                    // PUPR + biaya P: + tombol konfirmasi bayar (kombinasi idem native)
                    if ($modePupr && $biaya === 'P') {
                        $aksi = ['label' => 'Konfirmasi Pembayaran', 'url' => '/peserta/konfirmasi-pembayaran'];
                    }
                    return ['Pendaftaran diterima dan dijadwalkan.', 'green', $aksi];
            }
        }

        // ── CASE P (menunggu keputusan admin) → nested biaya_asesmen ──
        if ($status === 'P') {
            switch ($biaya) {
                case 'K':
                    return [
                        'Pembayaran telah dikonfirmasi. Menunggu validasi pembayaran oleh admin.',
                        'green',
                        null,
                    ];
                case 'L':
                    return [
                        'Pembayaran lunas. Menunggu persetujuan pendaftaran oleh admin.',
                        'blue',
                        null,
                    ];
                case 'P':
                default:
                    // PUPR mode: daftar/bayar via portal PUPR → tombol internal disembunyikan
                    $aksi = $modePupr ? null : [
                        'label' => 'Konfirmasi Pembayaran',
                        'url' => '/peserta/konfirmasi-pembayaran',
                    ];
                    return ['Menunggu Pembayaran.', 'orange', $aksi];
            }
        }

        // ── Fallback kombinasi tak terduga (fix: pesan kosong native) ──
        return [
            'Status pendaftaran sedang diproses. Hubungi admin LSK bila ada pertanyaan.',
            'orange',
            null,
        ];
    }
}
