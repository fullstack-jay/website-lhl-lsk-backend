<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesi;
use App\Models\AsesiAsesmen;
use App\Models\JadwalAsesmen;
use App\Models\Komite;
use App\Models\KomiteKeputusan;
use App\Models\PenilaianAsesi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controller untuk Daftar Hasil Asesmen & Rekomendasi Komite Teknis
 * Sesuai alur: Hasil penilaian penguji masuk ke akun komite teknis terlebih dahulu
 * untuk direview teknis dan ditetapkan sebelum diterbitkan ke peserta.
 * Terhubung langsung dengan database riil (asesi_asesmen, penilaian_asesi, komite_keputusan).
 */
class KomiteHasilAsesmenController extends Controller
{
    /**
     * GET /api/v1/komite-teknis/jadwal
     * Mengambil daftar jadwal asesmen untuk halaman Komite Teknis
     */
    /**
     * Resolve authenticated komite model from request token / session
     */
    private function resolveKomite(Request $request): ?Komite
    {
        $user = $request->user('sanctum') ?? $request->user();
        if (!$user) {
            return null;
        }

        return Komite::where(function ($q) use ($user) {
            $q->where('no_ktp', $user->username)
                ->orWhere('no_hp', $user->username)
                ->orWhere('no_induk', $user->username)
                ->orWhere('email', $user->username);
            if (!empty($user->no_ktp) && $user->no_ktp !== $user->username) {
                $q->orWhere('no_ktp', $user->no_ktp);
            }
        })->first();
    }

