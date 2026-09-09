<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePenilaianAsesiRequest;
use App\Models\Asesi;
use App\Models\AsesiAsesmen;
use App\Models\Asesor;
use App\Models\PenilaianAsesi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Form Penilaian Asesi (4 Instrumen Uji) — Portal PENGUJI
 * Sesuai docs/BACKEND_FORM_PENILAIAN.md:
 *
 * - Bobot: VP 20% · PT 10% · DPSK 35% · PW 35% (total 100%)
 * - Passing grade 70.00 → K (>=70) / BK (<70)
 * - ⭐ Kalkulasi nilai terbobot & penentuan kelulusan = SINGLE SOURCE OF TRUTH
 *   di backend — frontend TIDAK mengirim status kelulusan (anti manipulasi)
 * - Upsert ke penilaian_asesi + sinkron asesi_asesmen (status_asesmen,
 *   id_asesor, tgl_asesmen, catatan_asesmen) dalam satu transaction
 */
class PenilaianAsesiController extends Controller
{
    /**
     * Rekapitulasi penilaian seluruh asesi pada jadwal.
     * GET /api/v1/penguji/jadwal/{id_jadwal}/penilaian
     */
    public function index(Request $request, $id_jadwal): JsonResponse
    {
        [$asesor, $error] = $this->resolvePenguji($request, $id_jadwal);
        if ($error) {
            return $error;
        }

        $penilaianList = PenilaianAsesi::where('id_jadwal', $id_jadwal)
            ->get()
            ->keyBy('no_pendaftaran');

        $peserta = AsesiAsesmen::where('id_jadwal', $id_jadwal)
            ->join('asesi', 'asesi.no_pendaftaran', '=', 'asesi_asesmen.id_asesi')
            ->select([
                'asesi.no_pendaftaran',
                'asesi.nama',
                'asesi.nama_kantor',
                'asesi.jabatan',
                'asesi_asesmen.status_asesmen',
            ])
            ->get()
            ->map(function ($p) use ($penilaianList) {
                $pen = $penilaianList->get($p->no_pendaftaran);
                return [
                    'no_pendaftaran' => $p->no_pendaftaran,
                    'nama' => $p->nama,
                    'instansi' => $p->nama_kantor ?? $p->jabatan,
                    'status_asesmen' => $p->status_asesmen,
                    'is_dinilai' => (bool) $pen,
                    'total_skor' => $pen?->total_skor,
                    'rekomendasi' => $pen?->rekomendasi,
                    'breakdown' => $pen ? [
                        'vp' => $pen->nilai_vp,
                        'pt' => $pen->nilai_pt,
                        'dpsk' => $pen->nilai_dpsk,
                        'pw' => $pen->nilai_pw,
                    ] : null,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $peserta,
        ]);
    }

    /**
     * Ambil penilaian satu asesi.
     * GET /api/v1/penguji/jadwal/{id_jadwal}/penilaian/{no_pendaftaran}
     */
    public function show(Request $request, $id_jadwal, $no_pendaftaran): JsonResponse
    {
        [$asesor, $error] = $this->resolvePenguji($request, $id_jadwal);
        if ($error) {
            return $error;
        }

        $penilaian = PenilaianAsesi::with(['asesiAsesmen.asesi'])
            ->where('id_jadwal', $id_jadwal)
            ->where('no_pendaftaran', $no_pendaftaran)
            ->first();

        if (!$penilaian) {
            return response()->json([
                'success' => false,
                'message' => 'Penilaian belum tersedia untuk peserta ini.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $penilaian,
        ]);
    }

    /**
     * Simpan / Perbarui Penilaian Asesi (Upsert)
     * POST /api/v1/penguji/jadwal/{id_jadwal}/penilaian/{no_pendaftaran}
     *
     * Backend menghitung ulang seluruh nilai terbobot & kelulusan secara
     * independen — input status kelulusan dari client DIABAIKAN.
     */
    public function store(StorePenilaianAsesiRequest $request, $id_jadwal, $no_pendaftaran): JsonResponse
    {
        // 1. Verifikasi jadwal
        $jadwal = DB::table('jadwal_asesmen')->where('id', $id_jadwal)->first();
        if (!$jadwal) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal uji tidak ditemukan.',
            ], 404);
        }

