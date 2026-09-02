<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesi;
use App\Models\AsesiAsesmen;
use App\Models\AsesiPersyaratanpokok;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard Peserta — GET /api/v1/peserta/dashboard (auth:sanctum)
 * Sesuai docs/PESERTA_BACKEND_DASHBOARD.md.
 *
 * Menyatukan data lintas tabel untuk halaman depan portal peserta:
 * identitas, kartu status asesmen aktif (state machine 9 status),
 * progres 8 langkah, dan ringkasan.
 *
 * Rantai resolusi identitas (server-side — frontend tak perlu no_pendaftaran):
 *   token → users.no_ktp → asesi (no_ktp, fallback nohp=no_telp)
 *
 * Deviasi dari docs yang disesuaikan ke struktur DB nyata:
 * - asesi_asesmen tak punya created_at → urut by id DESC
 * - jadwal_asesmen tak punya FK id_tuk → tempat_asesmen (teks) di-resolve
 *   ke tuk.nama bila bernilai ID numerik
 * - asesi_pembayaran: bukti = kolom `file`, verifikasi = `status` ('P'/'V')
 */
class PesertaDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // ── 1. Guard role ──
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

        // ── 2. Resolve asesi (no_ktp, fallback nohp) ──
        $asesi = Asesi::where('no_ktp', $user->no_ktp)
            ->orWhere('nohp', $user->no_telp)
            ->first();

        // ── Guard blokir ──
        if ($asesi && $asesi->blokir === 'Y') {
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda diblokir. Hubungi admin LSK.',
            ], 403);
        }

        // ── Peserta tanpa profil asesi → dashboard kosong (langkah 1 active) ──
        if (!$asesi) {
            return response()->json([
                'success' => true,
                'data' => [
                    'peserta' => [
                        'nama' => $user->nama_lengkap,
                        'no_pendaftaran' => null,
                        'email' => $user->email,
                        'nohp' => $user->no_telp,
                        'angkatan' => null,
                        'foto_url' => null,
                    ],
                    'asesmen_aktif' => ['ada' => false],
                    'langkah' => $this->buildLangkah([
                        'ada_asesmen' => false, 'dok_lengkap' => false,
                        'ada_bayar' => false, 'ada_bukti' => false,
                        'terverifikasi' => false, 'terjadwal' => false,
                        'dinilai' => false, 'kompeten' => false, 'sertifikat' => false,
                    ]),
                    'ringkasan' => [
                        'dokumen_wajib' => ['ada' => 0, 'total' => 0],
                        'profil_perlu_lengkapi' => true,
                        'total_skema_diikuti' => 0,
                        'sertifikat_terbit' => 0,
                    ],
                ],
            ]);
        }

        // ── 3. Pendaftaran terakhir (asesmen aktif) ──
        $asesmen = AsesiAsesmen::where('id_asesi', $asesi->no_pendaftaran)
            ->orderBy('id', 'desc')
            ->first();

        // ── 4. Dokumen wajib DINAMIS dari devsyarat ──
        $wajib = AsesiPersyaratanpokok::wajib()->aktif()->orderBy('id')->get();
        $dokumenAda = 0;
        foreach ($wajib as $p) {
            if (!empty($asesi->{$p->shortcode})) {
                $dokumenAda++;
            }
        }
        $dokLengkap = $wajib->count() > 0 && $dokumenAda === $wajib->count();

        // Zero-date guard tgl_lahir (idem PESERTA_ROLE.md §2.1)
        $tglLahir = (string) $asesi->tgl_lahir;
        $profilPerluLengkapi = empty($tglLahir) || $tglLahir === '0000-00-00';
        if ($profilPerluLengkapi) {
            $dokLengkap = false;
        }

        // ── 5. Pembayaran skema aktif ──
        $bayar = null;
        if ($asesmen) {
            $bayar = DB::table('asesi_pembayaran')
                ->where('id_asesi', $asesi->no_pendaftaran)
                ->where('id_skemakkni', $asesmen->id_skemakkni)
                ->orderBy('id', 'desc')
                ->first();
        }

        // ── 6. Jadwal + TUK ──
        $jadwal = null;
        if ($asesmen && $asesmen->id_jadwal) {
            $jadwal = DB::table('jadwal_asesmen')->where('id', $asesmen->id_jadwal)->first();
        }
        $tukNama = null;
        $tukAlamat = null;
        if ($jadwal && $jadwal->tempat_asesmen !== null && $jadwal->tempat_asesmen !== '') {
            // tempat_asesmen berisi ID TUK (numeric) → resolve nama+alamat
            if (ctype_digit((string) $jadwal->tempat_asesmen)) {
                $tuk = DB::table('tuk')->where('id', $jadwal->tempat_asesmen)->first();
                if ($tuk) {
                    $tukNama = $tuk->nama;
                    $tukAlamat = $tuk->alamat;
                }
            }
            if ($tukNama === null) {
                $tukNama = $jadwal->tempat_asesmen;   // teks bebas
            }
        }

        // ── 7. Derive flags untuk state machine & langkah ──
        $adaAsesmen = $asesmen !== null;
        $adaBayar = $bayar !== null;
        $adaBukti = $bayar && !empty($bayar->file);
        $terverifikasi = $bayar && $bayar->status === 'V';
        $terjadwal = $asesmen && !empty($asesmen->id_jadwal);
        $tglAsesmen = $jadwal->tgl_asesmen ?? null;
        $jadwalAktif = $terjadwal && $tglAsesmen && $tglAsesmen >= now()->toDateString();
        $dinilai = $asesmen && in_array($asesmen->status_asesmen, ['K', 'BK']);
        $kompeten = $asesmen && $asesmen->status_asesmen === 'K';
        $sertifikat = $asesmen && (!empty($asesmen->no_serisertifikat) || !empty($asesmen->no_lisensi));

        // ── State machine 9 status (evaluasi berurutan, berhenti pertama cocok) ──
        [$status, $statusLabel, $tindakLanjut] = $this->deriveStatus(
            $adaAsesmen, $dokLengkap, $adaBayar, $adaBukti, $terverifikasi,
            $terjadwal, $jadwalAktif, $dinilai, $kompeten, $sertifikat,
            $asesmen->status_asesmen ?? null
        );

        // ── 8. Response ──
        return response()->json([
            'success' => true,
            'data' => [
                'peserta' => [
                    'nama' => $asesi->nama ?: $user->nama_lengkap,
                    'no_pendaftaran' => $asesi->no_pendaftaran,
                    'email' => $asesi->email ?: $user->email,
                    'nohp' => $asesi->nohp ?: $user->no_telp,
                    'angkatan' => $asesi->angkatan ? (int) $asesi->angkatan : null,
                    'foto_url' => $asesi->foto ? asset('foto_asesi/' . $asesi->foto) : null,
                ],

                'asesmen_aktif' => [
                    'ada' => $adaAsesmen,
                    'id_asesmen' => $asesmen?->id,
                    'skema' => $asesmen ? $this->skemaRingkas($asesmen->id_skemakkni) : null,
                    'status_pendaftaran' => $status,
                    'status_label' => $statusLabel,
                    'jadwal' => $terjadwal && $jadwal ? [
                        'tgl_asesmen' => $this->fmtDate($jadwal->tgl_asesmen),
                        'tgl_asesmen_formatted' => $this->fmtDateIndo($jadwal->tgl_asesmen),
                        'jam_asesmen' => $jadwal->jam_asesmen ? substr((string) $jadwal->jam_asesmen, 0, 5) : null,
                        'tuk' => [
                            'nama' => $tukNama,
                            'alamat' => $tukAlamat,
                        ],
                    ] : null,
                    'pembayaran' => $bayar ? [
                        'jumlah_bayar' => (int) ($bayar->nominal ?? 0),
                        'jumlah_bayar_formatted' => number_format((float) ($bayar->nominal ?? 0), 0, ',', '.'),
                        'tanggal_bayar' => $this->fmtDate($bayar->tgl_bayar ?? null),
                        'bukti_bayar_url' => !empty($bayar->file) ? asset('foto_buktibayar/' . $bayar->file) : null,
                        'verifikasi_bayar' => (bool) $terverifikasi,
                        'status_bayar' => $bayar->status ?? 'P',   // P=Pending | V=Terverifikasi
                    ] : null,
                    'tindak_lanjut' => $tindakLanjut,
                ],

                'langkah' => $this->buildLangkah([
                    'ada_asesmen' => $adaAsesmen,
                    'dok_lengkap' => $dokLengkap,
                    'ada_bayar' => $adaBayar,
                    'ada_bukti' => $adaBukti,
                    'terverifikasi' => (bool) $terverifikasi,
                    'terjadwal' => $terjadwal,
                    'dinilai' => $dinilai,
                    'kompeten' => $kompeten,
                    'sertifikat' => $sertifikat,
                ]),

                'ringkasan' => [
                    'dokumen_wajib' => ['ada' => $dokumenAda, 'total' => $wajib->count()],
                    'profil_perlu_lengkapi' => $profilPerluLengkapi,
                    'total_skema_diikuti' => AsesiAsesmen::where('id_asesi', $asesi->no_pendaftaran)->count(),
                    'sertifikat_terbit' => $sertifikat ? 1 : 0,
                ],
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // STATE MACHINE §4 — urutan evaluasi berhenti pada kondisi pertama
    // ════════════════════════════════════════════════════════════════

    private function deriveStatus(
        bool $adaAsesmen, bool $dokLengkap, bool $adaBayar, bool $adaBukti,
        bool $terverifikasi, bool $terjadwal, bool $jadwalAktif, bool $dinilai,
        bool $kompeten, bool $sertifikat, ?string $statusAsesmen
    ): array {
        // 1. Belum registrasi skema
        if (!$adaAsesmen) {
            return ['BELUM_REGISTRASI', 'Belum Registrasi', 'Pilih skema sertifikasi terlebih dahulu'];
        }
        // 2. Dokumen wajib belum lengkap
        if (!$dokLengkap) {
            return ['LENGKAPI_DOKUMEN', 'Lengkapi Dokumen', 'Lengkapi dokumen persyaratan di halaman Profil'];
        }
        // 3. Belum ada pembayaran
        if (!$adaBayar) {
            return ['MENUNGGU_PEMBAYARAN', 'Menunggu Pembayaran', 'Lakukan pembayaran biaya uji ke rekening resmi LSK'];
        }
        // 4. Bukti terupload, belum diverifikasi
        if (!$terverifikasi) {
            return ['VERIFIKASI_PEMBAYARAN', 'Verifikasi Pembayaran', 'Tim keuangan sedang memverifikasi bukti transfer Anda (1×24 jam)'];
        }
        // 5. Terverifikasi, belum dijadwalkan
        if (!$terjadwal) {
            return ['MENUNGGU_PENJADWALAN', 'Menunggu Penjadwalan', 'Pembayaran terkonfirmasi — menunggu jadwal asesmen dari admin'];
        }
        // 6. Terjadwal, jadwal belum lewat, belum dinilai
        if ($jadwalAktif && !$dinilai) {
            return ['TERJADWAL', 'Terjadwal', 'Hadir di TUK sesuai jadwal — bawa dokumen asli'];
        }
        // 7. Belum kompeten
        if ($statusAsesmen === 'BK') {
            return ['BELUM_KOMPETEN', 'Belum Kompeten', 'Lakukan tindak lanjut asesmen sesuai catatan penguji'];
        }
        // 8. Kompeten, sertifikat belum terbit
        if ($kompeten && !$sertifikat) {
            return ['KOMPETEN', 'Kompeten', 'Menunggu penerbitan sertifikat'];
        }
        // 9. Sertifikat terbit
        if ($sertifikat) {
            return ['SERTIFIKAT_TERBIT', 'Sertifikat Terbit', 'Unduh sertifikat kompetensi Anda'];
        }
        // Fallback (mis. jadwal lewat tanpa nilai)
        return ['DALAM_PROSES', 'Dalam Proses', 'Proses asesmen berlangsung — hubungi admin untuk info lebih lanjut'];
    }

    // ════════════════════════════════════════════════════════════════
    // PROGRES 8 LANGKAH §5 — done / active / locked
    // ════════════════════════════════════════════════════════════════

    private function buildLangkah(array $f): array
    {
        // done bila kondisi terpenuhi (dari data), sesuai tabel §5
        $done = [
            1 => $f['ada_asesmen'],
            2 => $f['ada_asesmen'] && $f['dok_lengkap'],
            3 => $f['ada_bayar'],
            4 => $f['ada_bukti'],
            5 => $f['terverifikasi'],
            6 => $f['terjadwal'],
            7 => $f['dinilai'],
            8 => $f['sertifikat'],
        ];

        // active bila prasyarat sebelumnya selesai tapi langkah ini belum
        $active = [
            1 => !$f['ada_asesmen'],
            2 => $done[1] && !$done[2],
            3 => $done[2] && !$done[3],
            4 => $done[3] && !$done[4],
            5 => $done[4] && !$done[5],
            6 => $done[5] && !$done[6],
            7 => $done[6] && !$done[7],
            8 => $done[7] && !$done[8],
        ];

        $langkah = [];
        for ($i = 1; $i <= 8; $i++) {
            $langkah[] = [
                'no' => $i,
                'status' => $done[$i] ? 'done' : ($active[$i] ? 'active' : 'locked'),
            ];
        }
        return $langkah;
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    private function skemaRingkas($id): ?array
    {
        if (empty($id)) {
            return null;
        }
        $s = DB::table('skema_kkni')->where('id', $id)->first(['id', 'kode_skema', 'judul']);
        return $s ? ['id' => (int) $s->id, 'kode_skema' => $s->kode_skema, 'judul' => $s->judul] : null;
    }

    private function fmtDate($d): ?string
    {
        if (empty($d) || $d === '0000-00-00') {
            return null;
        }
        return date('Y-m-d', strtotime($d));
    }

    private function fmtDateIndo($d): ?string
    {
        if (empty($d) || $d === '0000-00-00') {
            return null;
        }
        $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $t = strtotime($d);
        return date('j', $t) . ' ' . $bulan[(int) date('n', $t)] . ' ' . date('Y', $t);
    }
}
