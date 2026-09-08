<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard Admin — GET /api/v1/admin/dashboard (auth:sanctum, role admin)
 * Sesuai docs/ALUR_ADMIN_DASHBOARD.md: satu endpoint penyatu 8 blok data.
 *
 * Blok: statistics (4 kartu) · angkatan · provinsi · kota · pengusul ·
 *       demografi (peserta & penguji) · kemajuan proses · asesi_terbaru
 *
 * Catatan: read-only agregat — cache 60 detik (dashboard tak butuh real-time).
 * Zero-date guard: usia/angkatan NULL/'0000-00-00' dikecualikan dari rekap.
 */
class AdminDashboardController extends Controller
{
    private const CACHE_KEY = 'admin_dashboard_v1';
    private const CACHE_TTL = 60; // detik

    public function index(Request $request): JsonResponse
    {
        // ── Guard role admin ──
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }
        if (!$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus admin.',
            ], 403);
        }

        // Cache 60 detik (idem docs §6.5 — read-only agregat)
        $data = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return [
                'statistics'    => $this->statistics(),
                'angkatan'      => $this->angkatan(),
                'provinsi'      => $this->wilayah('propinsi'),
                'kota'          => $this->wilayah('kota'),
                'pengusul'      => $this->pengusul(),
                'demografi'     => $this->demografi(),
                'kemajuan'      => $this->kemajuan(),
                'asesi_terbaru' => $this->asesiTerbaru(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // 1. STATCARDS — 4 angka besar
    // ════════════════════════════════════════════════════════════════

    private function statistics(): array
    {
        return [
            'tuk'    => (int) DB::table('tuk')->count(),
            'skema'  => (int) DB::table('skema_kkni')->where('aktif', 'Y')->count(),
            'asesor' => (int) DB::table('asesor')->count(),
            'asesi'  => (int) DB::table('asesi')->count(),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    // 2. ANGKATAN — rekap per tahun (DESC, max 5, NULL excluded)
    // ════════════════════════════════════════════════════════════════

    private function angkatan(): array
    {
        $rows = DB::table('asesi')
            ->selectRaw('angkatan AS tahun, COUNT(*) AS jumlah')
            ->whereNotNull('angkatan')
            ->where('angkatan', '!=', '')
            ->where('angkatan', '!=', '0000')
            ->groupBy('angkatan')
            ->orderBy('angkatan', 'desc')
            ->limit(5)
            ->get();

        return $rows->map(fn ($r, $i) => [
            'no'     => $i + 1,
            'tahun'  => (string) $r->tahun,
            'jumlah' => (int) $r->jumlah,
        ])->all();
    }

    // ════════════════════════════════════════════════════════════════
    // 3 & 4. PROVINSI / KOTA — top 5 (resolve nama via data_wilayah)
    // ════════════════════════════════════════════════════════════════

    private function wilayah(string $kolom): array
    {
        // level_wil: 1 = provinsi, 2 = kota/kabupaten
        $level = $kolom === 'propinsi' ? 1 : 2;

        $rows = DB::table('asesi as a')
            ->join('data_wilayah as w', function ($join) use ($kolom, $level) {
                $join->on("a.{$kolom}", '=', 'w.id_wil')
                    ->where('w.id_level_wil', $level);
            })
            ->selectRaw("w.id_wil AS kode, w.nm_wil AS nama, COUNT(*) AS jumlah")
            ->where("a.{$kolom}", '!=', '')
            ->whereNotNull("a.{$kolom}")
            ->groupBy('w.id_wil', 'w.nm_wil')
            ->orderByDesc('jumlah')
            ->limit(5)
            ->get();

        return $rows->map(fn ($r, $i) => [
            'no'     => $i + 1,
            'kode'   => (string) $r->kode,
            'nama'   => $r->nama,
            'jumlah' => (int) $r->jumlah,
        ])->all();
    }

    // ════════════════════════════════════════════════════════════════
    // 5. PENGUSUL — top pengusul (resolve nama dari tabel pengusul)
    // ════════════════════════════════════════════════════════════════

    private function pengusul(): array
    {
        // Data aktual asesi.id_pengusul berformat 'PNG001' (bukan pengusul.id int).
        // Group by nilai mentah, lalu coba resolve nama instansi dari tabel pengusul
        // (by id numerik ATAU kolom nama mirip). Fallback: tampilkan nilai mentah.
        $rows = DB::table('asesi')
            ->selectRaw("id_pengusul AS kode, COUNT(*) AS jumlah")
            ->whereNotNull('id_pengusul')
            ->where('id_pengusul', '!=', '')
            ->groupBy('id_pengusul')
            ->orderByDesc('jumlah')
            ->limit(5)
            ->get();

        return $rows->map(function ($r, $i) {
            $nama = $this->resolvePengusulNama($r->kode);
            return [
                'no'     => $i + 1,
                'id'     => (string) $r->kode,
                'nama'   => $nama,
                'jumlah' => (int) $r->jumlah,
            ];
        })->all();
    }

    /**
     * Resolve nama instansi pengusul dari berbagai kemungkinan format
     * id_pengusul ('PNG001' / id numerik / nama langsung).
     */
    private function resolvePengusulNama($kode): string
    {
        if (empty($kode)) {
            return '(Tanpa pengusul)';
        }

        try {
            // 1. id numerik → pengusul.id
            if (ctype_digit((string) $kode)) {
                $nama = DB::table('pengusul')->where('id', $kode)->value('nama');
                if ($nama) return $nama;
            }

            // 2. Kode alfanumerik (PNG001) → cek keberadaan tabel kode bila ada;
            //    fallback: tampilkan kode apa adanya (data belum bisa di-resolve)
            return (string) $kode;
        } catch (\Throwable $e) {
            return (string) $kode;
        }
    }

    // ════════════════════════════════════════════════════════════════
    // 6. DEMOGRAFI — gender & kelompok usia (peserta & penguji)
    // ════════════════════════════════════════════════════════════════

    private function demografi(): array
    {
        return [
            'peserta' => $this->demografiTabel('asesi'),
            'penguji' => $this->demografiTabel('asesor'),
        ];
    }

    private function demografiTabel(string $tabel): array
    {
        $genderRows = DB::table($tabel)
            ->selectRaw("jenis_kelamin, COUNT(*) AS jumlah")
            ->groupBy('jenis_kelamin')
            ->get()
            ->pluck('jumlah', 'jenis_kelamin');

        // Bucket usia (zero-date guard: usia NULL/0 dikecualikan)
        $usia = DB::table($tabel)
            ->selectRaw("
                SUM(usia BETWEEN 17 AND 25) AS b1,
                SUM(usia BETWEEN 26 AND 35) AS b2,
                SUM(usia BETWEEN 36 AND 45) AS b3,
                SUM(usia BETWEEN 46 AND 55) AS b4,
                SUM(usia >= 56) AS b5
            ")
            ->whereNotNull('usia')
            ->where('usia', '>', 0)
            ->first();

        $buckets = [
            ['rentang' => '17-25', 'label' => '17-25', 'jumlah' => (int) ($usia->b1 ?? 0)],
            ['rentang' => '26-35', 'label' => '26-35', 'jumlah' => (int) ($usia->b2 ?? 0)],
            ['rentang' => '36-45', 'label' => '36-45', 'jumlah' => (int) ($usia->b3 ?? 0)],
            ['rentang' => '46-55', 'label' => '46-55', 'jumlah' => (int) ($usia->b4 ?? 0)],
            ['rentang' => '56+',   'label' => '56+',   'jumlah' => (int) ($usia->b5 ?? 0)],
        ];

        return [
            'gender' => [
                'lakiLaki'  => (int) ($genderRows['L'] ?? 0),
                'perempuan' => (int) ($genderRows['P'] ?? 0),
            ],
            'kelompokUsia' => $buckets,
        ];
    }

    // ════════════════════════════════════════════════════════════════
    // 7. KEMAJUAN PROSES — progres administratif (current/total)
    // ════════════════════════════════════════════════════════════════

    private function kemajuan(): array
    {
        $tahun = now()->year;

        // ── Berkas lengkap: peserta dgn semua dokumen devsyarat wajib terisi ──
        $totalAsesi = DB::table('asesi')->count();
        $berkasLengkap = 0;

        $wajib = DB::table('asesi_persyaratanpokok')
            ->where('wajib', 'Y')->where('aktif', 'Y')
            ->pluck('shortcode');

        if ($wajib->isNotEmpty()) {
            $select = implode(' + ', $wajib->map(fn ($sc) =>
                "(CASE WHEN {$sc} IS NOT NULL AND TRIM({$sc}) != '' THEN 1 ELSE 0 END)"
            )->all());
            // Peserta yang jumlah kolom dokumen terisinya == jumlah dokumen wajib
            $berkasLengkap = (int) DB::table('asesi')
                ->selectRaw("SUM(($select) = ?) AS c", [$wajib->count()])
                ->value('c');
        }

        // ── Asesi_asesmen dasar (tahun berjalan) ──
        $base = DB::table('asesi_asesmen')
            ->whereYear('tgl_daftar', $tahun);

        $totalAsesmen = (clone $base)->count();
        $terjadwal = (clone $base)->whereNotNull('id_jadwal')->count();
        $kompeten = (clone $base)->where('status_asesmen', 'K')->count();
        $belumKompeten = (clone $base)->where('status_asesmen', 'BK')->count();

        return [
            'proses' => [
                [
                    'title'  => 'Data Peserta telah melengkapi berkas',
                    'current' => $berkasLengkap,
                    'total'  => $totalAsesi,
                ],
                [
                    'title'  => 'Data asesmen diproses (terjadwal)',
                    'current' => $terjadwal,
                    'total'  => $totalAsesmen,
                ],
            ],
            'hasil' => [
                [
                    'title'  => 'Data Asesmen dinyatakan Kompeten',
                    'current' => $kompeten,
                    'total'  => $totalAsesmen,
                ],
                [
                    'title'  => 'Data Asesmen dinyatakan Belum Kompeten',
                    'current' => $belumKompeten,
                    'total'  => $totalAsesmen,
                ],
            ],
        ];
    }

    // ════════════════════════════════════════════════════════════════
    // 8. ASESI TERBARU — 8 peserta terakhir + skema + TUK + status enum
    // ════════════════════════════════════════════════════════════════

    private function asesiTerbaru(): array
    {
        $rows = DB::table('asesi')
            ->orderBy('tgl_daftar', 'desc')
            ->orderBy('id', 'desc')
            ->limit(8)
            ->get(['id', 'no_pendaftaran', 'nama', 'tgl_daftar', 'verifikasi']);

        return $rows->values()->map(function ($a, $i) {
            // Asesmen terbaru peserta ini
            $m = DB::table('asesi_asesmen')
                ->where('id_asesi', $a->no_pendaftaran)
                ->orderBy('id', 'desc')
                ->first(['id_skemakkni', 'status', 'status_asesmen', 'id_jadwal']);

            // Skema judul singkat
            $skema = null;
            if ($m && !empty($m->id_skemakkni)) {
                $skema = DB::table('skema_kkni')
                    ->where('id', $m->id_skemakkni)->value('judul');
            }

            // TUK nama dari jadwal (tempat_asesmen = ID TUK numeric → resolve)
            $tuk = null;
            if ($m && !empty($m->id_jadwal)) {
                $jadwal = DB::table('jadwal_asesmen')
                    ->where('id', $m->id_jadwal)->value('tempat_asesmen');
                if ($jadwal) {
                    if (ctype_digit((string) $jadwal)) {
                        $tuk = DB::table('tuk')->where('id', $jadwal)->value('nama') ?: $jadwal;
                    } else {
                        $tuk = $jadwal;
                    }
                }
            }

            return [
                'no'         => $i + 1,
                'id'         => $a->no_pendaftaran,
                'nama'       => $a->nama,
                'skema'      => $skema,
                'tuk'        => $tuk,
                'status'     => $this->deriveStatusAsesi($a, $m),
                'tgl_daftar' => $a->tgl_daftar,
            ];
        })->all();
    }

    /**
     * Mapping status enum (docs §4):
     * K → kompeten · BK → belum_kompeten · A+P → terverifikasi
     * verifikasi='P' atau tanpa asesmen → belum_terverifikasi
     */
    private function deriveStatusAsesi($asesi, $asesmen): string
    {
        if (!$asesmen) {
            return 'belum_terverifikasi';
        }
        if ($asesmen->status_asesmen === 'K') {
            return 'kompeten';
        }
        if ($asesmen->status_asesmen === 'BK') {
            return 'belum_kompeten';
        }
        if ($asesmen->status === 'A' && $asesmen->status_asesmen === 'P') {
            return 'terverifikasi';
        }
        // status='P' (menunggu keputusan) atau verifikasi 'P'
        return 'belum_terverifikasi';
    }
}
