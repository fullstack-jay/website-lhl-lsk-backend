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
            // Get all jadwal for this event
            $jadwals = JadwalAsesmen::byEvent($event->id_event)
                ->with(['skema:id,judul,kode_skema', 'tuk:id,nama,alamat,kelurahan'])
                ->get();

            // Count total peserta
            $totalPeserta = 0;
            foreach ($jadwals as $jadwal) {
                $totalPeserta += $jadwal->asesi()->count();
            }

            // Get unique locations
            $locations = $jadwals->pluck('tuk.nama')->unique()->values()->implode(', ');

            return [
                'id_event' => $event->id_event,
                'tgl_mulai' => $event->tgl_mulai,
                'tgl_selesai' => $event->tgl_selesai,
                'jumlah_jadwal' => $event->jumlah_jadwal,
                'total_peserta' => $totalPeserta,
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
}
