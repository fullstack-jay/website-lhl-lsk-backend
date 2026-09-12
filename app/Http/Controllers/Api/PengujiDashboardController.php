<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard Penguji (Portal PENGUJI) — halaman agregasi
 * GET /api/v1/auth/penguji/dashboard  (auth:sanctum, role penguji)
 * Sesuai docs/BACKEND_DASHBOARD_PENGUJI.md:
 *
 * - 1 endpoint menyatukan 4 modul penugasan (Jadwal Uji, Verifikasi TUK,
 *   Meninjau Instrumen, MKVA) + statistik asesi
 * - STATISTIK (6 kartu, §4.2): jadwal_uji & verifikasi_tuk = penugasan AKTIF;
 *   meninjau_instrumen = DISTINCT jadwal penugasan per-peserta;
 *   mkva = jadwal penugasan tanpa row `mkva`;
 *   peserta_diuji/kompeten dari asesi_asesmen jadwal pivot penguji
 * - JADWAL TERDEKAT: gabungan 4 modul, tanggal >= hari ini,
 *   sort tanggal+jam ASC, maks 5 (verifikasi pakai tgl_verifikasi, bukan tgl uji)
 * - AKTIVITAS TERBARU: bukti penyelesaian nyata (§4.3), sort waktu DESC, maks 5
 *
 * Adaptasi kolom legacy (sesuai skema DB aktual):
 * - Tabel legacy memakai `waktu` (timestamp), BUKAN `updated_at`
 * - `asesi_asesmen` TIDAK punya kolom timestamp → waktu aktivitas asesmen
 *   fallback ke COALESCE(aa.tgl_asesmen, j.tgl_asesmen) (§4.3 fallback)
 * - `mkva.id_jadwal` varchar → dibandingkan dengan CAST(jadwal.id AS CHAR)
 * - TUK resolve dari `tempat_asesmen` (idem modul jadwal-uji)
 * - Verifikasi "aktif": tgl_verifikasi NULL dianggap masih aktif (belum dijadwalkan)
 */
class PengujiDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // ── Guard role penguji ──
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }
        if (!$user->isPenguji()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus penguji.',
            ], 403);
        }

        // ── Resolve asesor dari token (session native = no_ktp) ──
        $asesor = Asesor::where('no_ktp', $user->username)
            ->orWhere('no_ktp', $user->no_ktp)
            ->first();

        // Defensive: penguji tanpa profil asesor → 200 data kosong (bukan error)
        if (!$asesor) {
            return response()->json([
                'success' => true,
                'data' => [
                    'penguji' => null,
                    'statistik' => $this->emptyStatistik(),
                    'jadwal_terdekat' => [],
                    'aktivitas_terbaru' => [],
                    'terakhir_diperbarui' => now()->toIso8601String(),
                ],
            ]);
        }

        $idA = $asesor->id;
        $today = now()->toDateString();

        // ════════════════════════════════════════════════════════════
        // STATISTIK (6 kartu)
        // ════════════════════════════════════════════════════════════

        // a. Jadwal Uji aktif: pivot jadwal_asesor, belum Selesai, belum lewat
        $statJadwalUji = DB::table('jadwal_asesor as ja')
            ->join('jadwal_asesmen as j', 'j.id', '=', 'ja.id_jadwal')
            ->where('ja.id_asesor', $idA)
            ->where('j.status', '!=', 'Selesai')
            ->whereRaw('COALESCE(j.tgl_asesmen_akhir, j.tgl_asesmen) >= ?', [$today])
            ->distinct()
            ->count('j.id');

        // b. Verifikasi TUK aktif: belum diputuskan (P) & tgl >= hari ini
        //    (tgl_verifikasi NULL = belum dijadwalkan → tetap aktif)
        $statVerifikasi = DB::table('asesor_verifikatortuk')
            ->where('id_asesor', $idA)
            ->where('keputusanverifikasi', 'P')
            ->where(function ($q) use ($today) {
                $q->whereNull('tgl_verifikasi')->orWhere('tgl_verifikasi', '>=', $today);
            })
            ->count();

        // c. Meninjau Instrumen: DISTINCT jadwal penugasan per-peserta
        //    (kolom asesi_asesmen.peninjau_ia11, idem modul meninjau-instrumen)
        $statMeninjau = DB::table('asesi_asesmen')
            ->where('peninjau_ia11', $idA)
            ->whereNotNull('id_jadwal')
            ->where('id_jadwal', '!=', '')
            ->distinct()
            ->count('id_jadwal');

        // d. MKVA: jadwal penugasan (asesor_mkva1/2) yang belum ada row `mkva`
        $statMkva = DB::table('jadwal_asesmen as j')
            ->leftJoin('mkva as m', 'm.id_jadwal', '=', DB::raw('CAST(j.id AS CHAR)'))
            ->where(function ($q) use ($idA) {
                $q->where('j.asesor_mkva1', $idA)->orWhere('j.asesor_mkva2', $idA);
            })
            ->whereNull('m.id')
            ->distinct()
            ->count('j.id');

        // e+f. Peserta diuji & kompeten: asesi_asesmen pada jadwal pivot penguji
        $pesertaBase = DB::table('asesi_asesmen')
            ->whereIn('id_jadwal', function ($q) use ($idA) {
                $q->select('id_jadwal')->from('jadwal_asesor')->where('id_asesor', $idA);
            });
        $statPesertaDiuji = (clone $pesertaBase)->whereIn('status_asesmen', ['K', 'BK'])->count();
        $statPesertaKompeten = (clone $pesertaBase)->where('status_asesmen', 'K')->count();

        $statistik = [
            'jadwal_uji' => (int) $statJadwalUji,
            'verifikasi_tuk' => (int) $statVerifikasi,
            'meninjau_instrumen' => (int) $statMeninjau,
            'mkva' => (int) $statMkva,
            'peserta_diuji' => (int) $statPesertaDiuji,
            'peserta_kompeten' => (int) $statPesertaKompeten,
        ];

        // ════════════════════════════════════════════════════════════
        // JADWAL TERDEKAT (gabungan 4 modul, tanggal >= hari ini, maks 5)
        // ════════════════════════════════════════════════════════════
        $cards = collect();

        // 1. Uji Kompetensi (pivot jadwal_asesor)
        $cards = $cards->merge(
            DB::table('jadwal_asesor as ja')
                ->join('jadwal_asesmen as j', 'j.id', '=', 'ja.id_jadwal')
                ->leftJoin('tuk as t', 't.id', '=', DB::raw("NULLIF(j.tempat_asesmen, '')"))
                ->leftJoin('skema_kkni as sk', 'sk.id', '=', DB::raw("NULLIF(j.id_skemakkni, '')"))
                ->where('ja.id_asesor', $idA)
                ->whereRaw('COALESCE(j.tgl_asesmen_akhir, j.tgl_asesmen) >= ?', [$today])
                ->selectRaw("
                    j.id, j.nama_kegiatan, j.tahun, j.periode, j.gelombang,
                    j.tgl_asesmen, j.jam_asesmen, j.tempat_asesmen, j.status,
                    t.nama AS tuk_nama, sk.kode_skema, sk.judul AS skema_judul,
                    (SELECT COUNT(*) FROM asesi_asesmen a WHERE a.id_jadwal = j.id) AS jumlah_peserta
                ")
                ->get()
                ->map(fn ($r) => $this->jadwalCard($r, 'uji_kompetensi', 'uji', $r->tgl_asesmen, $this->mapStatusJadwal($r->status)))
        );

        // 2. Verifikasi TUK (pivot asesor_verifikatortuk — tanggal = tgl_verifikasi)
        $cards = $cards->merge(
            DB::table('asesor_verifikatortuk as v')
                ->join('jadwal_asesmen as j', 'j.id', '=', 'v.id_jadwal')
                ->leftJoin('tuk as t', 't.id', '=', DB::raw("NULLIF(j.tempat_asesmen, '')"))
                ->leftJoin('skema_kkni as sk', 'sk.id', '=', DB::raw("NULLIF(v.id_skemakkni, '')"))
                ->where('v.id_asesor', $idA)
                ->whereNotNull('v.tgl_verifikasi')
                ->where('v.tgl_verifikasi', '>=', $today)
                ->selectRaw('
                    v.id_jadwal, v.tgl_verifikasi, v.keputusanverifikasi,
                    j.nama_kegiatan, j.tahun, j.periode, j.gelombang,
                    j.jam_asesmen, j.tempat_asesmen, j.status,
                    t.nama AS tuk_nama, sk.kode_skema, sk.judul AS skema_judul
                ')
                ->get()
                ->map(function ($r) {
                    // Status kartu dari keputusan verifikasi, bukan status jadwal
                    $status = $r->keputusanverifikasi === 'P' ? 'terjadwal' : 'selesai';

                    return $this->jadwalCard($r, 'verifikasi_tuk', 'verif', $r->tgl_verifikasi, $status, null, "Verifikasi TUK {$r->tuk_nama}");
                })
        );

        // 3. Meninjau Instrumen (kolom asesi_asesmen.peninjau_ia11 → DISTINCT jadwal)
        $cards = $cards->merge(
            DB::table('asesi_asesmen as a')
                ->join('jadwal_asesmen as j', 'j.id', '=', 'a.id_jadwal')
                ->leftJoin('tuk as t', 't.id', '=', DB::raw("NULLIF(j.tempat_asesmen, '')"))
                ->leftJoin('skema_kkni as sk', 'sk.id', '=', DB::raw("NULLIF(j.id_skemakkni, '')"))
                ->where('a.peninjau_ia11', $idA)
                ->whereNotNull('a.id_jadwal')
                ->whereRaw('COALESCE(j.tgl_asesmen_akhir, j.tgl_asesmen) >= ?', [$today])
                ->groupBy('j.id', 'j.nama_kegiatan', 'j.tahun', 'j.periode', 'j.gelombang',
                    'j.tgl_asesmen', 'j.jam_asesmen', 'j.tempat_asesmen', 'j.status',
                    't.nama', 'sk.kode_skema', 'sk.judul')
                ->selectRaw('
                    j.id, j.nama_kegiatan, j.tahun, j.periode, j.gelombang,
                    j.tgl_asesmen, j.jam_asesmen, j.tempat_asesmen, j.status,
                    t.nama AS tuk_nama, sk.kode_skema, sk.judul AS skema_judul,
                    COUNT(DISTINCT a.id_asesi) AS jumlah_peserta
                ')
                ->get()
                ->map(fn ($r) => $this->jadwalCard($r, 'meninjau_instrumen', 'tinjau', $r->tgl_asesmen, $this->mapStatusJadwal($r->status)))
        );

        // 4. MKVA (kolom jadwal_asesmen.asesor_mkva1/2)
        $cards = $cards->merge(
            DB::table('jadwal_asesmen as j')
                ->leftJoin('tuk as t', 't.id', '=', DB::raw("NULLIF(j.tempat_asesmen, '')"))
                ->leftJoin('skema_kkni as sk', 'sk.id', '=', DB::raw("NULLIF(j.id_skemakkni, '')"))
                ->where(function ($q) use ($idA) {
                    $q->where('j.asesor_mkva1', $idA)->orWhere('j.asesor_mkva2', $idA);
                })
                ->whereRaw('COALESCE(j.tgl_asesmen_akhir, j.tgl_asesmen) >= ?', [$today])
                ->selectRaw('
                    j.id, j.nama_kegiatan, j.tahun, j.periode, j.gelombang,
                    j.tgl_asesmen, j.jam_asesmen, j.tempat_asesmen, j.status,
                    t.nama AS tuk_nama, sk.kode_skema, sk.judul AS skema_judul,
                    (SELECT COUNT(*) FROM asesi_asesmen a WHERE a.id_jadwal = j.id) AS jumlah_peserta
                ')
                ->get()
                ->map(fn ($r) => $this->jadwalCard($r, 'mkva', 'mkva', $r->tgl_asesmen, $this->mapStatusJadwal($r->status), null))
        );

        $jadwalTerdekat = $cards
            ->filter(fn ($c) => !empty($c['tanggal']))
            ->sortBy([['tanggal', 'asc'], ['jam', 'asc']])
            ->values()
            ->take(5)
            ->all();

        // ════════════════════════════════════════════════════════════
        // AKTIVITAS TERBARU (bukti penyelesaian nyata, waktu DESC, maks 5)
        // ════════════════════════════════════════════════════════════
        $aktivitas = collect();

        // a. Asesmen: peserta di jadwal pivot penguji dengan hasil K/BK
        //    (asesi_asesmen tanpa timestamp → fallback tgl_asesmen, §4.3)
        $aktivitas = $aktivitas->merge(
            DB::table('asesi_asesmen as aa')
                ->join('jadwal_asesmen as j', 'j.id', '=', 'aa.id_jadwal')
                ->leftJoin('skema_kkni as sk', 'sk.id', '=', DB::raw("NULLIF(COALESCE(aa.id_skemakkni, j.id_skemakkni), '')"))
                ->whereIn('aa.id_jadwal', function ($q) use ($idA) {
                    $q->select('id_jadwal')->from('jadwal_asesor')->where('id_asesor', $idA);
                })
                ->whereIn('aa.status_asesmen', ['K', 'BK'])
                ->groupBy('j.id', 'j.nama_kegiatan', 'j.tahun', 'j.periode', 'j.gelombang', 'j.tgl_asesmen', 'sk.judul')
                ->selectRaw('
                    MAX(COALESCE(aa.tgl_asesmen, j.tgl_asesmen)) AS waktu,
                    j.nama_kegiatan, j.tahun, j.periode, j.gelombang, sk.judul AS skema_judul
                ')
                ->get()
                ->map(function ($r) {
                    $judulKegiatan = trim((string) $r->nama_kegiatan) !== ''
                        ? $r->nama_kegiatan
                        : trim("{$r->periode} {$r->tahun} Gelombang {$r->gelombang}");
                    $skemaJudul = $r->skema_judul ?: 'Asesmen';

                    return [
                        'jenis' => 'asesmen',
                        'judul' => trim("Asesmen {$skemaJudul} — {$judulKegiatan}", ' —'),
                        'waktu' => $r->waktu,
                    ];
                })
        );

        // b. Verifikasi TUK: keputusan sudah diambil (Y/N), waktu = pivot.waktu
        $aktivitas = $aktivitas->merge(
            DB::table('asesor_verifikatortuk as v')
                ->join('jadwal_asesmen as j', 'j.id', '=', 'v.id_jadwal')
                ->leftJoin('tuk as t', 't.id', '=', DB::raw("NULLIF(j.tempat_asesmen, '')"))
                ->where('v.id_asesor', $idA)
                ->whereIn('v.keputusanverifikasi', ['Y', 'N'])
                ->selectRaw('v.waktu AS waktu, v.tgl_verifikasi, t.nama AS tuk_nama')
                ->get()
                ->map(fn ($r) => [
                    'jenis' => 'verifikasi',
                    'judul' => 'Verifikasi TUK ' . ($r->tuk_nama ?: '(TUK belum terdata)'),
                    'waktu' => $r->waktu ?: $r->tgl_verifikasi,
                ])
        );

        // c. Peninjauan Instrumen: peserta penugasan dengan 8 jawaban IA.11 lengkap
        $aktivitas = $aktivitas->merge(
            DB::table('asesmen_ia11 as ia')
                ->join('asesi_asesmen as aa', function ($join) {
                    $join->on('aa.id_jadwal', '=', 'ia.id_jadwal')
                        ->on('aa.id_asesi', '=', 'ia.id_asesi');
                })
                ->leftJoin('skema_kkni as sk', 'sk.id', '=', DB::raw("NULLIF(ia.id_skemakkni, '')"))
                ->where('aa.peninjau_ia11', $idA)
                ->groupBy('ia.id_jadwal', 'sk.judul')
                ->havingRaw('COUNT(*) >= 8')
                ->selectRaw('MAX(ia.waktu) AS waktu, sk.judul AS skema_judul')
                ->get()
                ->map(fn ($r) => [
                    'jenis' => 'peninjauan',
                    'judul' => 'Peninjauan Instrumen ' . ($r->skema_judul ?: 'Asesmen'),
                    'waktu' => $r->waktu,
                ])
        );

        // d. MKVA: row FR.VA Bagian 1 tersimpan utk jadwal penugasan
        $aktivitas = $aktivitas->merge(
            DB::table('mkva as m')
                ->join('jadwal_asesmen as j', DB::raw('CAST(j.id AS CHAR)'), '=', 'm.id_jadwal')
                ->leftJoin('skema_kkni as sk', 'sk.id', '=', DB::raw("NULLIF(j.id_skemakkni, '')"))
                ->where(function ($q) use ($idA) {
                    $q->where('j.asesor_mkva1', $idA)->orWhere('j.asesor_mkva2', $idA);
                })
                ->selectRaw('m.waktu AS waktu, sk.judul AS skema_judul')
                ->get()
                ->map(fn ($r) => [
                    'jenis' => 'mkva',
                    'judul' => 'Validasi Asesmen (MKVA) ' . ($r->skema_judul ?: 'Asesmen'),
                    'waktu' => $r->waktu,
                ])
        );

        $aktivitasTerbaru = $aktivitas
            ->filter(fn ($a) => !empty($a['waktu']))
            ->sortByDesc('waktu')
            ->values()
            ->take(5)
            ->map(function ($a, $i) {
                return [
                    'id' => 'akt-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'jenis' => $a['jenis'],
                    'judul' => $a['judul'],
                    'tanggal' => substr((string) $a['waktu'], 0, 10),
                    'status' => 'selesai',
                ];
            })
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'penguji' => [
                    'id' => $asesor->id,
                    'nama_lengkap' => $asesor->full_name,
                    'no_induk' => $asesor->no_induk,
                    'email' => $asesor->email,
                ],
                'statistik' => $statistik,
                'jadwal_terdekat' => $jadwalTerdekat,
                'aktivitas_terbaru' => $aktivitasTerbaru,
                'terakhir_diperbarui' => now()->toIso8601String(),
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // HELPER
    // ════════════════════════════════════════════════════════════════

    /**
     * Kartu jadwal terdekat — bentuk seragam lintas modul (§5).
     * $judulFallback dipakai bila nama_kegiatan kosong (mis. "Verifikasi TUK {nama}").
     */
    private function jadwalCard(
        $r,
        string $jenis,
        string $prefix,
        $tanggal,
        string $status,
        $jumlahPeserta = '__row__',
        ?string $judulFallback = null
    ): array {
        $namaKegiatan = trim((string) $r->nama_kegiatan);
        if ($namaKegiatan === '') {
            // Fallback idem native: "{periode} {tahun} Gelombang {g}"
            $namaKegiatan = trim("{$r->periode} {$r->tahun} Gelombang {$r->gelombang}");
        }
        if ($namaKegiatan === '' && $judulFallback !== null) {
            $namaKegiatan = $judulFallback;
        }

        // jumlah_peserta "__row__" = ambil dari row (uji/meninjau);
        // null eksplisit = modul tanpa hitungan peserta (verifikasi/mkva)
        $jumlah = $jumlahPeserta === '__row__'
            ? (isset($r->jumlah_peserta) ? (int) $r->jumlah_peserta : null)
            : $jumlahPeserta;

        return [
            'id' => "{$prefix}-{$r->id}",
            'jenis' => $jenis,
            'judul' => $namaKegiatan,
            'tanggal' => $tanggal ? substr((string) $tanggal, 0, 10) : null,
            'jam' => trim((string) $r->jam_asesmen),
            'tempat' => $r->tuk_nama ?? (is_numeric($r->tempat_asesmen) ? null : $r->tempat_asesmen),
            'skema' => [
                'kode_skema' => $r->kode_skema,
                'judul' => $r->skema_judul,
            ],
            'status' => $status,
            'jumlah_peserta' => $jumlah,
        ];
    }

    /**
     * Mapping status jadwal → enum UI (§4.1).
     * Draft/Terkonfirmasi → terjadwal, Berlangsung → berlangsung, Selesai → selesai.
     */
    private function mapStatusJadwal(?string $status): string
    {
        return match ($status) {
            'Berlangsung' => 'berlangsung',
            'Selesai' => 'selesai',
            default => 'terjadwal',   // Draft, Terkonfirmasi, null
        };
    }

    private function emptyStatistik(): array
    {
        return [
            'jadwal_uji' => 0,
            'verifikasi_tuk' => 0,
            'meninjau_instrumen' => 0,
            'mkva' => 0,
            'peserta_diuji' => 0,
            'peserta_kompeten' => 0,
        ];
    }
}
