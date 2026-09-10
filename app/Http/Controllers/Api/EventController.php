<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JadwalAsesmen;
use Illuminate\Http\Request;

class EventController extends Controller
{
    /**
     * Get all events with pagination
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = JadwalAsesmen::selectRaw('id_event, MIN(tgl_asesmen) as tgl_mulai, MAX(tgl_asesmen_akhir) as tgl_selesai, COUNT(*) as jumlah_jadwal')
            ->groupBy('id_event');

        // Search
        if ($request->has('search')) {
            $query->where('id_event', 'like', '%' . $request->search . '%');
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'tgl_mulai');
        $sortOrder = $request->get('sort_order', 'desc');

        if (!in_array($sortBy, ['tgl_mulai', 'tgl_selesai', 'id_event'])) {
            $sortBy = 'tgl_mulai';
        }

        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = min((int) $request->get('per_page', 20), 100);
        $page = (int) $request->get('page', 1);

        $events = $query->paginate($perPage, ['*'], 'page', $page);

        // Get additional data for each event
        $data = collect($events->items())->map(function ($event) {
            // Get all jadwal for this event (handle null/empty id_event)
            if (empty($event->id_event)) {
                $jadwals = JadwalAsesmen::where(function($q) {
                    $q->whereNull('id_event')->orWhere('id_event', '');
                })
                ->with(['skema:id,judul,kode_skema', 'tuk:id,nama,alamat,kelurahan'])
                ->get();
            } else {
                $jadwals = JadwalAsesmen::where('id_event', $event->id_event)
                    ->with(['skema:id,judul,kode_skema', 'tuk:id,nama,alamat,kelurahan'])
                    ->get();
            }

            // Count total peserta via direct DB count
            $totalPeserta = 0;
            foreach ($jadwals as $jadwal) {
                $totalPeserta += \DB::table('asesi_asesmen')
                    ->where('id_jadwal', $jadwal->id)
                    ->count();
            }

            // Get unique locations
            $locations = $jadwals->pluck('tuk.nama')->filter()->unique()->values()->implode(', ');
            $firstJadwal = $jadwals->first();

            $namaKegiatan = $event->id_event ?: ($firstJadwal ? $firstJadwal->nama_kegiatan : 'Event Penyelenggaraan Uji');
            $periode = $firstJadwal ? $firstJadwal->periode : null;
            $tahun = $firstJadwal ? $firstJadwal->tahun : null;
            $gelombang = $firstJadwal ? $firstJadwal->gelombang : null;
            $tukNama = $locations ?: ($firstJadwal && $firstJadwal->tuk ? $firstJadwal->tuk->nama : '-');

            return [
                'id_event' => $event->id_event ?: ('EVT-JADWAL-' . ($firstJadwal ? $firstJadwal->id : '1')),
                'raw_id_event' => $event->id_event,
                'nama_kegiatan' => $namaKegiatan,
                'periode' => $periode,
                'tahun' => $tahun,
                'gelombang' => $gelombang,
                'tgl_asesmen' => $event->tgl_mulai,
                'tgl_mulai' => $event->tgl_mulai,
                'tgl_selesai' => $event->tgl_selesai,
                'jumlah_jadwal' => $jadwals->count(),
                'total_peserta' => $totalPeserta,
                'tuk_nama' => $tukNama,
                'lokasi' => $locations,
                'jadwals' => $jadwals->map(function ($jadwal) {
                    return [
                        'id' => $jadwal->id,
                        'nama_kegiatan' => $jadwal->nama_kegiatan,
                        'tgl_asesmen' => $jadwal->tgl_asesmen ? $jadwal->tgl_asesmen->format('Y-m-d') : null,
                        'jam_asesmen' => $jadwal->jam_asesmen,
                        'skema' => $jadwal->skema ? [
                            'judul' => $jadwal->skema->judul,
                            'kode_skema' => $jadwal->skema->kode_skema,
                        ] : null,
                        'tuk' => $jadwal->tuk ? [
                            'nama' => $jadwal->tuk->nama,
                            'alamat' => $jadwal->tuk->alamat,
                            'kelurahan' => $jadwal->tuk->kelurahan,
                        ] : null,
                        'jumlah_peserta' => \DB::table('asesi_asesmen')
                            ->where('id_jadwal', $jadwal->id)
                            ->count(),
                        'status' => $jadwal->status,
                    ];
                }),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'last_page' => $events->lastPage(),
                'from' => $events->firstItem(),
                'to' => $events->lastItem(),
            ],
        ]);
    }

    /**
     * Get event detail by ID
     *
     * @param string $eventId
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($eventId)
    {
        $jadwals = JadwalAsesmen::byEvent($eventId)
            ->with([
                'skema:id,judul,kode_skema',
                'tuk:id,nama,alamat,kelurahan,kodepos,telepon,email',
                'asesor:id,nama,gelar_depan,gelar_blk,no_lisensi',
                'komite:id,nama,gelar_depan,gelar_blk',
            ])
            ->orderBy('tgl_asesmen')
            ->get();

        if ($jadwals->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Event tidak ditemukan',
            ], 404);
        }

        // Get summary
        $summary = [
            'id_event' => $eventId,
            'tgl_mulai' => $jadwals->min('tgl_asesmen'),
            'tgl_selesai' => $jadwals->max('tgl_asesmen_akhir'),
            'jumlah_jadwal' => $jadwals->count(),
        ];

        // Count total peserta
        $totalPeserta = 0;
        foreach ($jadwals as $jadwal) {
            // Use raw query to avoid relationship issues
            $totalPeserta += \DB::table('asesi_asesmen')
                ->where('id_jadwal', $jadwal->id)
                ->count();
        }
        $summary['total_peserta'] = $totalPeserta;

        // Get unique locations
        $locations = $jadwals->pluck('tuk.nama')->unique()->values();
        $summary['lokasi'] = $locations->implode(', ');

        // Get unique skema
        $skemas = $jadwals->pluck('skema.judul')->unique()->values();
        $summary['skema'] = $skemas->implode(', ');

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'jadwals' => $jadwals->map(function ($jadwal) {
                    $data = [
                        'id' => $jadwal->id,
                        'nama_kegiatan' => $jadwal->nama_kegiatan,
                        'tahun' => $jadwal->tahun,
                        'periode' => $jadwal->periode,
                        'gelombang' => $jadwal->gelombang,
                        'tgl_asesmen' => $jadwal->tgl_asesmen ? $jadwal->tgl_asesmen->format('Y-m-d') : null,
                        'tgl_asesmen_akhir' => $jadwal->tgl_asesmen_akhir ? $jadwal->tgl_asesmen_akhir->format('Y-m-d') : null,
                        'jam_asesmen' => $jadwal->jam_asesmen,
                        'status' => $jadwal->status,
                        'kapasitas' => $jadwal->kapasitas,
                        'jumlah_peserta' => $jadwal->asesi()->count(),
                        'skema' => $jadwal->skema ? [
                            'id' => $jadwal->skema->id,
                            'judul' => $jadwal->skema->judul,
                            'kode_skema' => $jadwal->skema->kode_skema,
                        ] : null,
                        'tuk' => $jadwal->tuk ? [
                            'id' => $jadwal->tuk->id,
                            'nama' => $jadwal->tuk->nama,
                            'alamat' => $jadwal->tuk->alamat,
                            'kelurahan' => $jadwal->tuk->kelurahan,
                            'kodepos' => $jadwal->tuk->kodepos,
                            'telepon' => $jadwal->tuk->telepon,
                            'email' => $jadwal->tuk->email,
                        ] : null,
                        'asesor' => $jadwal->asesor->map(function ($asesor) {
                            return [
                                'id' => $asesor->id,
                                'nama' => $asesor->nama,
                                'gelar_depan' => $asesor->gelar_depan,
                                'gelar_blk' => $asesor->gelar_blk,
                                'no_lisensi' => $asesor->no_lisensi,
                            ];
                        }),
                        'komite' => $jadwal->komite->map(function ($komite) {
                            return [
                                'id' => $komite->id,
                                'nama' => $komite->nama,
                                'gelar_depan' => $komite->gelar_depan,
                                'gelar_blk' => $komite->gelar_blk,
                            ];
                        }),
                        'no_surattugas' => $jadwal->no_surattugas,
                        'file_surattugas' => $jadwal->file_surattugas,
                        'file_surattugas_url' => $jadwal->file_surattugas ? asset('foto_surat/' . $jadwal->file_surattugas) : null,
                    ];

                    return $data;
                }),
            ],
        ]);
    }

    /**
     * Get event statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics()
    {
        $totalEvents = JadwalAsesmen::selectRaw('COUNT(DISTINCT id_event) as total')
            ->value('total');

        $totalJadwal = JadwalAsesmen::count();
        $totalPeserta = 0;

        // Get all active jadwal and count peserta
        $jadwals = JadwalAsesmen::active()->get();
        foreach ($jadwals as $jadwal) {
            // Use raw query to avoid relationship issues
            $totalPeserta += \DB::table('asesi_asesmen')
                ->where('id_jadwal', $jadwal->id)
                ->count();
        }

        // Get completed events
        $completedEvents = JadwalAsesmen::selectRaw('COUNT(DISTINCT id_event) as total')
            ->where('status', 'Selesai')
            ->value('total');

        return response()->json([
            'success' => true,
            'data' => [
                'total_events' => $totalEvents,
                'total_jadwal' => $totalJadwal,
                'total_peserta' => $totalPeserta,
                'completed_events' => $completedEvents,
                'active_events' => $totalEvents - $completedEvents,
            ],
        ]);
    }

    /**
     * Resolve jadwals belonging to an event identifier
     */
    protected function resolveJadwalsByEventId($eventId)
    {
        if (str_starts_with($eventId, 'EVT-JADWAL-')) {
            $id = substr($eventId, 11);
            $jadwal = JadwalAsesmen::find($id);
            if ($jadwal) {
                if (!empty($jadwal->id_event)) {
                    return JadwalAsesmen::where('id_event', $jadwal->id_event)->get();
                }
                return JadwalAsesmen::where('id', $id)->get();
            }
        }

        $jadwals = JadwalAsesmen::where('id_event', $eventId)->get();
        if ($jadwals->isNotEmpty()) {
            return $jadwals;
        }

        if (is_numeric($eventId)) {
            $jadwals = JadwalAsesmen::where('id', $eventId)->get();
            if ($jadwals->isNotEmpty()) {
                return $jadwals;
            }
        }

        return JadwalAsesmen::whereNull('id_event')->orWhere('id_event', '')->get();
    }

