<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Komite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard Komite Teknis (Portal KOMITE TEKNIS) — halaman agregasi
 * GET /api/v1/komite-teknis/dashboard  (auth:sanctum, level komite-teknis)
 * Alias: GET /api/v1/auth/komite-teknis/dashboard
 * Sesuai docs/BACKEND_DASHBOARD_KOMITE_TEKNIS.md:
 *
 * - 1 endpoint menyatukan profil + 3 kartu statistik + rekap status
 * - KONSISTENSI ANGKA (kunci desain): definisi tiap count memakai query yang
 *   SAMA dengan endpoint tujuan kartu —
 *   · jadwal_count  = COUNT jadwal_asesmen (idem baris GET /komite-teknis/jadwal;
 *     endpoint itu menampilkan SEMUA jadwal tanpa filter, jadi count-nya pun
 *     tanpa filter agar angka tidak kontradiktif saat kartu diklik)
 *   · skema_count   = COUNT DISTINCT id_skemakkni dari himpunan jadwal yang sama
 *   · rekap/counts  = klasifikasi status_key LINE-BY-LINE dari
 *     GET /komite-teknis/hasil-asesmen (join penilaian_asesi + komite_keputusan,
 *     dedup per jadwal×peserta, urutan cek K → TL → BK)
 *   · keputusan_count = COUNT komite_keputusan WHERE id_komite (kolom aktual
 *     `id_komite` — BUKAN `id_asesor` yang tidak ada di tabel legacy)
 * - Profil + lisensi via accessor Komite (full_name sanitasi double-gelar,
 *   status_lisensi AKTIF/SEGERA/KADALUARSA, warna_kartu)
 */
class KomiteDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // ── Guard level komite-teknis ──
        // (pola user('sanctum') ?? user() — idem storeRekomendasi & profil update)
        $user = $request->user('sanctum') ?? $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }
        if (!$user->isKomiteTeknis()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus Komite Teknis.',
            ], 403);
        }

        // ── Resolve komite dari token (idem login 3-opsi: no_ktp/no_hp/no_induk) ──
        $komite = Komite::where(function ($q) use ($user) {
            $q->where('no_ktp', $user->username)
                ->orWhere('no_hp', $user->username)
                ->orWhere('no_induk', $user->username)
                ->orWhere('email', $user->username);
            if (!empty($user->no_ktp) && $user->no_ktp !== $user->username) {
                $q->orWhere('no_ktp', $user->no_ktp);
            }
        })->first();

        // Defensive: token valid tapi profil komite belum ada → 200 data kosong
        if (!$komite) {
            return response()->json([
                'success' => true,
                'data' => [
                    'komite' => null,
                    'statistik' => $this->emptyStatistik(),
                    'rekap' => $this->emptyRekap(),
                    'terakhir_diperbarui' => now()->toIso8601String(),
                ],
            ]);
        }

        // ════════════════════════════════════════════════════════════
        // STATISTIK (3 kartu)
        // ════════════════════════════════════════════════════════════

        // Ambil ID jadwal yang ditugaskan kepada komite ini
        $assignedJadwalIds = DB::table('jadwal_komite')
            ->where('id_komite', $komite->id)
            ->pluck('id_jadwal')
            ->toArray();

        // Kartu 1 — Jadwal Aktif: hanya jadwal yang ditugaskan ke komite ini (konsisten dgn GET /komite-teknis/jadwal)
        $jadwalCount = DB::table('jadwal_asesmen')
            ->whereIn('id', $assignedJadwalIds)
            ->count();

        // Kartu 2 — Skema Kompetensi: DISTINCT skema dari jadwal yang ditugaskan
        $skemaCount = DB::table('jadwal_asesmen')
            ->whereIn('id', $assignedJadwalIds)
            ->whereNotNull('id_skemakkni')
            ->where('id_skemakkni', '!=', '')
            ->distinct()
            ->count('id_skemakkni');

        // Kartu 3 — Peserta Diputuskan: keputusan yang tersimpan oleh komite ini
        // (kolom aktual = id_komite; idem perbaikan count kartu profil)
        $keputusanCount = DB::table('komite_keputusan')
            ->where('id_komite', (string) $komite->id)
            ->count();

        // ════════════════════════════════════════════════════════════
        // REKAP STATUS — klasifikasi IDENTIK dgn GET /komite-teknis/hasil-asesmen
        // (join + dedup + urutan cek K → TL → BK sama persis, blok counts §index)
        // ════════════════════════════════════════════════════════════
        $rows = DB::table('asesi_asesmen')
            ->whereIn('asesi_asesmen.id_jadwal', $assignedJadwalIds)
            ->leftJoin('penilaian_asesi', function ($join) {
                $join->on('asesi_asesmen.id', '=', 'penilaian_asesi.id_asesmen')
                    ->orWhere(function ($q) {
                        $q->on('asesi_asesmen.id_jadwal', '=', 'penilaian_asesi.id_jadwal')
                            ->where(function ($q2) {
                                $q2->on('asesi_asesmen.id_asesi', '=', 'penilaian_asesi.no_pendaftaran')
                                    ->orWhere(DB::raw("REPLACE(REPLACE(asesi_asesmen.id_asesi, 'REG-', ''), '-', '')"), '=', DB::raw("REPLACE(REPLACE(penilaian_asesi.no_pendaftaran, 'REG-', ''), '-', '')"));
                            });
                    });
            })
            ->leftJoin('komite_keputusan', function ($join) {
                $join->on('asesi_asesmen.id_jadwal', '=', 'komite_keputusan.id_jadwal')
                    ->where(function ($q) {
                        $q->on('asesi_asesmen.id_asesi', '=', 'komite_keputusan.id_asesi')
                            ->orOn('asesi_asesmen.id', '=', 'komite_keputusan.id_asesi')
                            ->orWhere(DB::raw("REPLACE(REPLACE(asesi_asesmen.id_asesi, 'REG-', ''), '-', '')"), '=', DB::raw("REPLACE(REPLACE(komite_keputusan.id_asesi, 'REG-', ''), '-', '')"));
                    });
            })
            // Hanya peserta SUDAH dinilai penguji (idem index: baris penilaian riil)
            ->whereNotNull('penilaian_asesi.id')
            ->whereNotNull('penilaian_asesi.total_skor')
            ->select([
                'asesi_asesmen.id as id_asesmen',
                'asesi_asesmen.id_jadwal',
                'asesi_asesmen.id_asesi as reg_asesi',
                'asesi_asesmen.status_asesmen as status_asesi_asesmen',
                'komite_keputusan.keputusan as keputusan_komite',
            ])
            ->get();

        $rekap = $this->emptyRekap();
        $uniqueKeys = [];
        foreach ($rows as $item) {
            // Dedup per peserta×jadwal (idem index: key = id_jadwal + reg ?: id_asesmen)
            $dedupKey = $item->id_jadwal.'_'.($item->reg_asesi ?: $item->id_asesmen);
            if (isset($uniqueKeys[$dedupKey])) {
                continue;
            }
            $uniqueKeys[$dedupKey] = true;

            // Urutan cek IDENTIK dgn index() — "Perbaikan" sebelum "Direkomendasikan"
            if ($item->keputusan_komite === 'K' || $item->status_asesi_asesmen === 'K') {
                $rekap['disetujui']++;
            } elseif ($item->keputusan_komite === 'TL' || $item->status_asesi_asesmen === 'TL') {
                $rekap['perbaikan']++;
            } elseif ($item->keputusan_komite === 'BK') {
                $rekap['tidak_direkomendasikan']++;
            } else {
                // termasuk: asesor rekomendasi BK tapi komite belum memutuskan → menunggu
                $rekap['menunggu']++;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'komite' => [
                    'id' => $komite->id,
                    'nama_lengkap' => $komite->full_name,
                    'no_induk' => $komite->no_induk,
                    'no_lisensi' => $komite->no_lisensi,
                    'jabatan_komite' => $komite->jabatan_komite ?: 'Anggota',
                    'lisensi' => [
                        'masaberlaku' => $komite->masaberlaku_lisensi
                            ? optional($komite->masaberlaku_lisensi)->format('Y-m-d')
                            : null,
                        'sisa_hari' => $komite->sisa_hari_lisensi,
                        'status' => $komite->status_lisensi,
                        'warna_kartu' => $komite->warna_kartu,
                    ],
                ],
                'statistik' => [
                    'jadwal_count' => (int) $jadwalCount,
                    'skema_count' => (int) $skemaCount,
                    'keputusan_count' => (int) $keputusanCount,
                ],
                'rekap' => $rekap,
                'terakhir_diperbarui' => now()->toIso8601String(),
            ],
        ]);
    }

    private function emptyStatistik(): array
    {
        return [
            'jadwal_count' => 0,
            'skema_count' => 0,
            'keputusan_count' => 0,
        ];
    }

    private function emptyRekap(): array
    {
        return [
            'menunggu' => 0,
            'disetujui' => 0,
            'perbaikan' => 0,
            'tidak_direkomendasikan' => 0,
        ];
    }
}
