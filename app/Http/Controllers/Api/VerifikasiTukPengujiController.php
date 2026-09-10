<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Jadwal Verifikasi TUK — Portal PENGUJI (sebagai VERIFIKATOR TUK)
 * Implementasi modul `verifikasituk` + `inputceklist` PHP Native versi API.
 * Sesuai docs/BACKEND_JADWAL_VERIFIKASI_TUK.md:
 *
 * - Daftar tugas dari `asesor_verifikatortuk` WHERE id_asesor (BUKAN jadwal_asesor!)
 * - Kartu: jadwal + TUK + skema + tanggal uji vs tanggal verifikasi +
 *   tim asesor penguji (jadwal_asesor) + tim verifikator + status keputusan
 *   masing-masing (Y=Sesuai / N=Tidak / P=Belum)
 * - Form ceklis dinamis dari `skema_persyaratantuk` per skema + pre-fill
 *   `skema_ceklisvertuk` (re-verifikasi aman)
 * - Submit: upsert ceklis per perlengkapan + UPDATE keputusanverifikasi
 *
 * Perbaikan atas native:
 * - Validasi jumlah >= baik + rusak (native tidak divalidasi)
 * - Null-guard tuk/skema/skkni
 * - Read-only terhadap master jadwal (dead-code handler dihapus)
 */
class VerifikasiTukPengujiController extends Controller
{
    // ════════════════════════════════════════════════════════════════
    // DAFTAR TUGAS VERIFIKASI (padanan module verifikasituk, CQ #1)
    // ════════════════════════════════════════════════════════════════

    public function index(Request $request): JsonResponse
    {
        [$asesor, $error] = $this->resolveVerifikator($request);
        if ($error) {
            return $error;
        }

        $rows = DB::table('asesor_verifikatortuk as v')
            ->join('jadwal_asesmen as j', 'j.id', '=', 'v.id_jadwal')
            ->leftJoin('tuk as t', 't.id', '=', DB::raw("NULLIF(j.tempat_asesmen, '')"))
            ->leftJoin('skema_kkni as sk', 'sk.id', '=', DB::raw("NULLIF(v.id_skemakkni, '')"))
            ->leftJoin('skkni as skk', 'skk.id', '=', 'sk.id_skkni')
            ->where('v.id_asesor', $asesor->id)
            ->orderByDesc('v.tgl_verifikasi')
            ->selectRaw("
                v.id AS penugasan_id, v.id_jadwal, v.id_skemakkni, v.tgl_verifikasi,
                v.no_surattugas, v.tgl_surattugas, v.file_surattugas,
                v.keputusanverifikasi,
                j.nama_kegiatan, j.tahun, j.periode, j.gelombang,
                j.id_skemakkni AS jadwal_skemakkni,
                j.tgl_asesmen, j.tgl_asesmen_akhir, j.jam_asesmen,
                j.tempat_asesmen, j.kapasitas, j.status AS jadwal_status,
                t.nama AS tuk_nama, t.alamat AS tuk_alamat, t.kelurahan AS tuk_kelurahan,
                sk.kode_skema, sk.judul AS skema_judul, skk.no_skkni AS jenis_standar,
                (SELECT COUNT(*) FROM asesi_asesmen a WHERE a.id_jadwal = j.id) AS jumlah_peserta
            ")
            ->get();

        $jadwal = $rows->map(function ($v) use ($asesor) {
            $namaKegiatan = trim((string) $v->nama_kegiatan) !== ''
                ? $v->nama_kegiatan
                : trim("{$v->periode} {$v->tahun} Gelombang {$v->gelombang}");

            // Tim asesor penguji (jadwal_asesor × asesor)
            $timPenguji = DB::table('jadwal_asesor as ja')
                ->join('asesor as a', 'a.id', '=', 'ja.id_asesor')
                ->where('ja.id_jadwal', $v->id_jadwal)
                ->get(['a.id', 'a.nama', 'a.gelar_depan', 'a.gelar_blk'])
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'nama_lengkap' => trim(($a->gelar_depan ? $a->gelar_depan . ' ' : '') . $a->nama . ($a->gelar_blk ? ', ' . $a->gelar_blk : '')),
                ]);

            // Tim verifikator + status keputusan masing-masing (§B.4)
            $timVerifikator = DB::table('asesor_verifikatortuk as v')
                ->join('asesor as a', 'a.id', '=', DB::raw("CAST(v.id_asesor AS UNSIGNED)"))
                ->where('v.id_jadwal', $v->id_jadwal)
                ->get(['a.id', 'a.nama', 'a.gelar_depan', 'a.gelar_blk', 'v.keputusanverifikasi'])
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'nama_lengkap' => trim(($a->gelar_depan ? $a->gelar_depan . ' ' : '') . $a->nama . ($a->gelar_blk ? ', ' . $a->gelar_blk : '')),
                    'keputusan' => $a->keputusanverifikasi,                       // P | Y | N
                    'keputusan_label' => $this->keputusanLabel($a->keputusanverifikasi),
                ]);

