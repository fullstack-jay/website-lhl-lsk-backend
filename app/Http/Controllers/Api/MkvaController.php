<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Penugasan MKVA (FR.VA — Memberikan Kontribusi dalam Validasi Asesmen)
 * Portal PENGUJI. Implementasi modul `penugasanmkva` + `form-fr-va` +
 * `simpanmkva.php` + `form-fr-va2` PHP Native versi API.
 * Sesuai docs/BACKEND_PENUGASAN_MKVA.md:
 *
 * - Penugasan = 2 kolom jadwal_asesmen (asesor_mkva1/mkva2) — tim selalu 2 orang
 * - Bagian 1: tabel `mkva` (122 kolom) — upsert by id_jadwal
 *   ✅ Semua checkbox dinormalisasi '0'/'1' konsisten (fix native yang
 *      menyimpan '' utk unchecked bagian 1)
 * - Bagian 2: `mkva_temuan` + `mkva_perbaikan` CRUD inline
 *   ✅ Dup-check temuan (idem native), guard hapus
 *
 * Perbaikan bug native:
 * - $jdq undefined (hari kosong) → resolved server-side
 * - Normalisasi checkbox konsisten '0'/'1' semua grup
 * - Transaction wrap (122 kolom upsert anti partial-save)
 */
class MkvaController extends Controller
{
    // ════════════════════════════════════════════════════════════════
    // DAFTAR JADWAL TUGAS MKVA (padanan module penugasanmkva, CQ #1)
    // ════════════════════════════════════════════════════════════════

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }
        if (! $user->isPenguji()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus penguji.',
            ], 403);
        }

        $asesor = Asesor::where('no_ktp', $user->username)
            ->orWhere('no_ktp', $user->no_ktp)
            ->first();

        if (! $asesor) {
            return response()->json([
                'success' => true,
                'data' => [
                    'asesor' => null,
                    'jumlah_jadwal' => 0,
                    'jadwal' => [],
                ],
            ]);
        }

        // CQ #1: jadwal tugas MKVA (validator 1 ATAU 2) + posisi + progress
        $rows = DB::table('jadwal_asesmen as j')
            ->leftJoin('skema_kkni as sk', 'sk.id', '=', DB::raw("NULLIF(j.id_skemakkni, '')"))
            ->leftJoin('tuk as t', 't.id', '=', DB::raw("NULLIF(j.tempat_asesmen, '')"))
            ->leftJoin('mkva as m', 'm.id_jadwal', '=', DB::raw('CAST(j.id AS CHAR)'))
            ->where(function ($q) use ($asesor) {
                $q->where('j.asesor_mkva1', $asesor->id)
                    ->orWhere('j.asesor_mkva2', $asesor->id);
            })
            ->orderByDesc('j.tgl_asesmen')
            ->selectRaw('
                j.id, j.nama_kegiatan, j.tahun, j.periode, j.gelombang,
                j.tgl_asesmen, j.tgl_asesmen_akhir, j.jam_asesmen,
                j.tempat_asesmen, j.asesor_mkva1, j.asesor_mkva2,
                t.nama AS tuk_nama, t.alamat AS tuk_alamat,
                sk.kode_skema, sk.judul AS skema_judul,
                m.id AS mkva_id,
                (SELECT COUNT(*) FROM asesi_asesmen a WHERE a.id_jadwal = j.id) AS jumlah_peserta,
                (SELECT COUNT(*) FROM mkva_temuan t2 WHERE t2.id_jadwal = CAST(j.id AS CHAR)) AS jumlah_temuan,
                (SELECT COUNT(*) FROM mkva_perbaikan p WHERE p.id_jadwal = CAST(j.id AS CHAR)) AS jumlah_perbaikan
            ')
            ->get();

        // Tim validasi 2 orang (CQ #2) — id varchar → cast int utk lookup
        $validatorIds = collect([$rows->pluck('asesor_mkva1'), $rows->pluck('asesor_mkva2')])
            ->flatten()->filter()->unique()
            ->map(fn ($id) => (int) $id)->values();

        $validators = $validatorIds->isNotEmpty()
            ? Asesor::whereIn('id', $validatorIds)->get(['id', 'nama', 'gelar_depan', 'gelar_blk'])
                ->map(function ($a) {
                    $nama = trim(($a->gelar_depan ? $a->gelar_depan.' ' : '').$a->nama.($a->gelar_blk ? ', '.$a->gelar_blk : ''));

                    return ['id' => $a->id, 'nama_lengkap' => $nama];
                })
                ->keyBy('id')
            : collect();

        $jadwal = $rows->map(function ($j) use ($validators, $asesor) {
            $namaKegiatan = trim((string) $j->nama_kegiatan) !== ''
                ? $j->nama_kegiatan
                : trim("{$j->periode} {$j->tahun} Gelombang {$j->gelombang}");

            // Posisi asesor login dlm tim validasi (CQ #1: Validator 1 / 2)
            $posisiSaya = ((int) $j->asesor_mkva1) === (int) $asesor->id
                ? 'Validator 1'
                : 'Validator 2';

            $timValidasi = collect([$j->asesor_mkva1, $j->asesor_mkva2])
                ->filter()
                ->map(function ($id) use ($validators, $j) {
                    return [
                        'posisi' => ((string) $id) === ((string) $j->asesor_mkva1)
                            ? 'Validator 1'
                            : 'Validator 2',
                        'id' => (int) $id,
                        'nama_lengkap' => $validators->get((int) $id)['nama_lengkap'] ?? "Asesor #{$id}",
                    ];
                })
                ->values();

            return [
                'id_jadwal' => $j->id,
                'nama_kegiatan' => $namaKegiatan,
                'tgl_asesmen' => $j->tgl_asesmen,
                'tgl_asesmen_formatted' => $this->tglIndo($j->tgl_asesmen),
                'tgl_asesmen_akhir' => $j->tgl_asesmen_akhir,
                'jam_asesmen' => $j->jam_asesmen,
                'tempat' => [
                    'nama' => $j->tuk_nama ?? $j->tempat_asesmen,
                    'alamat' => $j->tuk_alamat,
                ],
                'skema' => $j->kode_skema || $j->skema_judul ? [
                    'kode_skema' => $j->kode_skema,
                    'judul' => $j->skema_judul,
                ] : null,
                'jumlah_peserta' => (int) $j->jumlah_peserta,
                // Progress validasi (CQ #3)
                'bagian1_terisi' => $j->mkva_id !== null,
                'jumlah_temuan' => (int) $j->jumlah_temuan,
                'jumlah_perbaikan' => (int) $j->jumlah_perbaikan,
                'posisi_saya' => $posisiSaya,
                'tim_validasi' => $timValidasi,
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
    // BAGIAN 1 — GET FORM (pre-fill) / POST SIMPAN (upsert 122 kolom)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/auth/penguji/mkva/{idJadwal}
     * Pre-fill Bagian 1 (mkva row existing) + tim validasi + identitas.
     */
    public function show(Request $request, $idJadwal): JsonResponse
    {
        [$asesor, $error] = $this->resolveOwnership($request, $idJadwal);
        if ($error) {
            return $error;
        }

        $mkva = DB::table('mkva')->where('id_jadwal', (string) $idJadwal)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'id_jadwal' => (int) $idJadwal,
                'bagian1_terisi' => $mkva !== null,
                'tim_validasi' => $this->timValidasi($idJadwal),
                'periode' => $mkva->periode ?? null,
                'form' => $mkva ? $this->normalisasiMkva($mkva) : null,
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/penguji/mkva/{idJadwal}
     * Upsert Bagian 1 — 122 kolom. Semua checkbox dinormalisasi '0'/'1'
     * (konsisten — fix native yang menyimpan '' utk unchecked).
     * Matriks VATM×VRFF (64 kolom) otomatis dari items[].
     */
    public function simpanBagian1(Request $request, $idJadwal): JsonResponse
    {
        [$asesor, $error] = $this->resolveOwnership($request, $idJadwal);
        if ($error) {
            return $error;
        }

        // ── Definisi semua kolom checkbox grup 1 ──
        $checkboxGroups = [
            'tujuan' => ['tujuan_1', 'tujuan_2', 'tujuan_3', 'tujuan_4', 'tujuan_5', 'tujuan_6', 'tujuan_7'],
            'konteks' => ['konteks_1', 'konteks_2', 'konteks_3', 'konteks_4', 'konteks_5', 'konteks_6'],
            'pendekatan' => ['pendekatan_1', 'pendekatan_2', 'pendekatan_3', 'pendekatan_4', 'pendekatan_5', 'pendekatan_6'],
            'orang' => ['askom', 'leadasesor', 'manspv', 'tenagaahli', 'koordtraining', 'asosiasi'],
            'acuan' => ['stdkom', 'sop', 'manualbook', 'stdkinerja', 'lainnya', 'skema', 'skk', 'perangkat', 'peraturan'],
            'keterampilan' => ['proaktif', 'activelistening', 'keterampilan1', 'keterampilan2'],
        ];

        // Text fields opsional
        $textFields = [
            'tujuan_7b', 'konteks_6b',
            'orel_1', 'konfirmorel_1', 'orel_2', 'konfirmorel_2', 'orel_3', 'konfirmorel_3',
            'nama_leadasesor', 'konfirmleadaseesor',
            'nama_manspv', 'konfirmmanspv',
            'nama_tenagaahli', 'konfirmtenagaahli',
            'nama_koordtraining', 'konfirmkoordtraining',
            'nama_asosiasi', 'konfirmasosiasi',
            'nama_lainnya',
            'nama_ketlainnya1', 'nama_ketlainnya2',
        ];

        $validator = Validator::make($request->all(), [
            'periode' => 'required|in:1,2,3',
            'matriks' => 'nullable|array',
            'matriks.*' => 'nullable|integer|min:0|max:1',
        ] + collect($checkboxGroups)->flatten()->mapWithKeys(
            fn ($f) => [$f => 'nullable|in:0,1']
        )->all() + collect($textFields)->mapWithKeys(
            fn ($f) => [$f => 'nullable|string']
        )->all(), [
            'periode.required' => 'Periode validasi wajib dipilih (Sebelum/Saat/Setelah asesmen)',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();

            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $data = [
                'id_jadwal' => (string) $idJadwal,
                'periode' => $request->input('periode'),
                'waktu' => now(),
            ];

            // ── Normalisasi semua checkbox grup 1: '0'/'1' (konsisten) ──
            foreach ($checkboxGroups as $fields) {
                foreach ($fields as $field) {
                    $data[$field] = $request->filled($field) && $request->input($field) == '1' ? '1' : '0';
                }
            }

            // ── Text fields ──
            foreach ($textFields as $field) {
                $data[$field] = $request->filled($field) ? $request->input($field) : null;
            }

            // ── Matriks VATM × VRFF (64 kolom, dinormalisasi '0'/'1') ──
            $matriks = $request->input('matriks', []);
            $aspekNames = ['1', '2', '3', '4', '5', '6', '7', '8'];
            $aturanBukti = ['v', 'a', 't', 'm'];
            $prinsip = ['v', 'r', 'f', 'f2'];
            foreach ($aspekNames as $n) {
                foreach ($aturanBukti as $x) {
                    $key = "ab{$n}{$x}";
                    $data[$key] = isset($matriks[$key]) && (int) $matriks[$key] === 1 ? 1 : 0;
                }
                foreach ($prinsip as $x) {
                    $key = "pa{$n}{$x}";
                    $data[$key] = isset($matriks[$key]) && (int) $matriks[$key] === 1 ? 1 : 0;
                }
            }

            // ── Upsert by id_jadwal (1 row per jadwal) ──
            $exists = DB::table('mkva')->where('id_jadwal', (string) $idJadwal)->exists();
            if ($exists) {
                DB::table('mkva')->where('id_jadwal', (string) $idJadwal)->update($data);
                $pesan = 'Bagian 1 MKVA Telah Terupdate';
            } else {
                DB::table('mkva')->insert($data);
                $pesan = 'Bagian 1 MKVA Telah Tersimpan';
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $pesan,
                'data' => [
                    'id_jadwal' => (int) $idJadwal,
                    'next_step' => [
                        'bagian' => 2,
                        'url' => "/penguji/mkva/{$idJadwal}/temuan-perbaikan",
                    ],
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan MKVA Bagian 1',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // BAGIAN 2 — TEMUAN & RENCANA PERBAIKAN (CRUD inline)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/auth/penguji/mkva/{idJadwal}/temuan-perbaikan
     * Riwayat temuan + perbaikan (padanan render form-fr-va2).
     */
    public function bagian2(Request $request, $idJadwal): JsonResponse
    {
        [$asesor, $error] = $this->resolveOwnership($request, $idJadwal);
        if ($error) {
            return $error;
        }

        $temuan = DB::table('mkva_temuan')->where('id_jadwal', (string) $idJadwal)
            ->orderBy('id')->get();
        $perbaikan = DB::table('mkva_perbaikan')->where('id_jadwal', (string) $idJadwal)
            ->orderBy('id')->get();
        $bagian1Terisi = DB::table('mkva')->where('id_jadwal', (string) $idJadwal)->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'id_jadwal' => (int) $idJadwal,
                'bagian1_terisi' => $bagian1Terisi,   // indikator progres (fix native)
                'temuan' => $temuan,
                'perbaikan' => $perbaikan,
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/penguji/mkva/{idJadwal}/temuan
     * Dup-check (jadwal+temuan+rekomendasi — idem native tambahtemuan).
     */
    public function storeTemuan(Request $request, $idJadwal): JsonResponse
    {
        [$asesor, $error] = $this->resolveOwnership($request, $idJadwal);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'temuan' => 'required|string',
            'rekomendasi' => 'required|string',
        ], [
            'temuan.required' => 'Temuan wajib diisi',
            'rekomendasi.required' => 'Rekomendasi wajib diisi',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();

            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Dup-check (idem native)
        $exists = DB::table('mkva_temuan')
            ->where('id_jadwal', (string) $idJadwal)
            ->where('temuan', $request->temuan)
            ->where('rekomendasi', $request->rekomendasi)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Data temuan tersebut sudah ada',
            ], 409);
        }

        $id = DB::table('mkva_temuan')->insertGetId([
            'id_jadwal' => (string) $idJadwal,
            'temuan' => $request->temuan,
            'rekomendasi' => $request->rekomendasi,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Temuan berhasil ditambahkan',
            'data' => ['id' => $id],
        ], 201);
    }

    /**
     * DELETE /api/v1/auth/penguji/mkva/{idJadwal}/temuan/{id}
     * (padanan hapustemuan — exist-check idem native)
     */
    public function destroyTemuan(Request $request, $idJadwal, $id): JsonResponse
    {
        [$asesor, $error] = $this->resolveOwnership($request, $idJadwal);
        if ($error) {
            return $error;
        }

        $deleted = DB::table('mkva_temuan')
            ->where('id', $id)->where('id_jadwal', (string) $idJadwal)->delete();

        return response()->json([
            'success' => true,
            'message' => $deleted ? 'Temuan berhasil dihapus' : 'Temuan tidak ditemukan',
        ]);
    }

    /**
     * POST /api/v1/auth/penguji/mkva/{idJadwal}/perbaikan
     * (padanan tambahperbaikan)
     */
    public function storePerbaikan(Request $request, $idJadwal): JsonResponse
    {
        [$asesor, $error] = $this->resolveOwnership($request, $idJadwal);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'perbaikan' => 'required|string',
            'penyelesaian' => 'required|string',
            'penanggungjawab' => 'required|string',
        ], [
            'perbaikan.required' => 'Perbaikan wajib diisi',
            'penyelesaian.required' => 'Penyelesaian wajib diisi',
            'penanggungjawab.required' => 'Penanggung jawab wajib diisi',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();

            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $id = DB::table('mkva_perbaikan')->insertGetId([
            'id_jadwal' => (string) $idJadwal,
            'perbaikan' => $request->perbaikan,
            'penyelesaian' => $request->penyelesaian,
            'penanggungjawab' => $request->penanggungjawab,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Rencana perbaikan berhasil ditambahkan',
            'data' => ['id' => $id],
        ], 201);
    }

    /**
     * DELETE /api/v1/auth/penguji/mkva/{idJadwal}/perbaikan/{id}
     * (padanan hapusperbaikan)
     */
    public function destroyPerbaikan(Request $request, $idJadwal, $id): JsonResponse
    {
        [$asesor, $error] = $this->resolveOwnership($request, $idJadwal);
        if ($error) {
            return $error;
        }

        $deleted = DB::table('mkva_perbaikan')
            ->where('id', $id)->where('id_jadwal', (string) $idJadwal)->delete();

        return response()->json([
            'success' => true,
            'message' => $deleted ? 'Rencana perbaikan berhasil dihapus' : 'Rencana perbaikan tidak ditemukan',
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    /**
     * Resolve asesor MKVA + ownership (asesor_mkva1 ATAU asesor_mkva2).
     */
    private function resolveOwnership(Request $request, $idJadwal): array
    {
        $user = $request->user();
        if (! $user) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401)];
        }
        if (! $user->isPenguji()) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus penguji.',
            ], 403)];
        }

        $asesor = Asesor::where('no_ktp', $user->username)
            ->orWhere('no_ktp', $user->no_ktp)
            ->first();

        if (! $asesor) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Profil penguji tidak ditemukan.',
            ], 404)];
        }

        $assigned = DB::table('jadwal_asesmen')
            ->where('id', $idJadwal)
            ->where(function ($q) use ($asesor) {
                $q->where('asesor_mkva1', $asesor->id)
                    ->orWhere('asesor_mkva2', $asesor->id);
            })
            ->exists();

        if (! $assigned) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Anda bukan tim validasi MKVA pada jadwal ini',
            ], 403)];
        }

        return [$asesor, null];
    }

    /**
     * Tim validasi 2 orang (CQ #2).
     */
    private function timValidasi($idJadwal): array
    {
        $jadwal = DB::table('jadwal_asesmen')
            ->where('id', $idJadwal)->first(['asesor_mkva1', 'asesor_mkva2']);

        $ids = collect([$jadwal->asesor_mkva1 ?? null, $jadwal->asesor_mkva2 ?? null])
            ->filter()->unique();

        return $ids->map(function ($id, $pos) {
            $a = DB::table('asesor')->where('id', $id)->first(['nama', 'gelar_depan', 'gelar_blk']);

            return [
                'posisi' => 'Validator '.($pos + 1),
                'id' => (int) $id,
                'nama_lengkap' => $a
                    ? trim(($a->gelar_depan ? $a->gelar_depan.' ' : '').$a->nama.($a->gelar_blk ? ', '.$a->gelar_blk : ''))
                    : "Asesor #{$id}",
            ];
        })->values()->all();
    }

    /**
     * Normalisasi mkva row utk response — semua checkbox '0'/'1' konsisten
     * + matriks terstruktur per aspek.
     */
    private function normalisasiMkva($mkva): array
    {
        $data = (array) $mkva;
        unset($data['id'], $data['id_jadwal'], $data['waktu']);

        $matriks = [];
        foreach (range(1, 8) as $n) {
            $abV = (int) ($data["ab{$n}v"] ?? 0);
            $abA = (int) ($data["ab{$n}a"] ?? 0);
            $abT = (int) ($data["ab{$n}t"] ?? 0);
            $abM = (int) ($data["ab{$n}m"] ?? 0);

            $paV = (int) ($data["pa{$n}v"] ?? 0);
            $paR = (int) ($data["pa{$n}r"] ?? 0);
            $paF = (int) ($data["pa{$n}f"] ?? 0);
            $paF2 = (int) ($data["pa{$n}f2"] ?? 0);

            $matriks["aspek_{$n}"] = [
                'aturan_bukti' => [
                    'valid' => $abV,
                    'autentik' => $abA,
                    'terkini' => $abT,
                    'memadai' => $abM,
                    'v' => $abV,
                    'a' => $abA,
                    't' => $abT,
                    'm' => $abM,
                ],
                'prinsip' => [
                    'valid' => $paV,
                    'reliabel' => $paR,
                    'fleksibel' => $paF,
                    'fair' => $paF2,
                    'v' => $paV,
                    'r' => $paR,
                    'f' => $paF,
                    'f2' => $paF2,
                ],
            ];
        }

        return [
            'periode' => $data['periode'] ?? null,
            'tujuan' => collect(range(1, 7))->mapWithKeys(
                fn ($i) => ["tujuan_{$i}" => ($data["tujuan_{$i}"] ?? null) === '1']
            )->put('tujuan_7b', $data['tujuan_7b'] ?? null)->all(),
            'konteks' => collect(range(1, 6))->mapWithKeys(
                fn ($i) => ["konteks_{$i}" => ($data["konteks_{$i}"] ?? null) === '1']
            )->put('konteks_6b', $data['konteks_6b'] ?? null)->all(),
            'pendekatan' => collect(range(1, 6))->mapWithKeys(
                fn ($i) => ["pendekatan_{$i}" => ($data["pendekatan_{$i}"] ?? null) === '1']
            )->all(),
            'orang_relevan' => collect([
                'askom', 'leadasesor', 'manspv', 'tenagaahli', 'koordtraining', 'asosiasi',
            ])->mapWithKeys(function ($g) use ($data) {
                // askom: 3 slot nama asesor (orel_1..3 + konfirmorel_1..3)
                // — kolom `nama_`/`konfirmorel` polos tidak ada di tabel
                if ($g === 'askom') {
                    $slot = [];
                    foreach (range(1, 3) as $i) {
                        $slot[] = [
                            'nama' => $data["orel_{$i}"] ?? null,
                            'hasil_konfirmasi' => $data["konfirmorel_{$i}"] ?? null,
                        ];
                    }

                    return [$g => [
                        'dikonfirmasi' => ($data[$g] ?? null) === '1',
                        'asesor' => $slot,
                    ]];
                }

                return [$g => [
                    'dikonfirmasi' => ($data[$g] ?? null) === '1',
                    'nama' => $data['nama_'.$g] ?? null,
                    // kolom legacy: konfirmleadaseesor (typo, tanpa 'r' kedua)
                    'hasil_konfirmasi' => $data[$g === 'leadasesor' ? 'konfirmleadaseesor' : 'konfirm'.$g] ?? null,
                ]];
            })->all(),
            'acuan_pembanding' => collect([
                'stdkom', 'sop', 'manualbook', 'stdkinerja', 'lainnya',
                'skema', 'skk', 'perangkat', 'peraturan',
            ])->mapWithKeys(fn ($f) => [$f => ($data[$f] ?? null) === '1'])
                ->put('nama_lainnya', $data['nama_lainnya'] ?? null)->all(),
            'keterampilan' => [
                'proaktif' => ($data['proaktif'] ?? null) === '1',
                'activelistening' => ($data['activelistening'] ?? null) === '1',
                'keterampilan_lainnya' => [
                    ['checkbox' => ($data['keterampilan1'] ?? null) === '1', 'nama' => $data['nama_ketlainnya1'] ?? null],
                    ['checkbox' => ($data['keterampilan2'] ?? null) === '1', 'nama' => $data['nama_ketlainnya2'] ?? null],
                ],
            ],
            'matriks_vatm_vrff' => $matriks,
        ];
    }

    private function tglIndo($tgl): ?string
    {
        if (empty($tgl) || $tgl === '0000-00-00') {
            return null;
        }
        $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $t = strtotime($tgl);

        return date('j', $t).' '.$bulan[(int) date('n', $t)].' '.date('Y', $t);
    }
}
