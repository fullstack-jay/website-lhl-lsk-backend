<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Jadwal Meninjau Instrumen Asesmen (FR.IA.11) — Portal PENGUJI
 * Implementasi modul `peninjauasesmen` + `tinjauia11` + `form-fr-ia-11`
 * PHP Native versi API. Sesuai docs/BACKEND_JADWAL_MENINJAU_INSTRUMEN.md:
 *
 * - Penugasan PER-PESERTA via kolom `asesi_asesmen.peninjau_ia11`
 *   (pola unik — bukan pivot; daftar jadwal pakai DISTINCT)
 * - 3 halaman: daftar jadwal → daftar peserta (+ badge APL-02 & keputusan,
 *   kontak peserta) → form ceklis 8 pertanyaan (skema_meninjauasesmen)
 * - Jawaban tersimpan di `asesmen_ia11` per pertanyaan
 *   + komentar umum ke `asesi_asesmen.komentar_ia11`
 *
 * Perbaikan atas native (semua BUG PRIORITAS docs):
 * - ✅ Fix kolom `tanggapan` tidak ada → simpan `komentar`
 * - ✅ Fix update tanpa filter id_pertanyaan (semua baris seragam)
 * - ✅ Hapus id_unitkompetensi undefined dari INSERT
 * - ✅ Satu path simpan untuk semua kasus (replace-all upsert + transaksi)
 * - ✅ Prepared statements / Query Builder
 */
class MeninjauInstrumenController extends Controller
{
    // ════════════════════════════════════════════════════════════════
    // 1. DAFTAR JADWAL (padanan module peninjauasesmen)
    // ════════════════════════════════════════════════════════════════