            // Progress ceklis verifikator ini (CQ #3)
            $sudahDicek = DB::table('skema_ceklisvertuk')
                ->where('id_asesor', $asesor->id)
                ->where('id_jadwal', $v->id_jadwal)
                ->count();
            $idSkema = $v->id_skemakkni ?: $v->jadwal_skemakkni;
            $totalPerlengkapan = DB::table('skema_persyaratantuk')
                ->where('id_skemakkni', $idSkema)
                ->count();

            $suratUrl = null;
            if (!empty($v->file_surattugas)) {
                $path = public_path('foto_surat/' . $v->file_surattugas);
                $suratUrl = file_exists($path) ? asset('foto_surat/' . $v->file_surattugas) : null;
            }

            return [
                'penugasan_id' => $v->penugasan_id,
                'id_jadwal' => $v->id_jadwal,
                'nama_kegiatan' => $namaKegiatan,
                'tgl_uji' => $this->tglIndo($v->tgl_asesmen),
                'tgl_asesmen' => $v->tgl_asesmen,
                'jam_asesmen' => $v->jam_asesmen,
                // ⭐ Tanggal verifikasi dari penugasan
                'tgl_verifikasi' => $v->tgl_verifikasi,
                'tgl_verifikasi_formatted' => $this->tglIndo($v->tgl_verifikasi),
                'tempat' => [
                    'nama' => $v->tuk_nama ?? $v->tempat_asesmen,
                    'alamat' => $v->tuk_alamat,
                    'kelurahan' => $v->tuk_kelurahan,
                ],
                'skema' => $v->kode_skema || $v->skema_judul ? [
                    'kode_skema' => $v->kode_skema,
                    'judul' => $v->skema_judul,
                    'jenis_standar' => $v->jenis_standar,
                ] : null,
                'kapasitas' => $v->kapasitas ? (int) $v->kapasitas : null,
                'jumlah_peserta' => (int) $v->jumlah_peserta,
                'jadwal_status' => $v->jadwal_status,
                'tim_penguji' => $timPenguji,
                'tim_verifikator' => $timVerifikator,
                // Keputusan verifikator INI
                'keputusan' => $v->keputusanverifikasi,
                'keputusan_label' => $this->keputusanLabel($v->keputusanverifikasi),
                // Progress ceklis
                'ceklis_progress' => ['sudah' => $sudahDicek, 'total' => $totalPerlengkapan],
                'surat_tugas_url' => $suratUrl,
                'punya_peserta' => (int) $v->jumlah_peserta > 0,
                // Aksi kondisional (hanya jika ada peserta — idem native)
                'aksi' => (int) $v->jumlah_peserta > 0 ? [
                    ['label' => in_array($v->keputusanverifikasi, ['Y', 'N']) ? 'Lihat / Edit Ceklis Verifikasi TUK' : 'Input Ceklis Verifikasi TUK', 'url' => "/peserta-verifikasi-tuk/ceklis/{$v->id_jadwal}"],
                    ['label' => 'Berita Acara & Ceklis', 'url' => "/unduh-ceklis?idj={$v->id_jadwal}", 'tipe' => 'pdf'],
                    ['label' => 'Surat Tugas Verifikasi', 'url' => "/unduh-surattugas-vertuk?idj={$v->id_jadwal}", 'tipe' => 'pdf'],
                ] : [],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'asesor' => [
                    'id' => $asesor->id,
                    'nama_lengkap' => $asesor->full_name,
                ],
                'jumlah_tugas' => $jadwal->count(),
                'verifikasi' => $jadwal,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // FORM CEKLIS DINAMIS (padanan module inputceklist render, CQ #2)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /ceklis/{idJadwal}
     * Master persyaratan TUK per skema + pre-fill ceklis existing verifikator ini.
     */
    public function ceklis(Request $request, $idJadwal): JsonResponse
    {
        [$asesor, $penugasan, $jadwal, $error] = $this->resolvePenugasan($request, $idJadwal);
        if ($error) {
            return $error;
        }

        $idSkema = $penugasan->id_skemakkni;

        // Master persyaratan + pre-fill ceklis verifikator ini (LEFT JOIN, CQ #2)
        $perlengkapan = DB::table('skema_persyaratantuk as p')
            ->leftJoin('skema_ceklisvertuk as c', function ($join) use ($idJadwal, $asesor) {
                $join->on('c.id_perlengkapan', '=', 'p.id')
                    ->where('c.id_jadwal', $idJadwal)
                    ->where('c.id_asesor', $asesor->id);
            })
            ->where('p.id_skemakkni', $idSkema)
            ->orderBy('p.id')
            ->selectRaw("
                p.id, p.perlengkapan, p.spesifikasi,
                c.jumlah, c.baik, c.rusak, c.keterangan
            ")
            ->get()
            ->map(function ($p) {
                return [
                    'id_perlengkapan' => (int) $p->id,
                    'perlengkapan' => $p->perlengkapan,
                    'spesifikasi' => $p->spesifikasi,
                    // pre-fill (null = belum diisi)
                    'jumlah' => $p->jumlah !== null ? (int) $p->jumlah : null,
                    'baik' => $p->baik !== null ? (int) $p->baik : null,
                    'rusak' => $p->rusak !== null ? (int) $p->rusak : null,
                    'keterangan' => $p->keterangan,
                ];
            });

        $sudahDicek = $perlengkapan->whereNotNull('jumlah')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'id_jadwal' => (int) $idJadwal,
                'skema' => [
                    'id' => $idSkema,
                    'kode_skema' => $jadwal->kode_skema ?? null,
                    'judul' => $jadwal->skema_judul ?? null,
                ],
                'keputusan' => $penugasan->keputusanverifikasi,   // P | Y | N (pre-checked radio)
                'keputusan_label' => $this->keputusanLabel($penugasan->keputusanverifikasi),
                'sudah_diverifikasi' => in_array($penugasan->keputusanverifikasi, ['Y', 'N']),
                'waktu_verifikasi' => $penugasan->waktu,
                'progress' => ['sudah' => $sudahDicek, 'total' => $perlengkapan->count()],
                'perlengkapan' => $perlengkapan,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // SUBMIT CEKLIS + KEPUTUSAN (padanan handler simpanverifikasi)
    // ════════════════════════════════════════════════════════════════

    /**
     * POST /ceklis/{idJadwal}
     * Body: { keputusanverifikasi: "Y"|"N", items: [ { id_perlengkapan, jumlah, baik, rusak, keterangan } ] }
     *
     * Upsert ceklis per perlengkapan (re-verifikasi aman) + UPDATE keputusan.
     * ✅ Validasi jumlah >= baik + rusak (fix native yang tidak divalidasi).
     */
    public function simpanCeklis(Request $request, $idJadwal): JsonResponse
    {
        [$asesor, $penugasan, $jadwal, $error] = $this->resolvePenugasan($request, $idJadwal);
        if ($error) {
            return $error;
        }

        $idSkema = $penugasan->id_skemakkni;

        $validator = Validator::make($request->all(), [
            'keputusanverifikasi' => 'required|in:Y,N',
            'items' => 'required|array|min:1',
            'items.*.id_perlengkapan' => 'required|integer|exists:skema_persyaratantuk,id',
            'items.*.jumlah' => 'required|integer|min:0',
            'items.*.baik' => 'required|integer|min:0',
            'items.*.rusak' => 'required|integer|min:0',
            'items.*.keterangan' => 'nullable|string',
        ], [
            'keputusanverifikasi.required' => 'Keputusan verifikasi wajib dipilih (Sesuai/Tidak Sesuai)',
            'items.required' => 'Data ceklis perlengkapan wajib diisi',
        ]);

        $validator->after(function ($v) use ($request) {
            foreach ($request->input('items', []) as $i => $item) {
                $jumlah = (int) ($item['jumlah'] ?? 0);
                $baik = (int) ($item['baik'] ?? 0);
                $rusak = (int) ($item['rusak'] ?? 0);
                if ($jumlah < $baik + $rusak) {
                    $v->errors()->add("items.{$i}.jumlah",
                        'Jumlah total tidak boleh lebih kecil dari (baik + rusak).');
                }
            }
        });

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Semua perlengkapan harus milik skema penugasan
        $validIds = DB::table('skema_persyaratantuk')
            ->where('id_skemakkni', $idSkema)
            ->pluck('id')
            ->all();

        DB::beginTransaction();
        try {
            foreach ($request->items as $item) {
                $idPerlengkapan = (int) $item['id_perlengkapan'];
                if (!in_array($idPerlengkapan, $validIds)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Perlengkapan {$idPerlengkapan} bukan bagian dari skema ini",
                    ], 422);
                }

                // Upsert (re-verifikasi aman — idem native)
                $exists = DB::table('skema_ceklisvertuk')
                    ->where('id_asesor', $asesor->id)
                    ->where('id_jadwal', $idJadwal)
                    ->where('id_perlengkapan', $idPerlengkapan)
                    ->exists();

                $payload = [
                    'jumlah' => (int) $item['jumlah'],
                    'baik' => (int) $item['baik'],
                    'rusak' => (int) $item['rusak'],
                    'keterangan' => $item['keterangan'] ?? null,
                ];

                if ($exists) {
                    DB::table('skema_ceklisvertuk')
                        ->where('id_asesor', $asesor->id)
                        ->where('id_jadwal', $idJadwal)
                        ->where('id_perlengkapan', $idPerlengkapan)
                        ->update($payload + ['waktu' => now()]);
                } else {
                    DB::table('skema_ceklisvertuk')->insert($payload + [
                        'id_asesor' => $asesor->id,
                        'id_jadwal' => $idJadwal,
                        'id_skemakkni' => $idSkema,
                        'id_perlengkapan' => $idPerlengkapan,
                        'waktu' => now(),
                    ]);
                }
            }

            // STEP 2: UPDATE keputusan (P → Y/N)
            DB::table('asesor_verifikatortuk')
                ->where('id_asesor', $asesor->id)
                ->where('id_jadwal', $idJadwal)
                ->update([
                    'keputusanverifikasi' => $request->keputusanverifikasi,
                    'waktu' => now(),
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Verifikasi TUK berhasil disimpan',
                'data' => [
                    'id_jadwal' => (int) $idJadwal,
                    'keputusan' => $request->keputusanverifikasi,
                    'keputusan_label' => $request->keputusanverifikasi === 'Y'
                        ? 'Sesuai Persyaratan Skema' : 'Tidak Sesuai Persyaratan Skema',
                    'perlengkapan_dicek' => count($request->items),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan verifikasi TUK',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    /**
     * Resolve verifikator dari token (tanpa cek penugasan).
     */
    private function resolveVerifikator(Request $request): array
    {
        $user = $request->user();
        if (!$user) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401)];
        }
        if (!$user->isPenguji()) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus penguji.',
            ], 403)];
        }