        // 2. Verifikasi asesi & pendaftaran di jadwal ini
        $asesi = Asesi::where('no_pendaftaran', $no_pendaftaran)->first();
        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Data asesi tidak ditemukan.',
            ], 404);
        }

        $asesiAsesmen = AsesiAsesmen::where('id_jadwal', $id_jadwal)
            ->where('id_asesi', $no_pendaftaran)
            ->first();

        if (!$asesiAsesmen) {
            return response()->json([
                'success' => false,
                'message' => 'Asesi tidak terdaftar pada jadwal asesmen ini.',
            ], 422);
        }

        // 3. Verifikasi penguji login + ownership jadwal (tim asesor via pivot)
        $user = $request->user();
        $asesor = null;
        if ($user) {
            $asesor = Asesor::where('no_ktp', $user->username)
                ->orWhere('no_ktp', $user->no_ktp)
                ->first();
        }

        if (!$asesor) {
            $idAsesorJadwal = DB::table('jadwal_asesor')
                ->where('id_jadwal', $id_jadwal)
                ->value('id_asesor');
            if ($idAsesorJadwal) {
                $asesor = Asesor::find($idAsesorJadwal);
            }
        }

        if (!$asesor) {
            return response()->json([
                'success' => false,
                'message' => 'Profil penguji untuk jadwal ini tidak ditemukan.',
            ], 403);
        }

        $idAsesor = $asesor->id;
        $validated = $request->validated();

        // 4. ⭐ Kalkulasi nilai terbobot di backend (single source of truth)
        $hasil = PenilaianAsesi::hitung($validated);

        DB::beginTransaction();
        try {
            // A. Upsert penilaian_asesi
            $penilaian = PenilaianAsesi::updateOrCreate(
                [
                    'id_jadwal' => $id_jadwal,
                    'no_pendaftaran' => $no_pendaftaran,
                ],
                [
                    'id_asesmen' => $asesiAsesmen->id,
                    'id_asesor' => $idAsesor,
                    'nilai_vp' => $hasil['nilai_vp'],
                    'nilai_pt' => $hasil['nilai_pt'],
                    'nilai_dpsk' => $hasil['nilai_dpsk'],
                    'nilai_pw' => $hasil['nilai_pw'],
                    'skor_vp' => $hasil['skor_vp'],
                    'skor_pt' => $hasil['skor_pt'],
                    'skor_dpsk' => $hasil['skor_dpsk'],
                    'skor_pw' => $hasil['skor_pw'],
                    'total_skor' => $hasil['total_skor'],
                    'rekomendasi' => $hasil['rekomendasi'],
                    'catatan' => $validated['catatan'] ?? null,
                    'rubrik_detail' => $validated['rubrik_detail'] ?? null,
                    'tgl_penilaian' => now()->toDateString(),
                ]
            );

            // B. Sinkronkan status ke asesi_asesmen
            $asesiAsesmen->update([
                'status_asesmen' => $hasil['rekomendasi'],
                'id_asesor' => $idAsesor,
                'tgl_asesmen' => now()->toDateString(),
                'catatan_asesmen' => $validated['catatan'] ?? null,
            ]);

            DB::commit();

            $label = $hasil['rekomendasi'] === 'K' ? 'Kompeten' : 'Belum Kompeten';

            return response()->json([
                'success' => true,
                'message' => "Penilaian untuk {$asesi->nama} berhasil disimpan dengan predikat {$label}.",
                'data' => [
                    'id_penilaian' => $penilaian->id,
                    'id_jadwal' => (int) $id_jadwal,
                    'no_pendaftaran' => $no_pendaftaran,
                    'nama_asesi' => $asesi->nama,
                    'rincian_nilai' => [
                        'vp' => ['nilai' => $hasil['nilai_vp'], 'bobot_persen' => 20, 'terbobot' => $hasil['skor_vp']],
                        'pt' => ['nilai' => $hasil['nilai_pt'], 'bobot_persen' => 10, 'terbobot' => $hasil['skor_pt']],
                        'dpsk' => ['nilai' => $hasil['nilai_dpsk'], 'bobot_persen' => 35, 'terbobot' => $hasil['skor_dpsk']],
                        'pw' => ['nilai' => $hasil['nilai_pw'], 'bobot_persen' => 35, 'terbobot' => $hasil['skor_pw']],
                    ],
                    'total_skor' => $hasil['total_skor'],
                    'standar_minimum' => PenilaianAsesi::PASSING_GRADE,
                    'is_lulus' => $hasil['is_lulus'],
                    'status_asesmen' => $hasil['rekomendasi'],
                    'status_asesmen_label' => $label,
                    'catatan' => $penilaian->catatan,
                    'asesor_penilai' => $asesor->full_name,
                    'tgl_penilaian' => $penilaian->tgl_penilaian->toDateString(),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan penilaian: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    /**
     * Resolve penguji dari token + ownership jadwal (tim asesor via pivot).
     * Return [asesor, errorResponse]
     */
    private function resolvePenguji(Request $request, $id_jadwal): array
    {
        $user = $request->user();
        if (!$user) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401)];
        }

        $asesor = Asesor::where('no_ktp', $user->username)
            ->orWhere('no_ktp', $user->no_ktp)
            ->first();

        if (!$asesor) {
            $idAsesorJadwal = DB::table('jadwal_asesor')
                ->where('id_jadwal', $id_jadwal)
                ->value('id_asesor');
            if ($idAsesorJadwal) {
                $asesor = Asesor::find($idAsesorJadwal);
            }
        }

        if (!$asesor) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Profil penguji tidak ditemukan.',
            ], 404)];
        }

        return [$asesor, null];
    }
}