    public function index(Request $request): JsonResponse
    {
        [$asesor, $error] = $this->resolveAsesor($request);
        if ($error) {
            return $error;
        }

        // Daftar jadwal via kolom peninjau_ia11 (DISTINCT, idem native CQ #1)
        $rows = DB::table('asesi_asesmen as a')
            ->join('jadwal_asesmen as j', 'j.id', '=', 'a.id_jadwal')
            ->leftJoin('tuk as t', 't.id', '=', DB::raw("NULLIF(j.tempat_asesmen, '')"))
            ->leftJoin('skema_kkni as sk', 'sk.id', '=', DB::raw("NULLIF(j.id_skemakkni, '')"))
            ->leftJoin('skkni as skk', 'skk.id', '=', 'sk.id_skkni')
            ->where('a.peninjau_ia11', $asesor->id)
            ->whereNotNull('a.id_jadwal')
            ->selectRaw("
                j.id, j.nama_kegiatan, j.tahun, j.periode, j.gelombang,
                j.tgl_asesmen, j.tgl_asesmen_akhir, j.jam_asesmen,
                j.tempat_asesmen, j.kapasitas, j.status AS jadwal_status,
                t.nama AS tuk_nama, t.alamat AS tuk_alamat,
                sk.kode_skema, sk.judul AS skema_judul, skk.no_skkni AS jenis_standar,
                COUNT(DISTINCT a.id_asesi) AS jumlah_peserta
            ")
            ->groupBy('j.id', 'j.nama_kegiatan', 'j.tahun', 'j.periode', 'j.gelombang',
                'j.tgl_asesmen', 'j.tgl_asesmen_akhir', 'j.jam_asesmen',
                'j.tempat_asesmen', 'j.kapasitas', 'j.status',
                't.nama', 't.alamat', 'sk.kode_skema', 'sk.judul', 'skk.no_skkni')
            ->orderByRaw('j.tgl_asesmen DESC')
            ->get();

        $jadwal = $rows->map(function ($j) {
            $namaKegiatan = trim((string) $j->nama_kegiatan) !== ''
                ? $j->nama_kegiatan
                : trim("{$j->periode} {$j->tahun} Gelombang {$j->gelombang}");

            // Tim asesor (jadwal_asesor — info pelengkap)
            $timPenguji = DB::table('jadwal_asesor as ja')
                ->join('asesor as a', 'a.id', '=', 'ja.id_asesor')
                ->where('ja.id_jadwal', $j->id)
                ->get(['a.id', 'a.nama', 'a.gelar_depan', 'a.gelar_blk'])
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'nama_lengkap' => trim(($a->gelar_depan ? $a->gelar_depan . ' ' : '') . $a->nama . ($a->gelar_blk ? ', ' . $a->gelar_blk : '')),
                ]);

            return [
                'id_jadwal' => $j->id,
                'nama_kegiatan' => $namaKegiatan,
                'tanggal' => [
                    'tgl_asesmen' => $j->tgl_asesmen,
                    'tgl_asesmen_formatted' => $this->tglIndo($j->tgl_asesmen),
                    'tgl_asesmen_akhir' => $j->tgl_asesmen_akhir,
                    'jam_asesmen' => $j->jam_asesmen,
                ],
                'tempat' => [
                    'nama' => $j->tuk_nama ?? $j->tempat_asesmen,
                    'alamat' => $j->tuk_alamat,
                ],
                'skema' => $j->kode_skema || $j->skema_judul ? [
                    'kode_skema' => $j->kode_skema,
                    'judul' => $j->skema_judul,
                    'jenis_standar' => $j->jenis_standar,
                ] : null,
                'kapasitas' => $j->kapasitas ? (int) $j->kapasitas : null,
                'jumlah_peserta' => (int) $j->jumlah_peserta,
                'jadwal_status' => $j->jadwal_status,
                'tim_penguji' => $timPenguji,
                'punya_peserta' => (int) $j->jumlah_peserta > 0,
                // Aksi satu item saja di level jadwal (idem native)
                'aksi' => (int) $j->jumlah_peserta > 0 ? [
                    ['label' => 'Lihat Peserta', 'url' => "/peserta/tinjau-ia11/{$j->id}"],
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
                'jumlah_jadwal' => $jadwal->count(),
                'jadwal' => $jadwal,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // 2. DAFTAR PESERTA JADWAL (padanan module tinjauia11)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/auth/penguji/tinjau-ia11/{idJadwal}
     * Peserta yang ditugaskan ke penguji ini (peninjau_ia11) + badge status
     * APL-02 & keputusan asesmen + kontak (idem native).
     */
    public function peserta(Request $request, $idJadwal): JsonResponse
    {
        [$asesor, $error] = $this->resolveAsesor($request);
        if ($error) {
            return $error;
        }

        // Info jadwal + panel (skema/periode/TUK/tim/standar kompetensi)
        $jadwal = DB::table('jadwal_asesmen as j')
            ->leftJoin('tuk as t', 't.id', '=', DB::raw("NULLIF(j.tempat_asesmen, '')"))
            ->leftJoin('skema_kkni as sk', 'sk.id', '=', DB::raw("NULLIF(j.id_skemakkni, '')"))
            ->leftJoin('skkni as skk', 'skk.id', '=', 'sk.id_skkni')
            ->where('j.id', $idJadwal)
            ->selectRaw("
                j.id, j.nama_kegiatan, j.tahun, j.periode, j.gelombang,
                j.tgl_asesmen, j.tgl_asesmen_akhir, j.jam_asesmen,
                j.tempat_asesmen, j.kapasitas, j.dok_standarkompetensi,
                t.nama AS tuk_nama, t.alamat AS tuk_alamat,
                sk.kode_skema, sk.judul AS skema_judul, skk.no_skkni AS jenis_standar
            ")
            ->first();

        if (!$jadwal) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal tidak ditemukan',
            ], 404);
        }

        // Hanya peserta yang ditugaskan ke asesor ini (idem native filter peninjau)
        $pesertaRows = DB::table('asesi_asesmen as a')
            ->join('asesi as x', 'x.no_pendaftaran', '=', 'a.id_asesi')
            ->where('a.id_jadwal', $idJadwal)
            ->where('a.peninjau_ia11', $asesor->id)
            ->orderBy('x.nama')
            ->selectRaw("
                a.id_asesi, a.status, a.status_asesmen, a.keputusan_asesor,
                a.komentar_ia11,
                x.nama, x.nohp, x.email
            ")
            ->get();

        $peserta = $pesertaRows->map(function ($p) use ($idJadwal) {
            // Badge 1: APL-02 (idem native — COUNT asesi_apl02)
            $apl02Count = DB::table('asesi_apl02')
                ->where('id_asesi', $p->id_asesi)
                ->count();

            // Progress FR.IA.11 peserta ini (CQ #2)
            $dijawab = DB::table('asesmen_ia11')
                ->where('id_asesi', $p->id_asesi)
                ->where('id_jadwal', $idJadwal)
                ->distinct()
                ->count('id_pertanyaan');

            // Kontak WhatsApp URL (pola 0→62)
            $waNumber = null;
            if (!empty($p->nohp)) {
                $nohp = preg_replace('/[^0-9]/', '', (string) $p->nohp);
                $waNumber = substr($nohp, 0, 1) === '0' ? '62' . substr($nohp, 1) : $nohp;
            }

            // Keputusan asesmen: K/BK/TL (kolom status_asesmen, guard string 'NULL')
            $keputusanAda = !empty($p->status_asesmen) && $p->status_asesmen !== 'P';
            $keputusanLabel = null;
            if ($keputusanAda) {
                $keputusanLabel = match ($p->status_asesmen) {
                    'K' => 'KOMPETEN',
                    'BK' => 'BELUM KOMPETEN',
                    'TL' => 'UJI ULANG / TINDAK LANJUT',
                    default => 'Telah ada keputusan asesmen',
                };
            }

            return [
                'no_pendaftaran' => $p->id_asesi,
                'nama' => $p->nama,
                'nohp' => $p->nohp,
                'email' => $p->email,
                // Badge status (idem native)
                'apl02_sudah_mengisi' => $apl02Count > 0,   // 🔴 merah
                'keputusan_ada' => $keputusanAda,           // 🟢 hijau bila true
                'keputusan_label' => $keputusanLabel,
                // Progress FR.IA.11
                'ia11_pertanyaan_dijawab' => $dijawab,
                'ia11_selesai' => $dijawab >= 8,
                'komentar_ia11' => $p->komentar_ia11,
                // Kontak
                'kontak' => [
                    'telepon' => $p->nohp ? 'tel:' . $p->nohp : null,
                    'whatsapp_url' => $waNumber
                        ? 'https://api.whatsapp.com/send?phone=' . $waNumber : null,
                ],
                // Aksi per peserta (idem native dropdown)
                'aksi' => [
                    ['label' => 'Input Ceklis FR.IA.11',
                     'url' => "/penguji/tinjau-ia11/{$idJadwal}/asesi/{$p->id_asesi}"],
                    ['label' => 'Unduh Ceklis FR.IA.11',
                     'url' => "/form-fr-ia-11?ida={$p->id_asesi}&idj={$idJadwal}", 'tipe' => 'pdf'],
                ],
            ];
        });

        // Tim asesor + dok standar kompetensi (panel info bawah)
        $timPenguji = DB::table('jadwal_asesor as ja')
            ->join('asesor as a', 'a.id', '=', 'ja.id_asesor')
            ->where('ja.id_jadwal', $idJadwal)
            ->get(['a.nama', 'a.no_induk'])
            ->map(fn ($a) => [
                'nama' => $a->nama,
                'no_induk' => $a->no_induk,
            ]);

        $namaLsp = null;
        try {
            $namaLsp = DB::table('identitas')->value('nama_lsp');
        } catch (\Throwable $e) {
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id_jadwal' => (int) $idJadwal,
                'jadwal' => [
                    'nama_kegiatan' => trim((string) $jadwal->nama_kegiatan) !== ''
                        ? $jadwal->nama_kegiatan
                        : trim("{$jadwal->periode} {$jadwal->tahun} Gelombang {$jadwal->gelombang}"),
                    'tgl_asesmen' => $jadwal->tgl_asesmen,
                    'tgl_asesmen_formatted' => $this->tglIndo($jadwal->tgl_asesmen),
                    'tgl_asesmen_akhir' => $jadwal->tgl_asesmen_akhir,
                    'jam_asesmen' => $jadwal->jam_asesmen,
                    'kapasitas' => $jadwal->kapasitas ? (int) $jadwal->kapasitas : null,
                    'tempat' => [
                        'nama' => $jadwal->tuk_nama ?? $jadwal->tempat_asesmen,
                        'alamat' => $jadwal->tuk_alamat,
                    ],
                    'skema' => $jadwal->kode_skema || $jadwal->skema_judul ? [
                        'kode_skema' => $jadwal->kode_skema,
                        'judul' => $jadwal->skema_judul,
                        'jenis_standar' => $jadwal->jenis_standar,
                    ] : null,
                    // Unduh standar kompetensi (cek dok kolom — idem native)
                    'dok_standar_kompetensi_url' => !empty($jadwal->dok_standarkompetensi)
                        ? asset('foto_dokskkni/' . $jadwal->dok_standarkompetensi) : null,
                ],
                'tim_penguji' => $timPenguji,
                'nama_lsp' => $namaLsp,
                'jumlah_peserta' => $peserta->count(),
                'peserta' => $peserta,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // 3. FORM CEKLIS 8 PERTANYAAN (padanan form-fr-ia-11)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/auth/penguji/tinjau-ia11/{idJadwal}/asesi/{noPendaftaran}
     * Master 8 pertanyaan × pre-fill jawaban/komentar + komentar umum.
     */
    public function form(Request $request, $idJadwal, $noPendaftaran): JsonResponse
    {
        [$asesor, $error] = $this->resolveAsesor($request);
        if ($error) {
            return $error;
        }

        // Ownership: peserta ini ditugaskan ke asesor ini?
        $penugasan = DB::table('asesi_asesmen')
            ->where('id_jadwal', $idJadwal)
            ->where('id_asesi', $noPendaftaran)
            ->where('peninjau_ia11', $asesor->id)
            ->first();

        if (!$penugasan) {
            return response()->json([
                'success' => false,
                'message' => 'Anda bukan peninjau untuk peserta ini pada jadwal ini',
            ], 403);
        }

        $asesi = DB::table('asesi')->where('no_pendaftaran', $noPendaftaran)->first();
        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Data asesi tidak ditemukan',
            ], 404);
        }

        // Master 8 pertanyaan × pre-fill jawaban (CQ #3)
        $pertanyaan = DB::table('skema_meninjauasesmen as q')
            ->leftJoin('asesmen_ia11 as ia', function ($join) use ($noPendaftaran, $idJadwal) {
                $join->on('ia.id_pertanyaan', '=', 'q.id')
                    ->where('ia.id_asesi', $noPendaftaran)
                    ->where('ia.id_jadwal', $idJadwal);
            })
            ->orderBy('q.id')
            ->selectRaw('q.id, q.pertanyaan, ia.jawaban, ia.komentar')
            ->get()
            ->map(fn ($q) => [
                'id_pertanyaan' => (int) $q->id,
                'pertanyaan' => $q->pertanyaan,
                'jawaban' => $q->jawaban,       // pre-fill
                'komentar' => $q->komentar,     // pre-fill
            ]);

        // Tim asesor (untuk header form)
        $timPenguji = DB::table('jadwal_asesor as ja')
            ->join('asesor as a', 'a.id', '=', 'ja.id_asesor')
            ->where('ja.id_jadwal', $idJadwal)
            ->get(['a.nama', 'a.no_induk'])
            ->map(fn ($a) => ['nama' => $a->nama, 'no_induk' => $a->no_induk]);

        return response()->json([
            'success' => true,
            'data' => [
                'asesi' => [
                    'no_pendaftaran' => $asesi->no_pendaftaran,
                    'nama' => $asesi->nama,
                ],
                'id_jadwal' => (int) $idJadwal,
                'komentar_ia11' => $penugasan->komentar_ia11,   // pre-fill komentar umum
                'pertanyaan_dijawab' => $pertanyaan->whereNotNull('jawaban')->count(),
                'pertanyaan' => $pertanyaan,                    // 8 pertanyaan FR.IA.11
                'tim_penguji' => $timPenguji,
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/penguji/tinjau-ia11/{idJadwal}/asesi/{noPendaftaran}
     *
     * Body: {
     *   "jawaban": [ { "id_pertanyaan": 1, "jawaban": "Ya", "komentar": "..." }, ... ],
     *   "komentar_ia11": "komentar umum peninjauan"
     * }
     *
     * Replace-all upsert (fix semua 3 bug branch native):
     * - kolom `komentar` dipakai konsisten (bukan `tanggapan` yang tak ada)
     * - upsert per id_pertanyaan (bukan update tanpa filter)
     * - tanpa id_unitkompetensi undefined
     */
    public function simpan(Request $request, $idJadwal, $noPendaftaran): JsonResponse
    {
        [$asesor, $error] = $this->resolveAsesor($request);
        if ($error) {
            return $error;
        }

        // Ownership check
        $penugasan = DB::table('asesi_asesmen')
            ->where('id_jadwal', $idJadwal)
            ->where('id_asesi', $noPendaftaran)
            ->where('peninjau_ia11', $asesor->id)
            ->first();

        if (!$penugasan) {
            return response()->json([
                'success' => false,
                'message' => 'Anda bukan peninjau untuk peserta ini pada jadwal ini',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'jawaban' => 'required|array|min:8',
            'jawaban.*.id_pertanyaan' => 'required|integer|exists:skema_meninjauasesmen,id',
            'jawaban.*.jawaban' => 'required|string',
            'jawaban.*.komentar' => 'nullable|string',
            'komentar_ia11' => 'nullable|string',
        ], [
            'jawaban.required' => 'Jawaban 8 pertanyaan FR.IA.11 wajib diisi',
            'jawaban.min' => 'Jawaban 8 pertanyaan FR.IA.11 wajib diisi lengkap',
            'jawaban.*.jawaban.required' => 'Setiap pertanyaan wajib punya jawaban',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Skema dari penugasan (id_skemakkni di asesi_asesmen)
        $idSkema = $penugasan->id_skemakkni;

        DB::beginTransaction();
        try {
            // Replace-all: upsert per pertanyaan (fix branch A/B/C native)
            foreach ($request->jawaban as $jwb) {
                $exists = DB::table('asesmen_ia11')
                    ->where('id_asesi', $noPendaftaran)
                    ->where('id_jadwal', $idJadwal)
                    ->where('id_pertanyaan', $jwb['id_pertanyaan'])
                    ->exists();

                $payload = [
                    'jawaban' => $jwb['jawaban'],
                    'komentar' => $jwb['komentar'] ?? null,
                    'waktu' => now(),
                ];

                if ($exists) {
                    // UPDATE dengan filter id_pertanyaan (fix branch A native)
                    DB::table('asesmen_ia11')
                        ->where('id_asesi', $noPendaftaran)
                        ->where('id_jadwal', $idJadwal)
                        ->where('id_pertanyaan', $jwb['id_pertanyaan'])
                        ->update($payload);
                } else {
                    // INSERT tanpa id_unitkompetensi undefined (fix branch C native)
                    DB::table('asesmen_ia11')->insert($payload + [
                        'id_asesi' => $noPendaftaran,
                        'id_skemakkni' => $idSkema,
                        'id_jadwal' => $idJadwal,
                        'id_pertanyaan' => $jwb['id_pertanyaan'],
                    ]);
                }
            }

            // Semua branch: komentar umum ke asesi_asesmen
            DB::table('asesi_asesmen')
                ->where('id_jadwal', $idJadwal)
                ->where('id_asesi', $noPendaftaran)
                ->update(['komentar_ia11' => $request->input('komentar_ia11')]);

            DB::commit();

            $dijawab = DB::table('asesmen_ia11')
                ->where('id_asesi', $noPendaftaran)
                ->where('id_jadwal', $idJadwal)
                ->distinct()->count('id_pertanyaan');

            return response()->json([
                'success' => true,
                'message' => 'Ceklis FR.IA.11 berhasil disimpan',
                'data' => [
                    'no_pendaftaran' => $noPendaftaran,
                    'id_jadwal' => (int) $idJadwal,
                    'pertanyaan_dijawab' => $dijawab,
                    'komentar_ia11' => $request->input('komentar_ia11'),
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan ceklis FR.IA.11',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    private function resolveAsesor(Request $request): array
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