        $asesor = Asesor::where('no_ktp', $user->username)
            ->orWhere('no_ktp', $user->no_ktp)
            ->first();

        if (!$asesor) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Profil penguji tidak ditemukan.',
            ], 404)];
        }

        return [$asesor, null];
    }

    /**
     * Resolve verifikator + penugasan + jadwal (ownership via asesor_verifikatortuk).
     * Return [asesor, penugasan, jadwalInfo, errorResponse]
     */
    private function resolvePenugasan(Request $request, $idJadwal): array
    {
        [$asesor, $error] = $this->resolveVerifikator($request);
        if ($error) {
            return [null, null, null, $error];
        }

        $penugasan = DB::table('asesor_verifikatortuk')
            ->where('id_asesor', $asesor->id)
            ->where('id_jadwal', $idJadwal)
            ->first();

        if (!$penugasan) {
            return [null, null, null, response()->json([
                'success' => false,
                'message' => 'Anda tidak ditugaskan sebagai verifikator pada jadwal ini',
            ], 403)];
        }

        $jadwal = DB::table('jadwal_asesmen as j')
            ->leftJoin('skema_kkni as sk', 'sk.id', '=', DB::raw("NULLIF(j.id_skemakkni, '')"))
            ->where('j.id', $idJadwal)
            ->selectRaw("j.id, j.id_skemakkni, sk.kode_skema, sk.judul AS skema_judul")
            ->first();

        if (!$jadwal) {
            return [null, null, null, response()->json([
                'success' => false,
                'message' => 'Jadwal tidak ditemukan',
            ], 404)];
        }

        return [$asesor, $penugasan, $jadwal, null];
    }

    private function keputusanLabel($keputusan): string
    {
        return match ($keputusan) {
            'Y' => 'Sesuai Persyaratan Skema',
            'N' => 'Tidak Sesuai Persyaratan Skema',
            default => 'Belum Dilaksanakan Verifikasi',
        };
    }

    private function tglIndo($tgl): ?string
    {
        if (empty($tgl) || $tgl === '0000-00-00') {
            return null;
        }
        $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $t = strtotime($tgl);
        return date('j', $t) . ' ' . $bulan[(int) date('n', $t)] . ' ' . date('Y', $t);
    }
}