    public function jadwal(Request $request): JsonResponse
    {
        $user = $request->user('sanctum') ?? $request->user();
        $komite = $this->resolveKomite($request);

        $query = DB::table('jadwal_asesmen')
            ->leftJoin('skema_kkni', 'jadwal_asesmen.id_skemakkni', '=', 'skema_kkni.id')
            ->select([
                'jadwal_asesmen.id',
                'jadwal_asesmen.nama_kegiatan',
                'jadwal_asesmen.tahun',
                'jadwal_asesmen.tgl_asesmen',
                'jadwal_asesmen.tgl_asesmen_akhir',
                'jadwal_asesmen.jam_asesmen',
                'jadwal_asesmen.tempat_asesmen',
                'jadwal_asesmen.kapasitas',
                'jadwal_asesmen.gelombang',
                'jadwal_asesmen.status',
                'skema_kkni.judul as judul_skema',
                'skema_kkni.kode_skema',
            ]);

        // Isolasi jadwal: Hanya jadwal yang ditugaskan ke personil komite teknis bersangkutan
        if ($komite) {
            $assignedJadwalIds = DB::table('jadwal_komite')
                ->where('id_komite', $komite->id)
                ->pluck('id_jadwal')
                ->toArray();

            $query->whereIn('jadwal_asesmen.id', $assignedJadwalIds);
        } elseif ($user && ($user->level === 'admin' || (method_exists($user, 'isAdmin') && $user->isAdmin()))) {
            // Admin dapat melihat seluruh jadwal
        } else {
            // Komite yang tidak terdaftar / belum ditugaskan tidak melihat jadwal
            $query->whereRaw('1 = 0');
        }

        $jadwalList = $query->orderBy('jadwal_asesmen.id', 'desc')->get();

        $data = [];
        foreach ($jadwalList as $j) {
            $asesiCount = DB::table('asesi_asesmen')->where('id_jadwal', $j->id)->count();

            // Asesor ditugaskan
            $asesorNames = DB::table('jadwal_asesor')
                ->join('asesor', 'jadwal_asesor.id_asesor', '=', 'asesor.id')
                ->where('jadwal_asesor.id_jadwal', $j->id)
                ->pluck('asesor.nama')
                ->toArray();

            if (empty($asesorNames)) {
                $asesorNames = ['Penguji LSK'];
            }

            $tglStr = $j->tgl_asesmen ? date('d F Y', strtotime($j->tgl_asesmen)) : date('d F Y');

            // Format status: Selesai / Sedang Berlangsung / Terkonfirmasi / Draft
            $rawStatus = $j->status ?? 'Draft';
            $status = $rawStatus;
            if ($rawStatus === 'Berlangsung') {
                $status = 'Sedang Berlangsung';
            }

            $data[] = [
                'id' => (int) $j->id,
                'tahun' => $j->tahun ? (int) $j->tahun : ($j->tgl_asesmen ? (int) date('Y', strtotime($j->tgl_asesmen)) : 2026),
                'kode_skema' => $j->kode_skema ?? 'SKK.01.001',
                'judul_skema' => $j->judul_skema ?? $j->nama_kegiatan ?? 'Sertifikasi Kompetensi',
                'gelombang' => $j->gelombang ? "Gelombang {$j->gelombang}" : ($j->nama_kegiatan ?? 'Batch Asesmen'),
                'tgl_asesmen' => $tglStr,
                'tgl_asesmen_raw' => $j->tgl_asesmen,
                'tgl_asesmen_akhir_raw' => $j->tgl_asesmen_akhir,
                'jam_asesmen' => $j->jam_asesmen ?? '09:00 WIB',
                'tuk_nama' => $j->tempat_asesmen ?? 'TUK LSK Lingkungan Hidup',
                'tuk_alamat' => 'LSK Lingkungan Hidup',
                'max_asesi' => $j->kapasitas ?: 15,
                'jumlah_asesi' => $asesiCount ?: 1,
                'asesor' => $asesorNames,
                'peran_komite' => $komite ? (DB::table('jadwal_komite')
                    ->where('id_jadwal', $j->id)
                    ->where('id_komite', $komite->id)
                    ->value('peran') ?? 'Anggota') : 'Anggota',
                'status' => $status,
                'raw_status' => $rawStatus,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * GET /api/v1/komite-teknis/hasil-asesmen
     * Mengambil daftar hasil asesmen yang perlu direview oleh Komite Teknis
     * Berdasarkan database riil tabel asesi_asesmen & penilaian_asesi
     */
    public function index(Request $request): JsonResponse
    {
        $tab = $request->query('tab', 'semua');
        $search = $request->query('search', '');
        $skemaFilter = $request->query('skema', '');
        $jadwalIdFilter = $request->query('id_jadwal', null);

        $user = $request->user('sanctum') ?? $request->user();
        $komite = $this->resolveKomite($request);

        // Sumber kebenaran utama peserta jadwal adalah tabel asesi_asesmen
        $query = DB::table('asesi_asesmen');

        // Isolasi peserta: Hanya dari jadwal yang ditugaskan ke komite ini
        if ($komite) {
            $assignedJadwalIds = DB::table('jadwal_komite')
                ->where('id_komite', $komite->id)
                ->pluck('id_jadwal')
                ->toArray();

            $query->whereIn('asesi_asesmen.id_jadwal', $assignedJadwalIds);
        } elseif ($user && ($user->level === 'admin' || (method_exists($user, 'isAdmin') && $user->isAdmin()))) {
            // Admin can view all
        } else {
            $query->whereRaw('1 = 0');
        }

        $query
            ->leftJoin('asesi', function ($join) {
                $join->on('asesi_asesmen.id_asesi', '=', 'asesi.no_pendaftaran')
                    ->orWhere('asesi_asesmen.id_asesi', '=', 'asesi.id')
                    ->orWhere(DB::raw("REPLACE(REPLACE(asesi_asesmen.id_asesi, 'REG-', ''), '-', '')"), '=', DB::raw("REPLACE(REPLACE(asesi.no_pendaftaran, 'REG-', ''), '-', '')"));
            })
            ->leftJoin('jadwal_asesmen', 'asesi_asesmen.id_jadwal', '=', 'jadwal_asesmen.id')
            ->leftJoin('skema_kkni', 'jadwal_asesmen.id_skemakkni', '=', 'skema_kkni.id')
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
            ->leftJoin('asesor', function ($join) {
                $join->on('penilaian_asesi.id_asesor', '=', 'asesor.id')
                    ->orWhere('asesi_asesmen.id_asesor', '=', 'asesor.id');
            })
            ->leftJoin('komite_keputusan', function ($join) {
                $join->on('asesi_asesmen.id_jadwal', '=', 'komite_keputusan.id_jadwal')
                    ->where(function ($q) {
                        $q->on('asesi_asesmen.id_asesi', '=', 'komite_keputusan.id_asesi')
                            ->orOn('asesi_asesmen.id', '=', 'komite_keputusan.id_asesi')
                            ->orWhere(DB::raw("REPLACE(REPLACE(asesi_asesmen.id_asesi, 'REG-', ''), '-', '')"), '=', DB::raw("REPLACE(REPLACE(komite_keputusan.id_asesi, 'REG-', ''), '-', '')"));
                    });
            })
            ->select([
                'asesi_asesmen.id as id_asesmen',
                'asesi_asesmen.id_jadwal',
                'asesi_asesmen.id_asesi as reg_asesi',
                'asesi_asesmen.status_asesmen as status_asesi_asesmen',
                'asesi_asesmen.catatan_asesmen',
                'asesi.nama as nama_peserta',
                'asesi.no_pendaftaran as asesi_no_pendaftaran',
                'jadwal_asesmen.tgl_asesmen as jadwal_tgl_asesmen',
                'jadwal_asesmen.ttd_ketua_lsk',
                'jadwal_asesmen.tgl_ttd_ketua_lsk',
                'skema_kkni.judul as nama_skema',
                'skema_kkni.kode_skema',
                'penilaian_asesi.id as id_penilaian',
                'penilaian_asesi.nilai_vp',
                'penilaian_asesi.nilai_pt',
                'penilaian_asesi.nilai_dpsk',
                'penilaian_asesi.nilai_pw',
                'penilaian_asesi.skor_vp',
                'penilaian_asesi.skor_pt',
                'penilaian_asesi.skor_dpsk',
                'penilaian_asesi.skor_pw',
                'penilaian_asesi.total_skor',
                'penilaian_asesi.rekomendasi as rekomendasi_asesor',
                'penilaian_asesi.tgl_penilaian',
                'penilaian_asesi.catatan as catatan_asesor',
                'penilaian_asesi.rubrik_detail',
                'asesor.nama as nama_asesor',
                'asesor.gelar_depan',
                'asesor.gelar_blk',
                'komite_keputusan.id as id_keputusan',
                'komite_keputusan.keputusan as keputusan_komite',
                'komite_keputusan.catatan as catatan_komite',
                'komite_keputusan.waktu as tgl_keputusan',
                'asesi.sertifikat_kompetensi_lain',
                'asesi.sertifikat_atpa_ktpa',
                'asesi.transkrip',
                'asesi.verifikasi_dokumen',
                'asesi.tgl_daftar',
                'asesi.ijazah',
                'asesi.sertifikat_amdal',
                'asesi.sertifikat',
                'asesi.bukti_keterlibatan',
                'asesi.suket',
                'asesi.dokumen_amdal',
                'asesi.cv',
                'asesi.foto',
                'asesi.ktp',
                'asesi.form_pendaftaran',
                'asesi.no_ktp',
                'asesi.pendidikan',
                'asesi.prodi',
                'asesi.nohp',
                'asesi.email',
                'asesi.tmp_lahir',
                'asesi.tgl_lahir',
                'asesi.jenis_kelamin',
                'asesi.alamat',
                'asesi.RT',
                'asesi.RW',
                'asesi.kelurahan',
                'asesi.kecamatan',
                'asesi.kota',
                'asesi.propinsi',
                'asesi.kodepos',
            ])
            ->orderBy('asesi_asesmen.id', 'desc');

        if ($jadwalIdFilter) {
            $query->where('asesi_asesmen.id_jadwal', $jadwalIdFilter);
        }

        // Hanya peserta yang SUDAH dinilai penguji (punya baris penilaian_asesi)
        // — hasil penilaian penguji-lah yang direview Komite Teknis.
        // Peserta yang belum dinilai tidak muncul (bukan nilai dummy).
        $query->whereNotNull('penilaian_asesi.id');
        $query->whereNotNull('penilaian_asesi.total_skor');

        $dbItems = $query->get();

        // Asesor yang ditugaskan per jadwal untuk fallback jika penilaian belum menentukan asesor spesifik
        $jadwalAsesors = DB::table('jadwal_asesor')
            ->join('asesor', 'jadwal_asesor.id_asesor', '=', 'asesor.id')
            ->select([
                'jadwal_asesor.id_jadwal',
                'asesor.id as id_asesor',
                'asesor.nama',
                'asesor.gelar_depan',
                'asesor.gelar_blk',
            ])
            ->get()
            ->groupBy('id_jadwal');

        $formattedDb = [];
        $uniqueKeys = [];

        foreach ($dbItems as $idx => $item) {
            // Deduplikasi agar per asesi hanya 1 baris
            $dedupKey = $item->id_jadwal.'_'.($item->reg_asesi ?: $item->id_asesmen);
            if (isset($uniqueKeys[$dedupKey])) {
                continue;
            }
            $uniqueKeys[$dedupKey] = true;

            $statusKey = 'menunggu';
            $statusLabel = 'Menunggu Review';
            $rekomendasiLabel = null;

            if ($item->keputusan_komite === 'K' || $item->status_asesi_asesmen === 'K') {
                $statusKey = 'disetujui';
                $statusLabel = 'Sudah Direview';
                $rekomendasiLabel = 'Direkomendasikan Kompeten';
            } elseif ($item->keputusan_komite === 'TL' || $item->status_asesi_asesmen === 'TL') {
                $statusKey = 'perbaikan';
                $statusLabel = 'Perlu Perbaikan';
                $rekomendasiLabel = 'Perlu Perbaikan / Uji Ulang';
            } elseif ($item->keputusan_komite === 'BK') {
                $statusKey = 'tidak_direkomendasikan';
                $statusLabel = 'Tidak Direkomendasikan';
                $rekomendasiLabel = 'Tidak Direkomendasikan';
            } elseif ($item->status_asesi_asesmen === 'BK' && $item->keputusan_komite === null) {
                // Asesor memberi rekomendasi BK namun Komite Teknis belum memutuskan
                $statusKey = 'menunggu';
                $statusLabel = 'Menunggu Review';
                $rekomendasiLabel = null;
            }

            // Asesor Name
            $asesorName = '';
            if ($item->nama_asesor) {
                $asesorName = trim(($item->gelar_depan ? $item->gelar_depan.' ' : '').$item->nama_asesor.($item->gelar_blk ? ', '.$item->gelar_blk : ''));
            } elseif (isset($jadwalAsesors[$item->id_jadwal]) && count($jadwalAsesors[$item->id_jadwal]) > 0) {
                $team = $jadwalAsesors[$item->id_jadwal];
                // Pilih asesor dari tim jadwal (jika ada lebih dari 1 asesor, asesi kedua dapat asesor kedua)
                $asObj = count($team) > 1 ? $team[1] : $team[0];
                $asesorName = trim(($asObj->gelar_depan ? $asObj->gelar_depan.' ' : '').$asObj->nama.($asObj->gelar_blk ? ', '.$asObj->gelar_blk : ''));
            } else {
                $asesorName = 'Penguji LSK';
            }

            // Tanggal Asesmen
            $tglStr = $item->tgl_penilaian
                ? date('d M Y', strtotime($item->tgl_penilaian))
                : ($item->jadwal_tgl_asesmen ? date('d M Y', strtotime($item->jadwal_tgl_asesmen)) : date('d M Y'));

            // Format No Registrasi
            $regNo = $item->reg_asesi ?: $item->asesi_no_pendaftaran;
            if ($regNo && ! str_starts_with($regNo, 'REG-')) {
                if (strlen($regNo) === 11 && ctype_digit($regNo)) {
                    $regNo = 'REG-'.substr($regNo, 0, 8).'-'.str_pad(substr($regNo, 8), 4, '0', STR_PAD_LEFT);
                } else {
                    $regNo = 'REG-'.$regNo;
                }
            }

            // 1. Bukti Persyaratan Pokok (Wajib)
            $dokPokok = [
                [
                    'id' => 'ijazah',
                    'persyaratan' => 'Scan Ijazah (Minimal S1/D4)',
                    'file_name' => $item->ijazah ? basename($item->ijazah) : null,
                    'file_url' => $item->ijazah ? asset('storage/foto_asesi/' . basename($item->ijazah)) : null,
                    'status' => $item->ijazah ? 'Terverifikasi' : 'Belum Ada',
                ],
                [
                    'id' => 'sertifikat_amdal',
                    'persyaratan' => 'Sertifikat Pelatihan AMDAL',
                    'file_name' => ($item->sertifikat_amdal ?: $item->sertifikat) ? basename($item->sertifikat_amdal ?: $item->sertifikat) : null,
                    'file_url' => ($item->sertifikat_amdal ?: $item->sertifikat) ? asset('storage/foto_asesi/' . basename($item->sertifikat_amdal ?: $item->sertifikat)) : null,
                    'status' => ($item->sertifikat_amdal ?: $item->sertifikat) ? 'Terverifikasi' : 'Belum Ada',
                ],
                [
                    'id' => 'bukti_keterlibatan',
                    'persyaratan' => 'Bukti Keterlibatan AMDAL',
                    'file_name' => ($item->bukti_keterlibatan ?: $item->suket) ? basename($item->bukti_keterlibatan ?: $item->suket) : null,
                    'file_url' => ($item->bukti_keterlibatan ?: $item->suket) ? asset('storage/foto_asesi/' . basename($item->bukti_keterlibatan ?: $item->suket)) : null,
                    'status' => ($item->bukti_keterlibatan ?: $item->suket) ? 'Terverifikasi' : 'Belum Ada',
                ],
                [
                    'id' => 'dokumen_amdal',
                    'persyaratan' => 'Salinan Dokumen AMDAL',
                    'file_name' => $item->dokumen_amdal ? basename($item->dokumen_amdal) : null,
                    'file_url' => $item->dokumen_amdal ? asset('storage/foto_asesi/' . basename($item->dokumen_amdal)) : null,
                    'status' => $item->dokumen_amdal ? 'Terverifikasi' : 'Belum Ada',
                ],
            ];

            // 2. Bukti Persyaratan Tambahan (Opsional)
            $dokTambahan = [
                [
                    'id' => 'cv',
                    'persyaratan' => 'Curriculum Vitae (CV)',
                    'file_name' => $item->cv ? basename($item->cv) : null,
                    'file_url' => $item->cv ? asset('storage/foto_asesi/' . basename($item->cv)) : null,
                    'status' => $item->cv ? 'Terverifikasi' : 'Belum Ada',
                ],
                [
                    'id' => 'foto',
                    'persyaratan' => 'Pas Foto (3×4)',
                    'file_name' => $item->foto ? basename($item->foto) : null,
                    'file_url' => $item->foto ? asset('storage/foto_asesi/' . basename($item->foto)) : null,
                    'status' => $item->foto ? 'Terverifikasi' : 'Belum Ada',
                ],
                [
                    'id' => 'ktp',
                    'persyaratan' => 'Scan KTP',
                    'file_name' => $item->ktp ? basename($item->ktp) : null,
                    'file_url' => $item->ktp ? asset('storage/foto_asesi/' . basename($item->ktp)) : null,
                    'status' => $item->ktp ? 'Terverifikasi' : 'Belum Ada',
                ],
                [
                    'id' => 'form_pendaftaran',
                    'persyaratan' => 'Formulir Pendaftaran',
                    'file_name' => $item->form_pendaftaran ? basename($item->form_pendaftaran) : null,
                    'file_url' => $item->form_pendaftaran ? asset('storage/foto_asesi/' . basename($item->form_pendaftaran)) : null,
                    'status' => $item->form_pendaftaran ? 'Terverifikasi' : 'Belum Ada',
                ],
            ];

            // Dokumen Portofolio (Persyaratan Portofolio)
            $portofolio = [];
            if (!empty($item->sertifikat_kompetensi_lain) || !empty($item->transkrip)) {
                $file = $item->sertifikat_kompetensi_lain ?: $item->transkrip;
                $portofolio[] = [
                    'id' => 1,
                    'persyaratan' => 'Sertifikat Pelatihan Relevan dengan AMDAL',
                    'file_name' => basename($file),
                    'file_url' => asset('storage/foto_asesi/' . basename($file)),
                    'no_dokumen' => $regNo,
                    'tgl_dokumen' => $item->tgl_daftar ? date('d/m/Y', strtotime($item->tgl_daftar)) : '04/09/2026',
                    'status' => 'Terverifikasi',
                ];
            }
            if (!empty($item->sertifikat_atpa_ktpa)) {
                $file = $item->sertifikat_atpa_ktpa;
                $portofolio[] = [
                    'id' => 2,
                    'persyaratan' => 'Sertifikat Kompetensi ATPA/KTPA',
                    'file_name' => basename($file),
                    'file_url' => asset('storage/foto_asesi/' . basename($file)),
                    'no_dokumen' => $regNo,
                    'tgl_dokumen' => $item->tgl_daftar ? date('d/m/Y', strtotime($item->tgl_daftar)) : '04/09/2026',
                    'status' => 'Terverifikasi',
                ];
            }

            // Tanda tangan & rekomendasi FR-APL-01
            $verifDok = is_array($item->verifikasi_dokumen)
                ? $item->verifikasi_dokumen
                : (is_string($item->verifikasi_dokumen) ? (json_decode($item->verifikasi_dokumen, true) ?: []) : []);

            $ttdLog = DB::table('logdigisign')
                ->where(function ($q) use ($regNo, $item) {
                    $cleanNo = preg_replace('/[^0-9]/', '', (string) $regNo);
                    $q->where('file', 'like', 'ttd_' . $regNo . '_%')
                      ->orWhere('penandatangan', $item->nama_peserta)
                      ->orWhere('url_ditandatangani', 'like', '%' . $regNo . '%');
                    if ($cleanNo) {
                        $q->orWhere('file', 'like', 'ttd_' . $cleanNo . '_%')
                          ->orWhere('url_ditandatangani', 'like', '%' . $cleanNo . '%');
                    }
                })
                ->orderBy('id', 'desc')
                ->first();

            $ttdPemohonUrl = null;
            $ttdPemohonWaktu = null;
            if ($ttdLog) {
                $ttdPemohonWaktu = $ttdLog->waktu ? date('d F Y, H:i:s \W\I\B', strtotime($ttdLog->waktu)) : null;
                if (!empty($ttdLog->file)) {
                    $ttdPemohonUrl = asset('storage/foto_tandatangan/' . basename($ttdLog->file));
                } elseif (!empty($ttdLog->url_ditandatangani)) {
                    $ttdPemohonUrl = $ttdLog->url_ditandatangani;
                }
            }

            $ttdAdmin = $verifDok['ttd_admin'] ?? null;
            $adminNama = $verifDok['nama_admin'] ?? 'Administrator';
            $adminWaktu = !empty($verifDok['tgl_persetujuan_admin'])
                ? date('d F Y, H:i:s \W\I\B', strtotime($verifDok['tgl_persetujuan_admin']))
                : '05 September 2026, 11:12:32 WIB';

            $rubrik = is_string($item->rubrik_detail)
                ? (json_decode($item->rubrik_detail, true) ?: [])
                : (is_array($item->rubrik_detail) ? $item->rubrik_detail : []);

            $linkWawancara = $rubrik['link_uji_wawancara'] ?? 'https://drive.google.com/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74W/view?usp=sharing';
            $linkTertulis = $rubrik['link_uji_tertulis'] ?? 'https://drive.google.com/file/d/1CxqNWt1YSA6oGNdLwCdBZkhmUVrqumct85X/view?usp=sharing';

            $tglTtdKetua = !empty($item->tgl_ttd_ketua_lsk)
                ? date('d F Y, H:i:s \W\I\B', strtotime($item->tgl_ttd_ketua_lsk))
                : '10 September 2026, 11:29:35 WIB';

            // Nilai: skor riil dari penilaian penguji (tanpa fallback dummy)
            $nilai = $item->total_skor !== null ? (int) round($item->total_skor) : null;

            $formattedDb[] = [
                'id' => $item->id_penilaian ? (int) $item->id_penilaian : (int) $item->id_asesmen,
                'id_jadwal' => (int) $item->id_jadwal,
                'id_asesmen' => (int) $item->id_asesmen,
                'no_registrasi' => $regNo ?: 'REG-20260902-0001',
                'nama_peserta' => $item->nama_peserta ?: 'Peserta Uji',
                'no_ktp' => $item->no_ktp,
                'pendidikan' => $item->pendidikan,
                'pendidikan_nama' => $item->pendidikan,
                'prodi' => $item->prodi,
                'nohp' => $item->nohp,
                'email' => $item->email,
                'tmp_lahir' => $item->tmp_lahir,
                'tgl_lahir' => $item->tgl_lahir ? date('Y-m-d', strtotime($item->tgl_lahir)) : null,
                'jenis_kelamin' => $item->jenis_kelamin,
                'skema_sertifikasi' => $item->nama_skema ?: 'Sertifikasi Kompetensi',
                'kode_skema' => $item->kode_skema ?: 'SKM/1983/00013/2/2021/1',
                'asesor' => $asesorName,
                'tanggal_asesmen' => $tglStr,
                'nilai' => $nilai,
                'status_review' => $statusLabel,
                'status_key' => $statusKey,
                'rekomendasi_komite' => $rekomendasiLabel,
                'catatan_justifikasi' => $item->catatan_komite ?: ($item->catatan_asesor ?: ''),
                'verifikasi_administrasi' => 'Memenuhi Syarat (MS)',
                'tinjauan_bukti' => 'Memenuhi',
                'review_proses' => 'Sesuai',
                'dokumen_pokok' => $dokPokok,
                'dokumen_tambahan' => $dokTambahan,
                'portofolio_dokumen' => $portofolio,
                'ttd_pemohon' => $ttdPemohonUrl,
                'ttd_pemohon_waktu' => $ttdPemohonWaktu ?: '04 September 2026, 13:10:05 WIB',
                'ttd_admin' => $ttdAdmin,
                'ttd_admin_nama' => $adminNama,
                'ttd_admin_waktu' => $adminWaktu,
                'ttd_admin_noreg' => 'ADM001',
                'status_rekomendasi_lsk' => 'Telah Disetujui Sebagai Peserta Sertifikasi',
                'rekap_nilai_ukom' => [
                    'nilai_vp' => $item->nilai_vp !== null ? (float) $item->nilai_vp : 80.0,
                    'skor_vp' => $item->skor_vp !== null ? (float) $item->skor_vp : 16.0,
                    'bobot_vp' => '20%',
                    'nilai_pt' => $item->nilai_pt !== null ? (float) $item->nilai_pt : 35.0,
                    'skor_pt' => $item->skor_pt !== null ? (float) $item->skor_pt : 3.5,
                    'bobot_pt' => '10%',
                    'nilai_dpsk' => $item->nilai_dpsk !== null ? (float) $item->nilai_dpsk : 75.0,
                    'skor_dpsk' => $item->skor_dpsk !== null ? (float) $item->skor_dpsk : 26.25,
                    'bobot_dpsk' => '35%',
                    'nilai_pw' => $item->nilai_pw !== null ? (float) $item->nilai_pw : 60.0,
                    'skor_pw' => $item->skor_pw !== null ? (float) $item->skor_pw : 21.0,
                    'bobot_pw' => '35%',
                    'total_skor' => $item->total_skor !== null ? (float) $item->total_skor : 66.75,
                    'total_nilai' => $nilai,
                    'rekomendasi_asesor' => $item->rekomendasi_asesor ?: 'BK',
                    'catatan_asesor' => $item->catatan_asesor ?: '',
                ],
                'berkas_uji_wawancara' => [
                    'judul' => 'Berkas Uji Wawancara (Ditandatangani)',
                    'deskripsi' => 'Lembar penilaian uji wawancara dan berita acara yang telah ditandatangani oleh asesi & asesor penguji.',
                    'tipe' => 'google_drive',
                    'link_drive' => $linkWawancara,
                    'status' => 'Tersedia di Google Drive',
                    'diunggah_oleh' => 'Admin LSK',
                    'is_ditandatangani' => true,
                    'tgl_unggah' => '09 September 2026, 14:20 WIB',
                ],
                'berkas_uji_tertulis' => [
                    'judul' => 'Berkas Uji Tertulis',
                    'deskripsi' => 'Lembar jawaban dan dokumen pelaksanaan ujian tertulis asesi yang telah discan dan diunggah oleh admin.',
                    'tipe' => 'google_drive',
                    'link_drive' => $linkTertulis,
                    'status' => 'Tersedia di Google Drive',
                    'diunggah_oleh' => 'Admin LSK',
                    'is_scanned' => true,
                    'tgl_unggah' => '09 September 2026, 14:15 WIB',
                ],
                'ketua_lsk' => [
                    'nama' => 'Rusdani Sosiawan, S.Pi., M.Ling',
                    'jabatan' => 'Ketua Amdal LSK - Lingkungan Hidup Lestari',
                    'ttd' => $item->ttd_ketua_lsk,
                    'tgl_ttd' => $tglTtdKetua,
                    'status' => !empty($item->ttd_ketua_lsk) ? 'Disahkan Ketua LSK' : 'Belum Ditandatangani',
                ],
            ];
        }

        // Filter berdasarkan tab, search, dan skema
        $filtered = array_values(array_filter($formattedDb, function ($item) use ($tab, $search, $skemaFilter) {
            if ($tab === 'menunggu' && $item['status_key'] !== 'menunggu') {
                return false;
            }
            if ($tab === 'disetujui' && $item['status_key'] !== 'disetujui') {
                return false;
            }
            if ($tab === 'perbaikan' && $item['status_key'] !== 'perbaikan') {
                return false;
            }
            if ($tab === 'tidak_direkomendasikan' && $item['status_key'] !== 'tidak_direkomendasikan') {
                return false;
            }

            if (! empty($search)) {
                $s = strtolower($search);
                $match = str_contains(strtolower($item['nama_peserta']), $s) ||
                         str_contains(strtolower($item['no_registrasi']), $s) ||
                         str_contains(strtolower($item['skema_sertifikasi']), $s) ||
                         str_contains(strtolower($item['kode_skema']), $s);
                if (! $match) {
                    return false;
                }
            }

            if (! empty($skemaFilter) && $skemaFilter !== 'Semua Skema') {
                if (strtolower($item['skema_sertifikasi']) !== strtolower($skemaFilter)) {
                    return false;
                }
            }

            return true;
        }));

        $counts = [
            'semua' => count($formattedDb),
            'menunggu' => count(array_filter($formattedDb, fn ($x) => $x['status_key'] === 'menunggu')),
            'disetujui' => count(array_filter($formattedDb, fn ($x) => $x['status_key'] === 'disetujui')),
            'perbaikan' => count(array_filter($formattedDb, fn ($x) => $x['status_key'] === 'perbaikan')),
            'tidak_direkomendasikan' => count(array_filter($formattedDb, fn ($x) => $x['status_key'] === 'tidak_direkomendasikan')),
        ];

        return response()->json([
            'success' => true,
            'counts' => $counts,
            'data' => $filtered,
        ]);
    }

    /**
     * POST /api/v1/komite-teknis/hasil-asesmen/{id}/rekomendasi
     * Menyimpan rekomendasi hasil review teknis dari Komite Teknis
     */
    public function storeRekomendasi(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'rekomendasi' => 'required|string',
            'catatan' => 'required|string',
            'verifikasi_administrasi' => 'nullable|string',
            'verifikasi_pendaftaran' => 'nullable|string',
            'verifikasi_berkas_uji' => 'nullable|string',
            'verifikasi_ukom' => 'nullable|string',
            'tinjauan_bukti' => 'nullable|string',
            'review_proses' => 'nullable|string',
        ]);

        $user = $request->user('sanctum') ?? $request->user();
        $komite = null;
        if ($user) {
            $komite = Komite::where('no_ktp', $user->username)
                ->orWhere('no_ktp', $user->no_ktp)
                ->first();
        }

        if (! $komite) {
            $komite = Komite::where('aktif', 'Y')->orderBy('id', 'asc')->first();
        }

        $idKomite = $komite ? $komite->id : 12;

        $keputusan = 'K';
        $statusLabel = 'Disetujui';
        $statusKey = 'disetujui';

        if (str_contains($validated['rekomendasi'], 'Perbaikan') || $validated['rekomendasi'] === 'TL') {
            $keputusan = 'TL';
            $statusLabel = 'Perbaikan';
            $statusKey = 'perbaikan';
        } elseif (str_contains($validated['rekomendasi'], 'Ditolak') || str_contains($validated['rekomendasi'], 'Tidak Direkomendasikan') || $validated['rekomendasi'] === 'BK') {
            $keputusan = 'BK';
            $statusLabel = 'Ditolak';
            $statusKey = 'tidak_direkomendasikan';
        }

        // Resolusi ID ke asesi_asesmen atau penilaian_asesi
        $penilaian = PenilaianAsesi::find($id);
        $asesiAsesmen = null;

        if ($penilaian) {
            $asesiAsesmen = AsesiAsesmen::find($penilaian->id_asesmen);
        } else {
            // Cek apakah $id merupakan ID dari asesi_asesmen
            $asesiAsesmen = AsesiAsesmen::find($id);
            if ($asesiAsesmen) {
                $penilaian = PenilaianAsesi::where('id_asesmen', $asesiAsesmen->id)
                    ->orWhere(function ($q) use ($asesiAsesmen) {
                        $q->where('id_jadwal', $asesiAsesmen->id_jadwal)
                            ->where('no_pendaftaran', $asesiAsesmen->id_asesi);
                    })
                    ->first();
            } else {
                // Cek berdasarkan no_pendaftaran
                $cleanNo = preg_replace('/[^0-9]/', '', (string) $id);
                $asesiAsesmen = AsesiAsesmen::where('id_asesi', (string) $id)
                    ->orWhere('id_asesi', $cleanNo)
                    ->orWhere(DB::raw("REPLACE(REPLACE(id_asesi, 'REG-', ''), '-', '')"), $cleanNo)
                    ->first();
            }
        }

        $idJadwal = $asesiAsesmen ? $asesiAsesmen->id_jadwal : ($penilaian ? $penilaian->id_jadwal : 13);
        $noPendaftaran = $asesiAsesmen ? $asesiAsesmen->id_asesi : ($penilaian ? $penilaian->no_pendaftaran : (string) $id);

        DB::beginTransaction();
        try {
            // 1. Simpan Rekomendasi/Keputusan ke komite_keputusan
            KomiteKeputusan::updateOrCreate(
                [
                    'id_jadwal' => (string) $idJadwal,
                    'id_asesi' => (string) $noPendaftaran,
                ],
                [
                    'id_skemakkni' => JadwalAsesmen::where('id', $idJadwal)->value('id_skemakkni') ?? '14',
                    'id_komite' => (string) $idKomite,
                    'keputusan' => $keputusan,
                    'catatan' => $validated['catatan'],
                    'waktu' => now(),
                ]
            );

            // 2. Perbarui status_asesmen pada asesi_asesmen
            if ($asesiAsesmen) {
                $asesiAsesmen->update([
                    'status_asesmen' => $keputusan,
                    'catatan_asesmen' => $validated['catatan'],
                ]);
            } else {
                AsesiAsesmen::where('id_jadwal', $idJadwal)
                    ->where(function ($q) use ($noPendaftaran) {
                        $clean = preg_replace('/[^0-9]/', '', $noPendaftaran);
                        $q->where('id_asesi', $noPendaftaran)
                            ->orWhere('id_asesi', $clean)
                            ->orWhere(DB::raw("REPLACE(REPLACE(id_asesi, 'REG-', ''), '-', '')"), $clean);
                    })
                    ->update([
                        'status_asesmen' => $keputusan,
                        'catatan_asesmen' => $validated['catatan'],
                    ]);
            }

            // 3. Perbarui verifikasi dokumen pada tabel asesi jika disetujui (K)
            if ($keputusan === 'K') {
                $cleanNo = preg_replace('/[^0-9]/', '', (string) $noPendaftaran);
                $asesiObj = Asesi::where('no_pendaftaran', $noPendaftaran)
                    ->orWhere('id', (string) $noPendaftaran)
                    ->orWhere(DB::raw("REPLACE(REPLACE(no_pendaftaran, 'REG-', ''), '-', '')"), $cleanNo)
                    ->first();

                if ($asesiObj) {
                    $currVerif = is_array($asesiObj->verifikasi_dokumen)
                        ? $asesiObj->verifikasi_dokumen
                        : (is_string($asesiObj->verifikasi_dokumen) ? (json_decode($asesiObj->verifikasi_dokumen, true) ?: []) : []);

                    $currVerif['ijazah'] = 'terverifikasi';
                    $currVerif['sertifikat_amdal'] = 'terverifikasi';
                    $currVerif['bukti_keterlibatan'] = 'terverifikasi';
                    $currVerif['dokumen_amdal'] = 'terverifikasi';

                    $asesiObj->update([
                        'verifikasi_dokumen' => $currVerif,
                        'verifikasi' => 'V',
                    ]);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan keputusan: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Rekomendasi Komite Teknis berhasil disimpan.',
            'data' => [
                'id' => is_numeric($id) ? (int) $id : $id,
                'status_review' => $statusLabel,
                'status_key' => $statusKey,
                'rekomendasi_komite' => $validated['rekomendasi'],
                'catatan_justifikasi' => $validated['catatan'],
                'waktu' => now()->format('Y-m-d H:i:s'),
            ],
        ]);
    }
}
