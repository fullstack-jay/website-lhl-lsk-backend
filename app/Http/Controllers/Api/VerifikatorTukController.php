<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JadwalAsesmen;
use App\Models\Asesor;
use App\Models\AsesorVerifikatortuk;
use App\Models\Tuk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class VerifikatorTukController extends Controller
{
    /**
     * Get verifikator TUK data for a schedule (or list of schedules if no ID given)
     *
     * @param int|null $jadwalId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVerifikatorData($jadwalId = null)
    {
        try {
            // 1. Get active schedules list with full details & verifikator status
            $activeSchedules = JadwalAsesmen::with(['skema:id,judul,kode_skema', 'tuk:id,nama,alamat,kelurahan'])
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($j) {
                    $assigned = DB::table('asesor_verifikatortuk')
                        ->join('asesor', 'asesor_verifikatortuk.id_asesor', '=', 'asesor.id')
                        ->where('asesor_verifikatortuk.id_jadwal', $j->id)
                        ->select('asesor.id', 'asesor.nama', 'asesor.gelar_depan', 'asesor.gelar_blk')
                        ->get();

                    $tglAsesmen = $j->tgl_asesmen ? Carbon::parse($j->tgl_asesmen) : Carbon::now();
                    $tglVerifikasi = $tglAsesmen->copy()->subDay()->format('Y-m-d');

                    $verifikatorNames = $assigned->map(function ($a) {
                        return trim(($a->gelar_depan ? $a->gelar_depan . ' ' : '') . $a->nama . ($a->gelar_blk ? ' ' . $a->gelar_blk : ''));
                    })->toArray();

                    return [
                        'id' => $j->id,
                        'nama_kegiatan' => $j->nama_kegiatan ?: ('Jadwal #' . $j->id . ' - ' . ($j->skema?->judul ?? 'Asesmen')),
                        'skema_id' => (int)$j->id_skemakkni,
                        'skema_judul' => $j->skema?->judul ?? '-',
                        'skema_kode' => $j->skema?->kode_skema ?? '-',
                        'periode' => $j->periode ?? '-',
                        'tahun' => (string)($j->tahun ?? ''),
                        'gelombang' => (int)($j->gelombang ?? 1),
                        'tuk_nama' => $j->tuk?->nama ?? '-',
                        'tuk_alamat' => $j->tuk?->alamat ?? '-',
                        'tuk_kelurahan' => $j->tuk?->kelurahan ?? '-',
                        'tgl_asesmen' => $j->tgl_asesmen ? Carbon::parse($j->tgl_asesmen)->format('Y-m-d') : '',
                        'jam_asesmen' => $j->jam_asesmen ?? '08:00',
                        'tgl_verifikasi' => $tglVerifikasi,
                        'kapasitas' => (int)($j->kapasitas ?? 20),
                        'peserta_count' => (int)$j->jumlah_peserta,
                        'status' => $j->status,
                        'verifikator_count' => $assigned->count(),
                        'verifikator_names' => $verifikatorNames,
                    ];
                });

            // 2. Determine target schedule if requested
            $jadwalInfo = null;
            $asesors = [];

            if ($jadwalId) {
                $query = JadwalAsesmen::with(['skema', 'tuk']);
                $jadwal = $query->find($jadwalId);

                if ($jadwal) {
                    $tglAsesmen = $jadwal->tgl_asesmen ? Carbon::parse($jadwal->tgl_asesmen) : Carbon::now();
                    $tglVerifikasi = $tglAsesmen->copy()->subDay()->format('Y-m-d');

                    $assignedRows = DB::table('asesor_verifikatortuk')
                        ->where('id_jadwal', $jadwal->id)
                        ->get();
                    $assignedAsesorIds = $assignedRows->pluck('id_asesor')->map(fn($id) => (int)$id)->toArray();

                    $asesors = DB::table('asesor')
                        ->select('id', 'nama', 'gelar_depan', 'gelar_blk', 'no_induk', 'no_lisensi', 'masaberlaku_lisensi', 'no_hp')
                        ->orderBy('nama', 'asc')
                        ->get()
                        ->map(function ($asesor) use ($jadwal, $assignedAsesorIds) {
                            $sk = DB::table('asesor_tugasskema')
                                ->where('id_asesor', $asesor->id)
                                ->where('id_skemakkni', $jadwal->id_skemakkni)
                                ->first();

                            $isAssigned = in_array((int)$asesor->id, $assignedAsesorIds, true);

                            return [
                                'id' => (int)$asesor->id,
                                'nama' => $asesor->nama,
                                'gelar_depan' => $asesor->gelar_depan ?? '',
                                'gelar_blk' => $asesor->gelar_blk ?? '',
                                'no_induk' => $asesor->no_induk ?: '-',
                                'no_lisensi' => $asesor->no_lisensi ?: '-',
                                'masaberlaku_lisensi' => $asesor->masaberlaku_lisensi ? Carbon::parse($asesor->masaberlaku_lisensi)->format('Y-m-d') : '2028-12-31',
                                'no_hp' => $asesor->no_hp ?: '-',
                                'no_sk_penugasan' => $sk?->no_sk ?: ($asesor->no_lisensi ? 'SK/' . $asesor->no_lisensi : 'SK/BNSP/' . $asesor->id),
                                'tanggal_sk' => $sk?->tanggal_sk ? Carbon::parse($sk->tanggal_sk)->format('Y-m-d') : ($asesor->masaberlaku_lisensi ? Carbon::parse($asesor->masaberlaku_lisensi)->subYears(3)->format('Y-m-d') : '2023-01-01'),
                                'is_assigned' => $isAssigned,
                            ];
                        });

                    $tuk = $jadwal->tuk;
                    $jadwalInfo = [
                        'id' => $jadwal->id,
                        'skema_id' => (int)$jadwal->id_skemakkni,
                        'skema_kode' => $jadwal->skema?->kode_skema ?? '-',
                        'skema_judul' => $jadwal->skema?->judul ?? '-',
                        'periode' => $jadwal->periode ?? '-',
                        'tahun' => (string)($jadwal->tahun ?? ''),
                        'gelombang' => (int)($jadwal->gelombang ?? 1),
                        'tgl_asesmen' => $jadwal->tgl_asesmen ? Carbon::parse($jadwal->tgl_asesmen)->format('Y-m-d') : '',
                        'jam_asesmen' => $jadwal->jam_asesmen ?? '08:00',
                        'kapasitas' => (int)($jadwal->kapasitas ?? 20),
                        'peserta_count' => (int)$jadwal->jumlah_peserta,
                        'tgl_verifikasi' => $tglVerifikasi,
                        'verifikator_count' => count($assignedAsesorIds),
                        'tuk' => [
                            'id' => $tuk?->id ?? 0,
                            'kode_tuk' => $tuk?->kode_tuk ?? '-',
                            'nama' => $tuk?->nama ?? ($jadwal->tempat ?? 'TUK'),
                            'penanggungjawab' => $tuk?->penanggungjawab ?? '-',
                            'alamat' => $tuk?->alamat ?? '-',
                            'kelurahan' => $tuk?->kelurahan ?? '-',
                            'telepon' => $tuk?->telepon ?? '-',
                            'no_lisensi' => $tuk?->no_lisensi ?? '-',
                            'masa_berlaku' => $tuk?->masa_berlaku ? Carbon::parse($tuk->masa_berlaku)->format('Y-m-d') : '-',
                        ],
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'jadwal' => $jadwalInfo,
                    'asesor' => $asesors,
                    'active_schedules' => $activeSchedules,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data verifikator: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign an asesor as verifikator for this schedule
     *
     * @param Request $request
     * @param int $jadwalId
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignVerifikator(Request $request, $jadwalId)
    {
        try {
            $request->validate([
                'id_asesor' => 'required|integer|exists:asesor,id',
            ]);

            $jadwal = JadwalAsesmen::find($jadwalId);
            if (!$jadwal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jadwal tidak ditemukan',
                ], 404);
            }

            $idAsesor = (int)$request->id_asesor;

            // Check if already assigned
            $exists = DB::table('asesor_verifikatortuk')
                ->where('id_jadwal', $jadwalId)
                ->where('id_asesor', $idAsesor)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => true,
                    'message' => 'Asesor sudah ditugaskan sebagai verifikator',
                ]);
            }

            $tglAsesmen = $jadwal->tgl_asesmen ? Carbon::parse($jadwal->tgl_asesmen) : Carbon::now();
            $tglVerifikasi = $tglAsesmen->copy()->subDay()->format('Y-m-d');

            DB::table('asesor_verifikatortuk')->insert([
                'id_asesor' => (string)$idAsesor,
                'id_jadwal' => $jadwalId,
                'id_skemakkni' => (string)$jadwal->id_skemakkni,
                'tgl_verifikasi' => $tglVerifikasi,
                'no_surattugas' => $request->no_surattugas ?? null,
                'tgl_surattugas' => $request->tgl_surattugas ?? null,
                'file_surattugas' => null,
                'keputusanverifikasi' => 'P',
                'waktu' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Verifikator TUK berhasil ditugaskan',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menugaskan verifikator: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unassign an asesor from verifikator for this schedule
     *
     * @param int $jadwalId
     * @param int $asesorId
     * @return \Illuminate\Http\JsonResponse
     */
    public function unassignVerifikator($jadwalId, $asesorId)
    {
        try {
            $deleted = DB::table('asesor_verifikatortuk')
                ->where('id_jadwal', $jadwalId)
                ->where('id_asesor', $asesorId)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => $deleted ? 'Verifikator TUK berhasil dihapus' : 'Verifikator tidak ditemukan',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus verifikator: ' . $e->getMessage(),
            ], 500);
        }
    }
}
