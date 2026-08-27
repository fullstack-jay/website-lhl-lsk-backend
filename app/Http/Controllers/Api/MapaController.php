<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SkemaMapa1a;
use App\Models\SkemaMapa1b;
use App\Models\SkemaMapa2;
use App\Models\SkemaKkni;
use App\Models\KategoriKandidat;
use App\Models\Muk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MapaController extends Controller
{
    // ==================== MAPA 1A - Pendekatan Asesmen ====================

    /**
     * Get MAPA 1A by skema and profil kandidat (Admin/Public)
     * GET /api/v1/skema/{id}/mapa1a/{profil}
     *
     * Menjawab semua kebutuhan render form (Query 3, 4, 5 pada dokumentasi):
     * - Data skema untuk header tabel (judul + kode_skema)
     * - Data MAPA-01 existing untuk pre-fill radio/checkbox (null jika form baru)
     * - Daftar profil kandidat untuk dropdown + penanda mana yang sudah terisi
     *
     * @param int $skemaId
     * @param int $profil
     * @return \Illuminate\Http\JsonResponse
     */
    public function showMapa1a($skemaId, $profil = 1)
    {
        // Query 3: Data skema
        $skema = SkemaKkni::findOrFail($skemaId);

        // Query 4: Data MAPA-01 existing (untuk pre-fill; null = form baru)
        $mapa = SkemaMapa1a::bySkema($skemaId)
            ->byProfil($profil)
            ->first();

        // Query 5: Dropdown profil kandidat + status kelengkapan per tipe
        $kategoriKandidat = \App\Models\KategoriKandidat::orderBy('kode', 'asc')->get();
        $filledProfiles = SkemaMapa1a::bySkema($skemaId)->pluck('profil_kandidat')->all();

        return response()->json([
            'success' => true,
            'data' => [
                'skema' => [
                    'id' => $skema->id,
                    'kode_skema' => $skema->kode_skema,
                    'judul' => $skema->judul,
                    'judul_eng' => $skema->judul_eng,
                    'apl02' => $skema->apl02,
                ],
                'profil_kandidat' => $profil,
                'dropdown_kandidat' => $kategoriKandidat->map(function ($item) use ($filledProfiles) {
                    return [
                        'value' => (string) $item->kode,
                        'label' => $item->deskripsi,
                        // Frontend bisa menandai tab kandidat yang sudah terisi
                        'sudah_terisi' => in_array($item->kode, array_map('intval', $filledProfiles)),
                    ];
                }),
                // null jika belum ada -> frontend render form kosong
                'mapa1a' => $mapa,
            ],
        ]);
    }

    /**
     * Store or update MAPA 1A (Admin)
     * POST /api/v1/admin/skema/{id}/mapa1a
     *
     * Implementasi sesuai dokumentasi input-ubah-renc-asesmen.md:
     * - Upsert berdasarkan kombinasi unik (id_skemakkni + profil_kandidat)
     * - Checkbox tidak dicentang => disimpan NULL (bukan "" seperti kode lama)
     * - Mendukung nama field baru (API style) DAN nama field lama (form PHP Native):
     *   kandidat=pendekatan, kandidatket1=pendekatan_ket, tujuanasesmen=tujuan,
     *   lingkungan=konteks_a, peluang=konteks_b, siapa=konteks_d,
     *   hubunganX=konteks_cX, hubunganX-1=konteks_cX rating,
     *   konfirmasiket=konfirmasi4_ket, tolokukurX=tolukX, tolokukurXket=tolukX_ket
     *
     * @param Request $request
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeMapa1a(Request $request, $skemaId)
    {
        $skema = SkemaKkni::findOrFail($skemaId);

        // ================= STEP 2: CAPTURE & NORMALISASI DATA =================
        // Mapping nama field form lama -> kolom database
        $aliases = [
            'kandidat'         => 'pendekatan',
            'kandidatket1'     => 'pendekatan_ket',
            'tujuanasesmen'    => 'tujuan',
            'lingkungan'       => 'konteks_a',
            'peluang'          => 'konteks_b',
            'hubungan1'        => 'konteks_c1',
            'hubungan1-1'      => 'konteks_c11',
            'hubungan2'        => 'konteks_c2',
            'hubungan2-1'      => 'konteks_c21',
            'hubungan3'        => 'konteks_c3',
            'hubungan3-1'      => 'konteks_c31',
            'siapa'            => 'konteks_d',
            'konfirmasiket'    => 'konfirmasi4_ket',
            'tolokukur1'       => 'toluk1',
            'tolokukur2'       => 'toluk2',
            'tolokukur3'       => 'toluk3',
            'tolokukur4'       => 'toluk4',
            'tolokukur5'       => 'toluk5',
            'tolokukur1ket'    => 'toluk1_ket',
            'tolokukur2ket'    => 'toluk2_ket',
            'tolokukur3ket'    => 'toluk3_ket',
            'tolokukur4ket'    => 'toluk4_ket',
            'tolokukur5ket'    => 'toluk5_ket',
        ];

        foreach ($aliases as $oldName => $columnName) {
            if ($request->has($oldName)) {
                $request->merge([$columnName => $request->input($oldName)]);
            }
        }

        // Profil kandidat default 1 jika tidak dikirim (mengikuti perilaku GET ?module=mapa1a tanpa kand)
        if (!$request->filled('profil_kandidat')) {
            $request->merge(['profil_kandidat' => 1]);
        }

        $validator = Validator::make($request->all(), [
            'profil_kandidat' => 'required|integer|min:1|max:5',

            // Radio wajib
            'pendekatan' => 'nullable|in:1,2,3',
            'pendekatan_ket' => 'nullable|string|max:255',
            'tujuan' => 'nullable|in:1,2,3,4,5',
            'tujuanket' => 'nullable|string|max:255',

            // Konteks asesmen
            'konteks_a' => 'nullable|in:1,2',
            'konteks_b' => 'nullable|in:1,2',

            // Checkbox + rating emoji (rating hanya valid jika checkbox dicentang)
            'konteks_c1' => 'nullable|in:1',
            'konteks_c11' => 'nullable|in:1,2,3',
            'konteks_c2' => 'nullable|in:2',
            'konteks_c21' => 'nullable|in:1,2,3',
            'konteks_c3' => 'nullable|in:3',
            'konteks_c31' => 'nullable|in:1,2,3',

            'konteks_d' => 'nullable|in:1,2,3',

            // Konfirmasi pihak relevan (checkbox multiple)
            'konfirmasi1' => 'nullable|in:1',
            'konfirmasi2' => 'nullable|in:2',
            'konfirmasi3' => 'nullable|in:3',
            'konfirmasi4' => 'nullable|in:4',
            'konfirmasi4_ket' => 'nullable|string|max:255',

            // Tolok ukur asesmen (checkbox multiple + keterangan)
            'toluk1' => 'nullable|in:1',
            'toluk2' => 'nullable|in:2',
            'toluk3' => 'nullable|in:3',
            'toluk4' => 'nullable|in:4',
            'toluk5' => 'nullable|in:5',
            'toluk1_ket' => 'nullable|string|max:255',
            'toluk2_ket' => 'nullable|string|max:255',
            'toluk3_ket' => 'nullable|string|max:255',
            'toluk4_ket' => 'nullable|string|max:255',
            'toluk5_ket' => 'nullable|string|max:255',

            // Kolom tambahan tabel lama
            'konfirm1' => 'nullable|string|max:2',
            'konfirm2' => 'nullable|string|max:2',
            'konfirm3' => 'nullable|string|max:2',
            'konfirm4' => 'nullable|string|max:2',
            'konfirm1_ket' => 'nullable|string',
            'konfirm2_ket' => 'nullable|string',
            'konfirm3_ket' => 'nullable|string',
            'konfirm4_ket' => 'nullable|string',
            'modkon1a' => 'nullable|string|max:2',
            'modkon1b' => 'nullable|string|max:2',
            'modkon1a_ket' => 'nullable|string',
            'modkon1b_ket' => 'nullable|string',
            'modkon2' => 'nullable|string|max:2',
            'modkon3' => 'nullable|string|max:2',
            'modkon4' => 'nullable|string|max:2',
            'modkon2_ket' => 'nullable|string',
            'modkon3_ket' => 'nullable|string',
            'modkon4_ket' => 'nullable|string',
        ]);

        // Rating hanya valid jika checkbox induknya dicentang
        // CATATAN: nilai checkbox adalah nomor itemnya ('1'/'2'/'3'), bukan on/off,
        // jadi gunakan filled() bukan boolean()
        $validator->after(function ($v) use ($request) {
            foreach ([1 => 'c11', 2 => 'c21', 3 => 'c31'] as $idx => $ratingField) {
                $checkField = "konteks_c{$idx}";
                if (!$request->filled($checkField) && $request->filled("konteks_{$ratingField}")) {
                    // Rating dikirim tanpa checkbox => abaikan saja (tidak error keras)
                    $request->merge(["konteks_{$ratingField}" => null]);
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // ================= STEP 3: CHECK EXISTING (UPSERT DECISION) ========
            $profil = (int) $request->profil_kandidat;

            $mapa = SkemaMapa1a::bySkema($skemaId)
                ->byProfil($profil)
                ->first();

            // Semua field data (selalu di-set penuh — uncheck/null akan menghapus nilai lama,
            // sesuai pola full-overwrite pada simpanmapa1a.php versi prepared statement)
            $textFieldMap = [
                'pendekatan'    => $request->input('pendekatan'),
                'pendekatan_ket'=> $request->input('pendekatan_ket'),
                'tujuan'        => $request->input('tujuan'),
                'tujuanket'     => $request->input('tujuanket'),
                'konteks_a'     => $request->input('konteks_a'),
                'konteks_b'     => $request->input('konteks_b'),
                'konteks_c1'    => $request->has('konteks_c1') ? $request->input('konteks_c1') : null,
                'konteks_c11'   => $request->input('konteks_c11'),
                'konteks_c2'    => $request->has('konteks_c2') ? $request->input('konteks_c2') : null,
                'konteks_c21'   => $request->input('konteks_c21'),
                'konteks_c3'    => $request->has('konteks_c3') ? $request->input('konteks_c3') : null,
                'konteks_c31'   => $request->input('konteks_c31'),
                'konteks_d'     => $request->input('konteks_d'),
                'konfirmasi1'   => $request->has('konfirmasi1') ? $request->input('konfirmasi1') : null,
                'konfirmasi2'   => $request->has('konfirmasi2') ? $request->input('konfirmasi2') : null,
                'konfirmasi3'   => $request->has('konfirmasi3') ? $request->input('konfirmasi3') : null,
                'konfirmasi4'   => $request->has('konfirmasi4') ? $request->input('konfirmasi4') : null,
                'konfirmasi4_ket' => $request->input('konfirmasi4_ket'),
                'toluk1'        => $request->has('toluk1') ? $request->input('toluk1') : null,
                'toluk1_ket'    => $request->input('toluk1_ket'),
                'toluk2'        => $request->has('toluk2') ? $request->input('toluk2') : null,
                'toluk2_ket'    => $request->input('toluk2_ket'),
                'toluk3'        => $request->has('toluk3') ? $request->input('toluk3') : null,
                'toluk3_ket'    => $request->input('toluk3_ket'),
                'toluk4'        => $request->has('toluk4') ? $request->input('toluk4') : null,
                'toluk4_ket'    => $request->input('toluk4_ket'),
                'toluk5'        => $request->has('toluk5') ? $request->input('toluk5') : null,
                'toluk5_ket'    => $request->input('toluk5_ket'),
            ];

            // Kolom tambahan tabel lama (opsional)
            $legacyFields = [
                'konfirm1', 'konfirm2', 'konfirm3', 'konfirm4',
                'konfirm1_ket', 'konfirm2_ket', 'konfirm3_ket', 'konfirm4_ket',
                'modkon1a', 'modkon1b', 'modkon1a_ket', 'modkon1b_ket',
                'modkon2', 'modkon3', 'modkon4',
                'modkon2_ket', 'modkon3_ket', 'modkon4_ket',
            ];
            foreach ($legacyFields as $f) {
                if ($request->has($f)) {
                    $textFieldMap[$f] = $request->input($f);
                }
            }

            $data = array_merge(
                ['id_skemakkni' => $skemaId, 'profil_kandidat' => $profil],
                $textFieldMap
            );

            if ($mapa) {
                $mapa->update($data);
                $action = 'updated';
                $message = 'Bagian 1 MAPA-01 Telah Terupdate';
            } else {
                $mapa = SkemaMapa1a::create($data);
                $action = 'created';
                $message = 'Bagian 1 MAPA-01 Telah Tersimpan';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $mapa->fresh(),
                'next_step' => [
                    'bagian' => 2,
                    'module' => 'mapa1b',
                    // Frontend dapat langsung redirect ke Bagian 2 membawa parameter sama
                    'url_hint' => "/api/v1/skema/{$skemaId}/mapa1b?profil={$profil}",
                ],
            ], $action === 'created' ? 201 : 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan MAPA 1A',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete MAPA 1A (Admin)
     * DELETE /api/v1/admin/skema/{id}/mapa1a/{profil}
     *
     * @param int $skemaId
     * @param int $profil
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyMapa1a($skemaId, $profil)
    {
        try {
            $mapa = SkemaMapa1a::bySkema($skemaId)
                ->byProfil($profil)
                ->first();

            if (!$mapa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data MAPA 1A tidak ditemukan',
                ], 404);
            }

            $mapa->delete();

            return response()->json([
                'success' => true,
                'message' => 'MAPA 1A berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus MAPA 1A',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== MAPA 1B - Perangkat Asesmen per Unit ====================

    /**
     * Get MAPA 1B by skema & profil kandidat (Admin/Public)
     * GET /api/v1/skema/{id}/mapa1b?profil={kode}
     *
     * Render seluruh hierarki Unit → Elemen → KUK milik skema, masing-masing KUK
     * ditempeli data pemetaan bukti/metode existing untuk pre-fill form
     * (padanan master-detail nested loop pada modul mapa1b PHP Native).
     *
     * Tambahan info progress: total KUK vs baris tersimpan vs KUK belum dipetakan
     * (padanan Common Queries #2-#4 di dokumentasi).
     *
     * @param int $skemaId
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function showMapa1b(Request $request, $skemaId)
    {
        // Profil kandidat: default 1 jika tidak dikirim (perilaku ?module=mapa1b tanpa kand)
        $profil = $request->input('profil') ?? $request->input('profil_kandidat') ?? 1;

        // Data skema untuk header tabel identitas
        $skema = SkemaKkni::findOrFail($skemaId);

        // Dropdown profil kandidat + flag sudah terisi (untuk Bagian 2)
        $kategoriKandidat = \App\Models\KategoriKandidat::orderBy('kode', 'asc')->get();
        $filledProfiles = SkemaMapa1b::bySkema($skemaId)
            ->where('profil_kandidat', $profil)
            ->exists();

        // Hierarki lengkap: unit → elemen → kuk
        $units = \App\Models\UnitKompetensi::bySkema($skemaId)
            ->with(['elemenKompetensi.kriteriaUnjukkerja'])
            ->orderBy('id', 'asc')
            ->get();

        // Semua baris mapa1b existing utk kombinasi skema+kandidat (sekali query)
        $existingRows = SkemaMapa1b::bySkema($skemaId)
            ->byProfil($profil)
            ->get()
            ->keyBy(function ($row) {
                return "{$row->id_unitkompetensi}-{$row->id_elemenkompetensi}-{$row->id_kriteria}";
            });

        // Bangun struktur hierarki dengan pre-fill per KUK
        // (lookup O(1): elemenId sudah diketahui dari relasi, unit dari loop luar)
        $totalKuk = 0;
        $mappedKuk = 0;

        $unitList = $units->map(function ($unit) use ($existingRows, &$totalKuk, &$mappedKuk) {
            $elemenList = $unit->elemenKompetensi->map(function ($elemen) use ($unit, $existingRows, &$totalKuk, &$mappedKuk) {
                $kukList = $elemen->kriteriaUnjukkerja->map(function ($kuk) use ($unit, $elemen, $existingRows) {
                    return [
                        'id_kriteria' => $kuk->id,
                        'kriteria' => $kuk->kriteria,
                        'kriteria_pasif' => $kuk->kriteria_pasif,
                        // Pre-fill: data existing (dari composite key) atau null (form baru)
                        'mapa1b' => $existingRows->get("{$unit->id}-{$elemen->id}-{$kuk->id}"),
                    ];
                });

                foreach ($kukList as $k) {
                    $totalKuk++;
                    if (!empty($k['mapa1b'])) {
                        $mappedKuk++;
                    }
                }

                return [
                    'id_elemenkompetensi' => $elemen->id,
                    'elemen_kompetensi' => $elemen->elemen_kompetensi,
                    'jumlah_kuk' => $kukList->count(),
                    'kuk' => $kukList,
                ];
            });

            $unitTotal = $elemenList->sum('jumlah_kuk');
            $unitMapped = $elemenList->sum(function ($el) {
                return collect($el['kuk'])->filter(fn ($k) => !empty($k['mapa1b']))->count();
            });

            return [
                'id_unitkompetensi' => $unit->id,
                'kode_unit' => $unit->kode_unit,
                'judul' => $unit->judul,
                'jumlah_elemen' => $elemenList->count(),
                'jumlah_kuk' => $unitTotal,
                'jumlah_terisi' => $unitMapped,
                'elemen' => $elemenList,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'skema' => [
                    'id' => $skema->id,
                    'kode_skema' => $skema->kode_skema,
                    'judul' => $skema->judul,
                    'judul_eng' => $skema->judul_eng,
                    'apl02' => $skema->apl02,
                ],
                'profil_kandidat' => (string) $profil,
                'dropdown_kandidat' => $kategoriKandidat->map(function ($item) {
                    return [
                        'value' => (string) $item->kode,
                        'label' => $item->deskripsi,
                    ];
                }),
                // Master-detail untuk render tabel input per KUK
                'unit_kompetensi' => $unitList,
                // Metode enum reference untuk dropdown frontend
                'metode_options' => \App\Models\SkemaMapa1b::METODE_OPTIONS,
                // Progress/gap analysis (padanan Common Queries #2-#4)
                'progress' => [
                    'total_kuk' => $totalKuk,
                    'terisi' => $mappedKuk,
                    'belum_dipetakan' => $totalKuk - $mappedKuk,
                ],
                'sudah_ada_data' => $filledProfiles,
            ],
        ]);
    }

    /**
     * Store or update MAPA 1B — bulk upsert (Admin)
     * POST /api/v1/admin/skema/{id}/mapa1b
     *
     * Implementasi inline handler `simpanbagian` versi API:
     * - Upsert berdasarkan composite key 5 kolom (skema, kandidat, unit, elemen, kriteria)
     *   sesuai UNIQUE KEY uk_mapa1b_row
     * - Checkbox bukti: dikirim saat dicentang ('L'/'TL'/'T'), tidak dikirim saat
     *   unchecked → disimpan NULL (full overwrite, pola isset())
     * - metodeX hanya menerima enum CL/DIT/DPL/DPT/VP/CUP/PW atau null/kosong
     * - Semua insert/update dibungkus DB transaction (perbaikan atas sistem lama
     *   yang partial-save saat error tengah loop)
     * - Field dengan key unit+elemen+kriteria otomatis di-resolve dari id_kriteria
     *
     * @param Request $request
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeMapa1b(Request $request, $skemaId)
    {
        $skema = SkemaKkni::findOrFail($skemaId);

        // Profil default 1 (padanan URL tanpa ?kand=)
        if (!$request->filled('profil_kandidat')) {
            $request->merge(['profil_kandidat' => 1]);
        }

        $validator = Validator::make($request->all(), [
            'profil_kandidat' => 'required|integer|min:1|max:5',
            'data' => 'required|array|min:1',
            'data.*.id_kriteria' => 'required|integer|exists:kriteria_unjukkerja,id',

            // Baris 1 (Tipe A)
            'data.*.ket_bukti' => 'nullable|string',
            'data.*.bukti_L' => ['nullable', function ($attr, $val, $fail) {
                if ($val !== null && $val !== '' && $val !== 'L') $fail("Nilai bukti_L harus 'L' atau kosong");
            }],
            'data.*.bukti_TL' => ['nullable', function ($attr, $val, $fail) {
                if ($val !== null && $val !== '' && $val !== 'TL') $fail("Nilai bukti_TL harus 'TL' atau kosong");
            }],
            'data.*.bukti_T' => ['nullable', function ($attr, $val, $fail) {
                if ($val !== null && $val !== '' && $val !== 'T') $fail("Nilai bukti_T harus 'T' atau kosong");
            }],

            // Enum metode baris 1
            'data.*.metode1' => 'nullable|in:CL,DIT,DPL,DPT,VP,CUP,PW',
            'data.*.metode2' => 'nullable|in:CL,DIT,DPL,DPT,VP,CUP,PW',
            'data.*.metode3' => 'nullable|in:CL,DIT,DPL,DPT,VP,CUP,PW',
            'data.*.metode4' => 'nullable|in:CL,DIT,DPL,DPT,VP,CUP,PW',
            'data.*.metode5' => 'nullable|in:CL,DIT,DPL,DPT,VP,CUP,PW',
            'data.*.metode6' => 'nullable|in:CL,DIT,DPL,DPT,VP,CUP,PW',

            // Baris 2 (Tipe B)
            'data.*.ket_bukti2' => 'nullable|string',
            'data.*.bukti_L2' => ['nullable', function ($attr, $val, $fail) {
                if ($val !== null && $val !== '' && $val !== 'L') $fail("Nilai bukti_L2 harus 'L' atau kosong");
            }],
            'data.*.bukti_TL2' => ['nullable', function ($attr, $val, $fail) {
                if ($val !== null && $val !== '' && $val !== 'TL') $fail("Nilai bukti_TL2 harus 'TL' atau kosong");
            }],
            'data.*.bukti_T2' => ['nullable', function ($attr, $val, $fail) {
                if ($val !== null && $val !== '' && $val !== 'T') $fail("Nilai bukti_T2 harus 'T' atau kosong");
            }],

            // Enum metode baris 2
            'data.*.metode1t' => 'nullable|in:CL,DIT,DPL,DPT,VP,CUP,PW',
            'data.*.metode2t' => 'nullable|in:CL,DIT,DPL,DPT,VP,CUP,PW',
            'data.*.metode3t' => 'nullable|in:CL,DIT,DPL,DPT,VP,CUP,PW',
            'data.*.metode4t' => 'nullable|in:CL,DIT,DPL,DPT,VP,CUP,PW',
            'data.*.metode5t' => 'nullable|in:CL,DIT,DPL,DPT,VP,CUP,PW',
            'data.*.metode6t' => 'nullable|in:CL,DIT,DPL,DPT,VP,CUP,PW',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $profil = (int) $request->profil_kandidat;
            $created = 0;
            $updated = 0;

            DB::beginTransaction();

            foreach ($request->data as $item) {
                $kriteriaId = $item['id_kriteria'];

                // Resolve hierarki dari id_kriteria (unit & elemen tidak perlu dikirim)
                $kriteria = \App\Models\KriteriaUnjukkerja::with('elemenKompetensi')->find($kriteriaId);
                if (!$kriteria || !$kriteria->elemenKompetensi) {
                    continue; // skip invalid, jangan gagalkan seluruh batch
                }

                $elemenId = $kriteria->id_elemenkompetensi;
                $unitId = $kriteria->elemenKompetensi->id_unitkompetensi;

                // Upsert berdasarkan composite key 5 kolom
                $mapa = SkemaMapa1b::where([
                    'id_skemakkni' => $skemaId,
                    'profil_kandidat' => $profil,
                    'id_unitkompetensi' => $unitId,
                    'id_elemenkompetensi' => $elemenId,
                    'id_kriteria' => $kriteriaId,
                ])->first();

                // Normalisasi: string kosong → NULL
                $payloadFields = [
                    'ket_bukti', 'bukti_L', 'bukti_TL', 'bukti_T',
                    'metode1', 'metode2', 'metode3', 'metode4', 'metode5', 'metode6',
                    'ket_bukti2', 'bukti_L2', 'bukti_TL2', 'bukti_T2',
                    'metode1t', 'metode2t', 'metode3t', 'metode4t', 'metode5t', 'metode6t',
                ];
                $data = [
                    'id_skemakkni' => $skemaId,
                    'profil_kandidat' => $profil,
                    'id_unitkompetensi' => $unitId,
                    'id_elemenkompetensi' => $elemenId,
                    'id_kriteria' => $kriteriaId,
                ];
                foreach ($payloadFields as $field) {
                    $val = $item[$field] ?? null;
                    $data[$field] = ($val === '' ) ? null : $val;
                }

                if ($mapa) {
                    $mapa->update($data);
                    $updated++;
                } else {
                    SkemaMapa1b::create($data);
                    $created++;
                }
            }

            DB::commit();

            $totalKuk = SkemaMapa1b::bySkema($skemaId)->byProfil($profil)->count();

            return response()->json([
                'success' => true,
                'message' => "DATA BERHASIL DISIMPAN: {$created} data dibuat, {$updated} data diperbarui",
                'data' => [
                    'created_count' => $created,
                    'updated_count' => $updated,
                    'total_baris_tersimpan' => $totalKuk,
                ],
                'next_step' => [
                    'bagian' => 3,
                    'module' => 'mapa1c',
                    'url_hint' => "/api/v1/skema/{$skemaId}/mapa1c?profil={$profil}",
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan MAPA 1B',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete MAPA 1B (Admin)
     * DELETE /api/v1/admin/mapa1b/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyMapa1b($id)
    {
        try {
            $mapa = SkemaMapa1b::findOrFail($id);
            $mapa->delete();

            return response()->json([
                'success' => true,
                'message' => 'MAPA 1B berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus MAPA 1B',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== MAPA 1C - Modifikasi & Kontekstualisasi (Bagian 3) ====================

    /**
     * Get MAPA 1C by skema and profil kandidat (Admin/Public)
     * GET /api/v1/skema/{id}/mapa1c/{profil}
     *
     * BAGIAN 3 dari 3: Mengidentifikasi Persyaratan Modifikasi dan
     * Kontekstualisasi + Konfirmasi dengan orang relevan.
     *
     * ⚠️ Arsitektur penting (sesuai dokumentasi): Bagian 3 TIDAK punya tabel sendiri.
     * Semua datanya disimpan di kolom `modkon*` & `konfirm*_ket` pada tabel
     * skema_mapa1a — tabel yang sama dengan Bagian 1.
     *
     * Response juga menyertakan summary kelengkapan 3 bagian MAPA-01
     * (padanan Common Query #3 pada dokumentasi).
     *
     * @param int $skemaId
     * @param int $profil
     * @return \Illuminate\Http\JsonResponse
     */
    public function showMapa1c($skemaId, $profil = 1)
    {
        // Query 1: Identitas skema untuk header tabel
        $skema = SkemaKkni::findOrFail($skemaId);

        // Query 2: Pre-fill data dari skema_mapa1a (null = form baru)
        $mapa = SkemaMapa1a::bySkema($skemaId)
            ->byProfil($profil)
            ->first();

        // Query 3: Dropdown profil kandidat
        $kategoriKandidat = \App\Models\KategoriKandidat::orderBy('kode', 'asc')->get();

        // Padanan Common Query #3: cek kelengkapan 3 bagian MAPA-01
        $bag1Done = $mapa && !empty($mapa->pendekatan);
        $bag2Done = SkemaMapa1b::bySkema($skemaId)->byProfil($profil)->exists();
        $bag3Done = $mapa && ($mapa->modkon1a !== null && $mapa->modkon1a !== '');

        return response()->json([
            'success' => true,
            'data' => [
                'skema' => [
                    'id' => $skema->id,
                    'kode_skema' => $skema->kode_skema,
                    'judul' => $skema->judul,
                    'judul_eng' => $skema->judul_eng,
                ],
                'profil_kandidat' => (string) $profil,
                'dropdown_kandidat' => $kategoriKandidat->map(function ($item) {
                    return [
                        'value' => (string) $item->kode,
                        'label' => $item->deskripsi,
                    ];
                }),
                // Pre-fill Bagian 3: modkon* + konfirm* (null jika form baru)
                'mapa1c' => $mapa ? $mapa->only([
                    'modkon1a', 'modkon1a_ket',
                    'modkon1b', 'modkon1b_ket',
                    'modkon2', 'modkon2_ket',
                    'modkon3', 'modkon3_ket',
                    'modkon4', 'modkon4_ket',
                    'konfirm1', 'konfirm1_ket',
                    'konfirm2', 'konfirm2_ket',
                    'konfirm3', 'konfirm3_ket',
                    'konfirm4', 'konfirm4_ket',
                ]) : null,
                // Ringkasan kelengkapan seluruh rangkaian MAPA-01
                'kelengkapan' => [
                    'bagian_1_pendekatan' => $bag1Done,
                    'bagian_2_perencanaan' => $bag2Done,
                    'bagian_3_modifikasi' => $bag3Done,
                ],
                // Pemilihan profil dapat mempengaruhi lanjutan; sertakan konteks
                'refensi_lanjutan' => [
                    'mapa_pdf_hint' => "/api/v1/skema/{$skemaId}/mapa-pdf?profil={$profil}",
                ],
            ],
        ]);
    }

    /**
     * Store or update MAPA 1C — "Simpan Jawaban" (Admin)
     * POST /api/v1/admin/skema/{id}/mapa1c
     *
     * Implementasi handler `simpanmapa1c.php` versi API dengan perbaikan:
     * - ✅ FIX BUG FATAL `$profilkandidat` (undefined → INSERT orphan dengan '')
     *   Selalu upsert ke baris (id_skemakkni, profil_kandidat) yang BENAR,
     *   memakai pola ON DUPLICATE KEY UPDATE (native upsert) via updateOrCreate.
     * - Checkbox konf1..4 unchecked → NULL (bukan '') + full overwrite saat update
     * - Radio Ada/Tidak Ada divalidasi in:0,1 (server-side required check)
     * - Tidak mengubah kolom milik Bagian 1 (pendekatan, tujuan, konteks*, toluk*)
     *   sehingga data kedua bagian tidak saling menimpa.
     *
     * @param Request $request
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeMapa1c(Request $request, $skemaId)
    {
        $skema = SkemaKkni::findOrFail($skemaId);

        // Profil default 1 (padanan URL tanpa ?kand=) — FIX utama atas bug native
        if (!$request->filled('profil_kandidat')) {
            $request->merge(['profil_kandidat' => 1]);
        }

        $validator = Validator::make($request->all(), [
            'profil_kandidat' => 'required|integer|min:1|max:5',

            // Radio 3.1a s/d 3.4: '1'=Ada, '0'=Tidak Ada
            'modkon1a' => 'nullable|in:0,1',
            'modkon1b' => 'nullable|in:0,1',
            'modkon2' => 'nullable|in:0,1',
            'modkon3' => 'nullable|in:0,1',
            'modkon4' => 'nullable|in:0,1',

            // Keterangan tiap poin (hanya relevan jika nilai radio = 1)
            'modkon1a_ket' => 'nullable|string|max:255',
            'modkon1b_ket' => 'nullable|string|max:255',
            'modkon2_ket' => 'nullable|string|max:255',
            'modkon3_ket' => 'nullable|string|max:255',
            'modkon4_ket' => 'nullable|string|max:255',

            // Checkbox konfirmasi orang relevan: '1' jika dicentang
            'konfirm1' => ['nullable', function ($attr, $val, $fail) {
                if ($val !== null && $val !== '' && $val != 1) $fail('konfirm1 harus bernilai 1');
            }],
            'konfirm2' => ['nullable', function ($attr, $val, $fail) {
                if ($val !== null && $val !== '' && $val != 1) $fail('konfirm2 harus bernilai 1');
            }],
            'konfirm3' => ['nullable', function ($attr, $val, $fail) {
                if ($val !== null && $val !== '' && $val != 1) $fail('konfirm3 harus bernilai 1');
            }],
            'konfirm4' => ['nullable', function ($attr, $val, $fail) {
                if ($val !== null && $val !== '' && $val != 1) $fail('konfirm4 harus bernilai 1');
            }],

            // Nama personil per baris konfirmasi
            'konfirm1_ket' => 'nullable|string|max:255',
            'konfirm2_ket' => 'nullable|string|max:255',
            'konfirm3_ket' => 'nullable|string|max:255',
            'konfirm4_ket' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $profil = (int) $request->profil_kandidat;

            // Normalisasi checkbox: string kosong → NULL (polanya full-overwrite)
            $payloadFields = [
                'modkon1a', 'modkon1a_ket',
                'modkon1b', 'modkon1b_ket',
                'modkon2', 'modkon2_ket',
                'modkon3', 'modkon3_ket',
                'modkon4', 'modkon4_ket',
                'konfirm1', 'konfirm1_ket',
                'konfirm2', 'konfirm2_ket',
                'konfirm3', 'konfirm3_ket',
                'konfirm4', 'konfirm4_ket',
            ];

            $data = [];
            foreach ($payloadFields as $field) {
                // Dukung alias nama field lama juga: 31a -> modkon1a dst (lihat mapping)
                $data[$field] = ($request->input($field) === '') ? null : $request->input($field);
            }

            // Aliases nama field lama PHP Native: 31a, 31b, 32, 33, 34, konf1..4, nama_konf1..4
            $legacyAliases = [
                '31a' => 'modkon1a', '31a_ket' => 'modkon1a_ket',
                '31b' => 'modkon1b', '31b_ket' => 'modkon1b_ket',
                '32' => 'modkon2', '32_ket' => 'modkon2_ket',
                '33' => 'modkon3', '33_ket' => 'modkon3_ket',
                '34' => 'modkon4', '34_ket' => 'modkon4_ket',
                'konf1' => 'konfirm1', 'nama_konf1' => 'konfirm1_ket',
                'konf2' => 'konfirm2', 'nama_konf2' => 'konfirm2_ket',
                'konf3' => 'konfirm3', 'nama_konf3' => 'konfirm3_ket',
                'konf4' => 'konfirm4', 'nama_konf4' => 'konfirm4_ket',
            ];
            foreach ($legacyAliases as $oldName => $field) {
                if (!$request->has($field) && $request->has($oldName)) {
                    $data[$field] = ($request->input($oldName) === '') ? null : $request->input($oldName);
                }
            }

            DB::beginTransaction();

            // Native-upsert semantik via updateOrCreate pada composite key unik
            // uk_skema_kandidat (id_skemakkni, profil_kandidat)
            $mapa = SkemaMapa1a::updateOrCreate(
                [
                    'id_skemakkni' => $skemaId,
                    'profil_kandidat' => $profil,
                ],
                // HANYA kolom Bagian 3 yang ditulis — kolom Bagian 1 aman
                array_merge(['id_skemakkni' => $skemaId, 'profil_kandidat' => $profil], $data)
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bagian 3 MAPA-01 Telah Tersimpan/Terupdate',
                'data' => $mapa->fresh(),
                'rangkaian_selesai' => true,
            ], $mapa->wasRecentlyCreated ? 201 : 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan MAPA 1C',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== MAPA 2 - Peta Instrumen ====================

    /**
     * Get MAPA 2 by skema (Admin/Public)
     * GET /api/v1/skema/{id}/mapa2
     *
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function showMapa2($skemaId)
    {
        $skema = SkemaKkni::with(['unitKompetensi'])->findOrFail($skemaId);
        $muk = Muk::all();

        $mapa = SkemaMapa2::bySkema($skemaId)
            ->with(['unitKompetensi', 'muk'])
            ->get()
            ->groupBy(['id_unitkompetensi']);

        return response()->json([
            'success' => true,
            'data' => [
                'skema' => $skema,
                'muk' => $muk,
                'mapa2' => $mapa,
            ],
        ]);
    }

    /**
     * Store or update MAPA 2 (Admin)
     * POST /api/v1/admin/skema/{id}/mapa2
     *
     * @param Request $request
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeMapa2(Request $request, $skemaId)
    {
        $skema = SkemaKkni::findOrFail($skemaId);

        $validator = Validator::make($request->all(), [
            'data' => 'required|array|min:1',
            'data.*.id_unitkompetensi' => 'required|integer|exists:unit_kompetensi,id',
            'data.*.id_muk' => 'required|integer|exists:muk,id',
            'data.*.kandidat1' => 'nullable|in:1,0',
            'data.*.kandidat2' => 'nullable|in:1,0',
            'data.*.kandidat3' => 'nullable|in:1,0',
            'data.*.kandidat4' => 'nullable|in:1,0',
            'data.*.kandidat5' => 'nullable|in:1,0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $created = [];
            $updated = [];

            foreach ($request->data as $item) {
                $unitId = $item['id_unitkompetensi'];
                $mukId = $item['id_muk'];

                // Check if exists
                $mapa = SkemaMapa2::where('id_skema', $skemaId)
                    ->where('id_unitkompetensi', $unitId)
                    ->where('id_muk', $mukId)
                    ->first();

                $data = [
                    'id_skema' => $skemaId,
                    'id_unitkompetensi' => $unitId,
                    'id_muk' => $mukId,
                    'kandidat1' => $item['kandidat1'] ?? null,
                    'kandidat2' => $item['kandidat2'] ?? null,
                    'kandidat3' => $item['kandidat3'] ?? null,
                    'kandidat4' => $item['kandidat4'] ?? null,
                    'kandidat5' => $item['kandidat5'] ?? null,
                ];

                if ($mapa) {
                    $mapa->update($data);
                    $updated[] = $mapa;
                } else {
                    $mapa = SkemaMapa2::create($data);
                    $created[] = $mapa;
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($created) . ' data dibuat, ' . count($updated) . ' data diperbarui',
                'data' => [
                    'created' => $created,
                    'updated' => $updated,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan MAPA 2',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete MAPA 2 (Admin)
     * DELETE /api/v1/admin/mapa2/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyMapa2($id)
    {
        try {
            $mapa = SkemaMapa2::findOrFail($id);
            $mapa->delete();

            return response()->json([
                'success' => true,
                'message' => 'MAPA 2 berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus MAPA 2',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get kategori kandidat options
     * GET /api/v1/kategori-kandidat
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function kategoriKandidat()
    {
        $kategori = KategoriKandidat::orderBy('kode', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $kategori->map(function ($item) {
                return [
                    'value' => $item->kode,
                    'label' => $item->deskripsi,
                ];
            }),
        ]);
    }

    /**
     * Get muk options
     * GET /api/v1/muk
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function mukOptions()
    {
        $muk = Muk::orderBy('id', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $muk->map(function ($item) {
                return [
                    'value' => $item->id,
                    'label' => $item->judul,
                ];
            }),
        ]);
    }
}
