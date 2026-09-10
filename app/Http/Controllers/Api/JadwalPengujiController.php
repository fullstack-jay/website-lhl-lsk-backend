<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Jadwal Uji Kompetensi (Jadwal TUK) — Portal PENGUJI
 * GET /api/v1/penguji/jadwal-uji  (auth:sanctum, role penguji)
 * Implementasi modul `jadwalasesmen` PHP Native versi API.
 * Sesuai docs/BACKEND_PENGUJI_JADWAL_UJI_KOMPETENSI.md:
 *
 * - Daftar jadwal via PIVOT `jadwal_asesor` (BUKAN kolom legacy id_asesor)
 * - Data kartu: nama kegiatan (fallback periode+tahun+gelombang), tgl/jam,
 *   TUK (nama/alamat/kelurahan), skema + jenis standar (skkni),
 *   kapasitas vs terjadwal (COUNT asesi_asesmen), tim sesama asesor,
 *   unduh surat tugas
 * - Tombol aksi KONDISIONAL — hanya bila ada peserta terjadwal
 * - Aksi list: Lihat Peserta, Berita Acara, MAPA-01 (5 kandidat),
 *   MAPA-02, AK-05, AK-06 (input & unduh)
 *
 * TIDAK ADA write handler (tambah/hapus jadwal = dead-code native dihapus;
 * juga mencegah privilege escalation via POST manual).
 */
class JadwalPengujiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // ── Guard role penguji ──
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }
        if (!$user->isPenguji()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus penguji.',
            ], 403);
        }

        // ── Resolve asesor dari token (session native = no_ktp) ──
        $asesor = Asesor::where('no_ktp', $user->username)
            ->orWhere('no_ktp', $user->no_ktp)
            ->first();

        if (!$asesor) {
            return response()->json([
                'success' => true,
                'data' => ['asesor' => null, 'jumlah_jadwal' => 0, 'jadwal' => []],
            ]);
        }

        // ── Daftar tugas via PIVOT jadwal_asesor (idem native, CQ #1) ──
        $rows = DB::table('jadwal_asesor as ja')
            ->join('jadwal_asesmen as j', 'j.id', '=', 'ja.id_jadwal')
            ->leftJoin('tuk as t', 't.id', '=', DB::raw("NULLIF(j.tempat_asesmen, '')"))
            ->leftJoin('skema_kkni as sk', 'sk.id', '=', DB::raw("NULLIF(j.id_skemakkni, '')"))
            ->leftJoin('skkni as skk', 'skk.id', '=', 'sk.id_skkni')
            ->where('ja.id_asesor', $asesor->id)
            ->orderByRaw('j.tgl_asesmen DESC')
            ->selectRaw("
                j.id, j.nama_kegiatan, j.tahun, j.periode, j.gelombang,
                j.tgl_asesmen, j.tgl_asesmen_akhir, j.jam_asesmen,
                j.tempat_asesmen, t.nama AS tuk_nama, t.alamat AS tuk_alamat,
                t.kelurahan AS tuk_kelurahan,
                j.id_skemakkni, sk.kode_skema, sk.judul AS skema_judul,
                skk.no_skkni AS jenis_standar,
                j.kapasitas, j.status, j.file_surattugas, j.no_surattugas,
                (SELECT COUNT(*) FROM asesi_asesmen a WHERE a.id_jadwal = j.id) AS jumlah_peserta
            ")
            ->get();

        $jadwal = $rows->map(function ($j) use ($asesor) {
            // Nama kegiatan fallback (idem native): "{periode} {tahun} Gelombang {g}"
            $namaKegiatan = $j->nama_kegiatan;
            if (empty(trim((string) $namaKegiatan))) {
                $namaKegiatan = trim("{$j->periode} {$j->tahun} Gelombang {$j->gelombang}");
            }

            // Surat tugas URL + file_exists guard
            $suratTugasUrl = null;
            if (!empty($j->file_surattugas)) {
                $path = public_path('foto_surat/' . $j->file_surattugas);
                if (file_exists($path)) {
                    $suratTugasUrl = asset('foto_surat/' . $j->file_surattugas);
                }
            }

            // Verifikasi TUK status & tim verifikator
            $verifRows = DB::table('asesor_verifikatortuk as v')
                ->leftJoin('asesor as a', 'a.id', '=', DB::raw('CAST(v.id_asesor AS UNSIGNED)'))
                ->where('v.id_jadwal', $j->id)
                ->get(['a.id', 'a.nama', 'a.gelar_depan', 'a.gelar_blk', 'v.keputusanverifikasi', 'v.tgl_verifikasi', 'v.waktu']);

            if ($verifRows->isNotEmpty()) {
                $adaY = $verifRows->contains('keputusanverifikasi', 'Y');
                $adaN = $verifRows->contains('keputusanverifikasi', 'N');
                $keputusan = $adaY ? 'Y' : ($adaN ? 'N' : 'P');
                $label = match ($keputusan) {
                    'Y' => 'Sesuai Persyaratan Skema',
                    'N' => 'Tidak Sesuai Persyaratan Skema',
                    default => 'Belum Dilaksanakan Verifikasi',
                };
                $first = $verifRows->first();
                $timVerifikator = $verifRows->map(function ($a) {
                    return [
                        'id' => $a->id,
                        'nama_lengkap' => trim(($a->gelar_depan ? $a->gelar_depan . ' ' : '') . $a->nama . ($a->gelar_blk ? ', ' . $a->gelar_blk : '')),
                        'keputusan' => $a->keputusanverifikasi,
                    ];
                })->values()->all();

                $verifikasiTuk = [
                    'terverifikasi' => in_array($keputusan, ['Y', 'N']),
                    'keputusan' => $keputusan,
                    'keputusan_label' => $label,
                    'tgl_verifikasi' => $first->tgl_verifikasi,
                    'waktu_verifikasi' => $first->waktu,
                    'tim_verifikator' => $timVerifikator,
                ];
            } else {
                $verifikasiTuk = [
                    'terverifikasi' => false,
                    'keputusan' => null,
                    'keputusan_label' => 'Belum Ada Verifikator',
                    'tgl_verifikasi' => null,
                    'waktu_verifikasi' => null,
                    'tim_verifikator' => [],
                ];
            }

            return [
                'id_jadwal' => $j->id,
                'nama_kegiatan' => $namaKegiatan,
                'nama_kegiatan_asli' => $j->nama_kegiatan,   // null bila fallback dipakai
                'tahun' => $j->tahun ? (int) $j->tahun : null,
                'periode' => $j->periode,
                'gelombang' => $j->gelombang ? (int) $j->gelombang : null,
                'tanggal' => [
                    'tgl_asesmen' => $j->tgl_asesmen,
                    'tgl_asesmen_formatted' => $this->tglIndo($j->tgl_asesmen),
                    'tgl_asesmen_akhir' => $j->tgl_asesmen_akhir,
                    'jam_asesmen' => $j->jam_asesmen,
                ],
                'tempat' => $j->tempat_asesmen ? [
                    'id' => is_numeric($j->tempat_asesmen) ? (int) $j->tempat_asesmen : null,
                    'nama' => $j->tuk_nama ?? $j->tempat_asesmen,
                    'alamat' => $j->tuk_alamat,
                    'kelurahan' => $j->tuk_kelurahan,
                ] : null,
                'skema' => $j->id_skemakkni ? [
                    'id' => is_numeric($j->id_skemakkni) ? (int) $j->id_skemakkni : null,
                    'kode_skema' => $j->kode_skema,
                    'judul' => $j->skema_judul,
                    'jenis_standar' => $j->jenis_standar,   // no_skkni
                ] : null,
                'kapasitas' => $j->kapasitas ? (int) $j->kapasitas : null,
                'jumlah_peserta' => (int) $j->jumlah_peserta,
                'status' => $j->status,                     // Draft/Terkonfirmasi/Berlangsung/Selesai
                'verifikasi_tuk' => $verifikasiTuk,
                'surat_tugas' => [
                    'no_surattugas' => $j->no_surattugas,
                    'file' => $j->file_surattugas,
                    'url' => $suratTugasUrl,                // null bila file hilang (guard)
                ],
                // ⭐ Aksi kondisional — hanya jika ada peserta (idem native)
                'punya_peserta' => (int) $j->jumlah_peserta > 0,
                'aksi' => $this->buildAksi($j, (int) $j->jumlah_peserta),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'asesor' => [
                    'id' => $asesor->id,
                    'nama_lengkap' => $asesor->full_name,
                    'no_ktp' => $asesor->no_ktp,
                    'no_induk' => $asesor->no_induk,
                    'foto_url' => $asesor->foto ? asset('foto_asesor/' . $asesor->foto) : null,
                ],
                'jumlah_jadwal' => $jadwal->count(),
                'jadwal' => $jadwal,
            ],
        ]);
    }

    /**
     * GET /api/v1/penguji/jadwal-uji/{idJadwal}/peserta
     * Daftar sesama asesor tim + detail lengkap satu jadwal.
     */
    public function show(Request $request, $idJadwal): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isPenguji()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus penguji.',
            ], 403);
        }

        $asesor = Asesor::where('no_ktp', $user->username)
            ->orWhere('no_ktp', $user->no_ktp)->first();
        if (!$asesor) {
            return response()->json([
                'success' => false,
                'message' => 'Profil penguji tidak ditemukan',
            ], 404);
        }

        // Ownership: pastikan penguji ini ditugaskan di jadwal tsb (via pivot)
        $assigned = DB::table('jadwal_asesor')
            ->where('id_jadwal', $idJadwal)
            ->where('id_asesor', $asesor->id)
            ->exists();

        if (!$assigned) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak ditugaskan pada jadwal ini',
            ], 403);
        }

        // Tim asesor (CQ #2)
        $tim = DB::table('jadwal_asesor as ja')
            ->join('asesor as a', 'a.id', '=', 'ja.id_asesor')
            ->where('ja.id_jadwal', $idJadwal)
            ->get([
                'a.id', 'a.nama', 'a.gelar_depan', 'a.gelar_blk', 'a.foto',
            ])
            ->map(function ($a) {
                $depan = trim((string) $a->gelar_depan);
                $blk = trim((string) $a->gelar_blk);
                $nama = ($depan ? $depan . ' ' : '') . $a->nama . ($blk ? ', ' . $blk : '');
                return [
                    'id' => $a->id,
                    'nama_lengkap' => $nama,
                    'foto_url' => $a->foto ? asset('foto_asesor/' . $a->foto) : null,
                ];
            });

        // Peserta terjadwal
        $peserta = DB::table('asesi_asesmen as aa')
            ->join('asesi as a', 'a.no_pendaftaran', '=', 'aa.id_asesi')
            ->where('aa.id_jadwal', $idJadwal)
            ->orderBy('a.nama')
            ->get([
                'a.no_pendaftaran', 'a.nama', 'a.jenis_kelamin',
                'a.email', 'a.nohp',
                'a.nama_kantor', 'a.jabatan',
                'aa.status', 'aa.status_asesmen',
            ])
            ->map(fn ($p) => [
                'no_pendaftaran' => $p->no_pendaftaran,
                'nama' => $p->nama,
                'jenis_kelamin' => $p->jenis_kelamin,
                'email' => $p->email,
                'nohp' => $p->nohp,
                'nama_kantor' => $p->nama_kantor,
                'jabatan' => $p->jabatan,
                'status' => $p->status,
                'status_asesmen' => $p->status_asesmen,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id_jadwal' => (int) $idJadwal,
                'tim_asesor' => $tim,
                'jumlah_peserta' => $peserta->count(),
                'peserta' => $peserta,
            ],
        ]);
    }

    /**
     * Dropdown aksi kondisional (16 item native diringkas jadi struktur data).
     * Muncul HANYA jika jumlah_peserta > 0 (idem native).
     */
    private function buildAksi($j, int $jumlahPeserta): array
    {
        if ($jumlahPeserta === 0) {
            return [];   // "Belum ada peserta" — tanpa aksi (idem native)
        }

        $idJadwal = $j->id;
        $skemaId = is_numeric($j->id_skemakkni) ? (int) $j->id_skemakkni : null;

        $aksi = [
            ['grup' => 'utama', 'label' => 'Lihat Peserta',
             'url' => "/peserta-asesmen?idj={$idJadwal}"],
            ['grup' => 'utama', 'label' => 'Berita Acara & Daftar Hadir',
             'url' => "/api/peserta/daftarhadir?idj={$idJadwal}", 'tipe' => 'pdf'],
        ];

        // MAPA-1: 5 varian kandidat (input & unduh)
        if ($skemaId) {
            $filledProfiles = \App\Models\SkemaMapa1a::bySkema($skemaId)
                ->pluck('profil_kandidat')
                ->map(fn ($p) => (int) $p)
                ->all();

            for ($kand = 1; $kand <= 5; $kand++) {
                $aksi[] = [
                    'grup' => 'mapa1_input',
                    'label' => "MAPA 1 Kandidat {$kand}",
                    'kandidat' => $kand,
                    'terisi' => in_array($kand, $filledProfiles, true),
                    'url' => "/mapa1a?kand={$kand}&idsk={$skemaId}",
                ];
            }
            for ($kand = 1; $kand <= 5; $kand++) {
                $aksi[] = [
                    'grup' => 'mapa1_unduh',
                    'label' => "Unduh MAPA-01 Kandidat {$kand}",
                    'kandidat' => $kand,
                    'url' => "/form-mapa-01?idsk={$skemaId}&kand={$kand}",
                    'tipe' => 'pdf',
                ];
            }
            $aksi[] = ['grup' => 'mapa2_input', 'label' => 'MAPA 02 (Peta Instrumen)',
                'url' => "/mapa2?idsk={$skemaId}"];
            $aksi[] = ['grup' => 'mapa2_unduh', 'label' => 'Unduh MAPA-02',
                'url' => "/form-mapa-02?idsk={$skemaId}", 'tipe' => 'pdf'];
        }

        $aksi[] = ['grup' => 'ak05_input', 'label' => 'AK-05 Laporan Asesmen',
            'url' => "/form-fr-ak-05?idj={$idJadwal}"];
        $aksi[] = ['grup' => 'ak05_unduh', 'label' => 'Unduh AK-05',
            'url' => "/form-ak-05?idj={$idJadwal}", 'tipe' => 'pdf'];
        $aksi[] = ['grup' => 'ak06_input', 'label' => 'AK-06 Meninjau Asesmen',
            'url' => "/form-fr-ak-06?idj={$idJadwal}"];
        $aksi[] = ['grup' => 'ak06_unduh', 'label' => 'Unduh AK-06',
            'url' => "/form-ak-06?idj={$idJadwal}", 'tipe' => 'pdf'];

        return $aksi;
    }

    /**
     * tgl_indo — format Indonesia (idem native).
     */
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