    /**
     * Get Rekap Nilai Peserta Uji Kompetensi for an event
     *
     * @param string $eventId
     * @return \Illuminate\Http\JsonResponse
     */
    public function rekapNilai($eventId)
    {
        $jadwals = $this->resolveJadwalsByEventId($eventId);

        if ($jadwals->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Event tidak ditemukan',
            ], 404);
        }

        $firstJadwal = $jadwals->first();
        $jadwalIds = $jadwals->pluck('id')->toArray();

        // Event info
        $locations = $jadwals->pluck('tempat_asesmen')->unique()->toArray();
        $tuk = \App\Models\Tuk::whereIn('id', $locations)->first();

        $namaKegiatan = $firstJadwal->nama_kegiatan ?: $eventId;
        $periode = $firstJadwal->periode ?: '';
        $tahun = $firstJadwal->tahun ?: '';
        $gelombang = $firstJadwal->gelombang ?: 1;
        $tglAsesmen = $firstJadwal->tgl_asesmen ? $firstJadwal->tgl_asesmen->format('Y-m-d') : null;
        $tukNama = $tuk ? $tuk->nama : ($firstJadwal->tempat_asesmen ?: '-');

        // Ketua LSK info and signature from jadwal_asesmen
        $ttdKetuaLsk = $firstJadwal->ttd_ketua_lsk;
        $tglTtdKetuaLsk = $firstJadwal->tgl_ttd_ketua_lsk;

        // Fetch participants in this event
        $pesertaQuery = \DB::table('asesi_asesmen')
            ->whereIn('asesi_asesmen.id_jadwal', $jadwalIds)
            ->join('asesi', 'asesi.no_pendaftaran', '=', 'asesi_asesmen.id_asesi')
            ->leftJoin('skema_kkni', 'asesi_asesmen.id_skemakkni', '=', 'skema_kkni.id')
            ->leftJoin('penilaian_asesi', function ($join) {
                $join->on('asesi_asesmen.id_jadwal', '=', 'penilaian_asesi.id_jadwal')
                     ->on('asesi_asesmen.id_asesi', '=', 'penilaian_asesi.no_pendaftaran');
            })
            ->select(
                'asesi_asesmen.id as id_asesmen',
                'asesi_asesmen.id_jadwal',
                'asesi_asesmen.id_asesi as no_pendaftaran',
                'asesi.nama as nama_peserta',
                'skema_kkni.judul as nama_skema',
                'skema_kkni.kode_skema',
                'penilaian_asesi.id as id_penilaian',
                'penilaian_asesi.nilai_vp',
                'penilaian_asesi.skor_vp',
                'penilaian_asesi.nilai_pt',
                'penilaian_asesi.skor_pt',
                'penilaian_asesi.nilai_dpsk',
                'penilaian_asesi.skor_dpsk',
                'penilaian_asesi.nilai_pw',
                'penilaian_asesi.skor_pw',
                'penilaian_asesi.total_skor',
                'penilaian_asesi.rekomendasi',
                'penilaian_asesi.catatan',
                'penilaian_asesi.tgl_penilaian'
            )
            ->orderBy('penilaian_asesi.id', 'desc')
            ->orderBy('asesi.nama', 'asc')
            ->get();

        $pesertaData = $pesertaQuery->map(function ($item, $index) {
            $isDinilai = !is_null($item->total_skor);
            $rekomendasi = strtoupper(trim($item->rekomendasi ?? ''));

            return [
                'no' => $index + 1,
                'id_asesmen' => $item->id_asesmen,
                'id_jadwal' => $item->id_jadwal,
                'no_pendaftaran' => $item->no_pendaftaran,
                'nama_peserta' => $item->nama_peserta,
                'skema' => $item->nama_skema ?: 'Anggota Tim Penyusun Amdal (ATPA)',
                'kode_skema' => $item->kode_skema,
                'is_dinilai' => $isDinilai,
                'nilai_vp' => $isDinilai ? (float) $item->nilai_vp : null,
                'skor_vp' => $isDinilai ? (float) $item->skor_vp : null,
                'nilai_pt' => $isDinilai ? (float) $item->nilai_pt : null,
                'skor_pt' => $isDinilai ? (float) $item->skor_pt : null,
                'nilai_dpsk' => $isDinilai ? (float) $item->nilai_dpsk : null,
                'skor_dpsk' => $isDinilai ? (float) $item->skor_dpsk : null,
                'nilai_pw' => $isDinilai ? (float) $item->nilai_pw : null,
                'skor_pw' => $isDinilai ? (float) $item->skor_pw : null,
                'total_skor' => $isDinilai ? (float) $item->total_skor : null,
                'rekomendasi' => $rekomendasi,
                'is_kompeten' => $rekomendasi === 'K',
                'is_belum_kompeten' => $rekomendasi === 'BK',
                'catatan' => $item->catatan,
                'tgl_penilaian' => $item->tgl_penilaian,
            ];
        });

        // Determine title schema
        $uniqueSkemas = $pesertaData->pluck('skema')->unique()->filter()->values();
        $skemaTitle = $uniqueSkemas->isNotEmpty() 
            ? $uniqueSkemas->implode(' & ') 
            : 'ANGGOTA DAN KETUA TIM PENYUSUNAN AMDAL';

        return response()->json([
            'success' => true,
            'data' => [
                'event' => [
                    'id_event' => $eventId,
                    'nama_kegiatan' => $namaKegiatan,
                    'periode' => $periode,
                    'tahun' => $tahun,
                    'gelombang' => $gelombang,
                    'tgl_asesmen' => $tglAsesmen,
                    'tuk_nama' => $tukNama,
                    'skema_title' => $skemaTitle,
                    'jumlah_peserta' => $pesertaData->count(),
                    'jumlah_dinilai' => $pesertaData->where('is_dinilai', true)->count(),
                    'jumlah_kompeten' => $pesertaData->where('is_kompeten', true)->count(),
                    'jumlah_belum_kompeten' => $pesertaData->where('is_belum_kompeten', true)->count(),
                    'nama_ketua_lsk' => 'Rusdani Sosiawan, S.Pi., M.Ling',
                    'jabatan_ketua_lsk' => 'Ketua Amdal LSK - Lingkungan Hidup Lestari',
                    'ttd_ketua_lsk' => $ttdKetuaLsk,
                    'tgl_ttd_ketua_lsk' => $tglTtdKetuaLsk,
                ],
                'peserta' => $pesertaData,
            ],
        ]);
    }

    /**
     * Save Ketua LSK signature for an event
     *
     * @param \Illuminate\Http\Request $request
     * @param string $eventId
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveTtdKetua(Request $request, $eventId)
    {
        $signature = $request->input('signature');
        if (empty($signature)) {
            return response()->json([
                'success' => false,
                'message' => 'Tanda tangan tidak boleh kosong',
            ], 422);
        }

        $jadwals = $this->resolveJadwalsByEventId($eventId);
        if ($jadwals->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Event tidak ditemukan',
            ], 404);
        }

        $jadwalIds = $jadwals->pluck('id')->toArray();
        \DB::table('jadwal_asesmen')
            ->whereIn('id', $jadwalIds)
            ->update([
                'ttd_ketua_lsk' => $signature,
                'tgl_ttd_ketua_lsk' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Tanda tangan Ketua LSK berhasil disimpan',
            'data' => [
                'id_event' => $eventId,
                'tgl_ttd_ketua_lsk' => now()->toDateTimeString(),
            ],
        ]);
    }
}
