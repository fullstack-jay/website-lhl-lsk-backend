<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesi;
use App\Models\AsesiAsesmen;
use App\Models\AsesiDoc;
use App\Models\SkemaKkni;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Calon Peserta Baru (Asesi Baru) — implementasi modul `asesibaru` PHP Native versi API.
 * Sesuai docs/BACKEND_CALONPESERTABARU.md:
 *
 * Kriteria modul: peserta pendaftar MANDIRI dengan verifikasi='P' dan blokir='N',
 * ORDER BY tgl_daftar DESC.
 *
 * 4 Tab:
 * 1. belum_skema     : pendaftar baru TANPA baris asesi_asesmen (baru isi profil)
 * 2. terjadwal       : punya pendaftaran skema dengan id_jadwal terisi
 * 3. belum_terjadwal : punya pendaftaran skema dengan id_jadwal NULL
 * 4. rekapitulasi    : COUNT per skema × status
 *
 * Perbaikan atas sistem native: cascade delete dibungkus transaction (anti-orphan),
 * refactor dedup kartu (satu transformer untuk semua tab), guard NULL tanggal lahir,
 * guard URL eksternal saat unlink, @unlink anti-warning.
 */
class AsesiBaruController extends Controller
{
    /** Direktori fisik dokumen asesi & APL-02 (idem native: foto_asesi/, foto_apl02/). */
    private const DIR_ASESI = 'foto_asesi';
    private const DIR_APL02 = 'foto_apl02';

    /**
     * Tabel turunan yang dihapus pada cascade total
     * (padanan 14-tabel native; id_asesi = asesi.no_pendaftaran).
     */
    private const TABEL_TURUNAN_PER_ASESI = [
        'asesi_pembayaran',   // step 3
        'asesi_asesmen',      // step 4
        'asesi_apl02',        // step 5
        'asesmen_ak01',       // step 6
        'asesmen_ak03',       // step 7
        'asesmen_ia03',       // step 8
        'asesmen_ia05',       // step 9
        'asesmen_ia06',       // step 10
        'asesmen_ia08',       // step 11
        'asesmen_ia08asesor', // step 12
        'asesmen_ia09',       // step 13
        'asesmen_ia11',       // step 14
    ];

    /** Kolom file langsung di tabel asesi. */
    private const KOLOM_FILE_ASESI = ['foto', 'ktp', 'kk', 'ijazah', 'transkrip', 'suket', 'cv', 'sertifikat'];

    // ════════════════════════════════════════════════════════════════
    // DAFTAR — 4 TAB
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/asesi-baru?tab=belum_skema|terjadwal|belum_terjadwal|rekapitulasi
     */
    public function index(Request $request): JsonResponse
    {
        $tab = $request->input('tab', 'belum_skema');

        if ($tab === 'rekapitulasi') {
            return $this->rekapitulasi();
        }

        // Filter dasar semua tab (padanan: WHERE verifikasi='P' AND blokir='N')
        $query = Asesi::query()
            ->where('verifikasi', 'P')
            ->where('blokir', 'N')
            ->orderBy('tgl_daftar', 'desc');

        switch ($tab) {
            case 'terjadwal':
                // punya pendaftaran skema dengan id_jadwal terisi
                $query->whereHas('pendaftaran', fn ($q) => $q->whereNotNull('id_jadwal'));
                break;

            case 'belum_terjadwal':
                // punya pendaftaran skema dengan id_jadwal NULL
                $query->whereHas('pendaftaran', fn ($q) => $q->whereNull('id_jadwal'));
                break;

            case 'belum_skema':
            default:
                // Tab 1: TIDAK punya baris asesi_asesmen sama sekali
                $query->whereDoesntHave('pendaftaran');
                break;
        }

        // Search opsional
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Pagination
        $perPage = min((int) ($request->per_page ?? 20), 100);
        $result = $query->paginate($perPage);

        $data = collect($result->items())
            ->map(fn ($asesi) => $this->transformKartu($asesi));

        return response()->json([
            'success' => true,
            'tab' => $tab,
            'data' => $data,
            'pagination' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
                'from' => $result->firstItem(),
                'to' => $result->lastItem(),
            ],
        ]);
    }

    /**
     * TAB 4: Rekapitulasi per skema — padanan Common Query #3 (satu query efisien).
     * Catatan UI: satu peserta bisa ikut banyak skema → total per skema
     * bisa lebih besar dari jumlah peserta unik.
     */
    private function rekapitulasi(): JsonResponse
    {
        $rows = DB::table('asesi_asesmen as m')
            ->join('skema_kkni as sk', 'sk.id', '=', 'm.id_skemakkni')
            ->selectRaw("
                sk.id, sk.kode_skema, sk.judul,
                COUNT(*) AS terdaftar,
                SUM(m.id_jadwal IS NOT NULL) AS terjadwal,
                SUM(m.id_jadwal IS NULL) AS belum_terjadwal
            ")
            ->where('m.status_asesmen', 'P')
            ->whereNull('m.keputusan_asesor')
            ->groupBy('sk.id', 'sk.kode_skema', 'sk.judul')
            ->orderBy('sk.id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'per_skema' => $rows,
                'total' => [
                    'terdaftar' => (int) $rows->sum('terdaftar'),
                    'terjadwal' => (int) $rows->sum('terjadwal'),
                    'belum_terjadwal' => (int) $rows->sum('belum_terjadwal'),
                ],
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // KARTU PESERTA (refactor dedup — satu transformer utk semua tab)
    // ════════════════════════════════════════════════════════════════

    /**
     * Transform satu asesi menjadi payload kartu.
     * Padanan Per-Card Logic native: statistik pendaftaran, deteksi duplikat,
     * label pendidikan/wilayah, dokumen wajib dinamis, detail skema, aksi kontak.
     */
    private function transformKartu(Asesi $asesi): array
    {
        // 1. Statistik pendaftaran: total / A (disetujui) / R (ditolak) / P (menunggu)
        $pendaftaran = AsesiAsesmen::where('id_asesi', $asesi->no_pendaftaran)->get();

        $statistik = [
            'total' => $pendaftaran->count(),
            'disetujui' => $pendaftaran->where('status', 'A')->count(),
            'ditolak' => $pendaftaran->where('status', 'R')->count(),
            'menunggu' => $pendaftaran->where('status', 'P')->count(),
        ];

        // 2. Deteksi duplikat: nama ganda & KTP ganda (tgl_lahir kosong di-skip — fix MySQL 8)
        $dupNama = !empty($asesi->nama)
            ? Asesi::where('nama', $asesi->nama)->count() : 0;
        $dupKtp = !empty($asesi->no_ktp)
            ? Asesi::where('no_ktp', $asesi->no_ktp)->count() : 0;

        $duplikat = [
            'nama' => $dupNama > 1,
            'no_ktp' => $dupKtp > 1,
            'jumlah_nama' => $dupNama,
            'jumlah_ktp' => $dupKtp,
        ];

        // 3. Label pendidikan & wilayah uji (JOIN referensi)
        $pendidikanLabel = $asesi->pendidikan
            ? DB::table('pendidikan')->where('id', $asesi->pendidikan)->value('jenjang_pendidikan')
            : null;
        $wilayahLabel = $asesi->wil_ujikom
            ? DB::table('data_wilayah')->where('id_wil', $asesi->wil_ujikom)->value('nm_wil')
            : null;

        // 4. Dokumen wajib DINAMIS dari asesi_persyaratanpokok (pola shortcode)
        $dokumenWajib = DB::table('asesi_persyaratanpokok')
            ->where('wajib', 'Y')
            ->where('aktif', 'Y')
            ->get()
            ->map(function ($p) use ($asesi) {
                $shortcode = $p->shortcode;   // shortcode = nama kolom aktual tabel asesi
                $file = $asesi->{$shortcode} ?? null;
                return [
                    'persyaratan' => $p->persyaratan,
                    'shortcode' => $shortcode,
                    'file' => $file,
                    'url' => $file ? asset(self::DIR_ASESI . '/' . $file) : null,
                    'ada' => !empty($file),   // badge hijau "Ada" / merah "Belum Ada"
                ];
            });

        // 5. Detail per pendaftaran skema (untuk Tab 2 & 3)
        $detailSkema = $pendaftaran->map(function ($m) {
            $skema = SkemaKkni::find($m->id_skemakkni, ['id', 'kode_skema', 'judul']);
            $jumlahDoc = AsesiDoc::where('id_asesi', $m->id_asesi)
                ->where('id_skemakkni', $m->id_skemakkni)->count();
            $plotPenguji = $m->id_jadwal
                ? DB::table('jadwal_asesor')->where('id_jadwal', $m->id_jadwal)->count()
                : 0;

            return [
                'id_asesmen' => $m->id,
                'id_skemakkni' => $m->id_skemakkni,
                'kode_skema' => $skema?->kode_skema,
                'judul' => $skema?->judul,
                'id_jadwal' => $m->id_jadwal,
                'terjadwal' => $m->id_jadwal !== null,
                'tgl_asesmen' => optional($m->tgl_asesmen)->format('Y-m-d'),
                'jumlah_dokumen' => $jumlahDoc,
                'status_dokumen' => $jumlahDoc === 0
                    ? 'Belum Melengkapi'
                    : "Telah mengunggah {$jumlahDoc} Dokumen",
                'status' => $m->status,                     // P/A/R
                'status_label' => $this->statusLabel($m->status),
                'status_asesmen' => $m->status_asesmen,     // P/K/BK/TL
                'status_asesmen_label' => $this->statusAsesmenLabel($m->status_asesmen),
                'keputusan_asesor' => $m->keputusan_asesor, // R/NR
                'plot_penguji' => $plotPenguji > 0 ? 'Sudah' : 'Belum',
                'no_surattugas' => $m->no_surattugas,
            ];
        });

        return [
            'id' => $asesi->id,
            'no_pendaftaran' => $asesi->no_pendaftaran,
            'nama' => $asesi->nama,
            'no_ktp' => $asesi->no_ktp,
            'email' => $asesi->email,
            'nohp' => $asesi->nohp,
            'tgl_daftar' => optional($asesi->tgl_daftar)->format('Y-m-d'),
            'waktu' => optional($asesi->waktu)->toDateTimeString(),

            'duplikat' => $duplikat,
            'ada_duplikat' => $duplikat['nama'] || $duplikat['no_ktp'],   // badge merah kartu
            'pendidikan_label' => $pendidikanLabel,
            'wilayah_uji' => $wilayahLabel,

            'dokumen_wajib' => $dokumenWajib,

            'statistik' => $statistik,
            'pendaftaran_skema' => $detailSkema,

            // Aksi kontak (padanan handler telepon/whatsapp/sms)
            'kontak' => [
                'telepon' => $asesi->nohp,
                'whatsapp' => $asesi->whatsapp,             // accessor 08xx → 62xx
                'sms_url' => "/api/v1/admin/asesi-baru/{$asesi->id}/sms",
            ],
        ];
    }

    private function statusLabel(?string $s): string
    {
        return match ($s) {
            'A' => 'Disetujui',
            'R' => 'Ditolak',
            default => 'Dalam proses',
        };
    }

    private function statusAsesmenLabel(?string $s): string
    {
        return match ($s) {
            'K' => 'Kompeten',
            'BK' => 'Belum Kompeten',
            'TL' => 'Tindak Lanjut',
            default => 'Proses',
        };
    }

    // ════════════════════════════════════════════════════════════════
    // HANDLER 1-2: BLOKIR / BUKA BLOKIR
    // ════════════════════════════════════════════════════════════════

    /**
     * PUT /api/v1/admin/asesi-baru/{id}/blokir  { blokir: "Y"|"N" }
     * Peserta yang diblokir tidak bisa login (blokir='N' dicek di cek_login).
     */
    public function updateBlokir(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'blokir' => 'required|in:Y,N',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $asesi = Asesi::find($id);
        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        $asesi->blokir = $request->blokir;
        $asesi->save();

        $label = $request->blokir === 'Y' ? 'diblokir' : 'dibuka blokirnya';
        return response()->json([
            'success' => true,
            'message' => "Akun peserta berhasil {$label}",
            'data' => ['id' => (int) $id, 'blokir' => $asesi->blokir],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // HANDLER 3: HAPUS TOTAL (cascade + unlink, transaction-wrapped)
    // ════════════════════════════════════════════════════════════════

    /**
     * DELETE /api/v1/admin/asesi-baru/{id}
     *
     * Padanan handler hapusasesor/hapusasesi native (16 step), dengan perbaikan:
     * - Transaction atomik: gagal di tengah = rollback semua (tidak ada orphan)
     * - Unlink file dilakukan SETELAH commit (DB aman walau file hilang)
     * - Guard URL eksternal (file "http*" tidak di-unlink dari disk)
     * - @unlink + file_exists check (anti PHP warning)
     */
    public function destroy($id): JsonResponse
    {
        DB::beginTransaction();
        try {
            $asesi = Asesi::lockForUpdate()->find($id);
            if (!$asesi) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Peserta tidak ditemukan',
                ], 404);
            }

            $noPendaftaran = $asesi->no_pendaftaran;
            $filesToDelete = [];

            // Step 2: asesi_doc — kumpulkan file lalu delete rows
            AsesiDoc::where('id_asesi', $noPendaftaran)->get()
                ->each(function ($doc) use (&$filesToDelete) {
                    if (!empty($doc->file) && !str_starts_with((string) $doc->file, 'http')) {
                        $filesToDelete[] = self::DIR_ASESI . '/' . $doc->file;
                    }
                });
            AsesiDoc::where('id_asesi', $noPendaftaran)->delete();

            // Step 3-14: tabel turunan lain
            foreach (self::TABEL_TURUNAN_PER_ASESI as $tabel) {
                DB::table($tabel)->where('id_asesi', $noPendaftaran)->delete();
            }

            // Step 15: asesi_apl02doc + unlink
            DB::table('asesi_apl02doc')->where('id_asesi', $noPendaftaran)->get()
                ->each(function ($row) use (&$filesToDelete) {
                    if (!empty($row->file) && !str_starts_with((string) $row->file, 'http')) {
                        $filesToDelete[] = self::DIR_APL02 . '/' . $row->file;
                    }
                });
            DB::table('asesi_apl02doc')->where('id_asesi', $noPendaftaran)->delete();

            // File pada kolom langsung tabel asesi
            foreach (self::KOLOM_FILE_ASESI as $f) {
                if (!empty($asesi->{$f}) && !str_starts_with((string) $asesi->{$f}, 'http')) {
                    $filesToDelete[] = self::DIR_ASESI . '/' . $asesi->{$f};
                }
            }

            // Step 16: record utama asesi TERAKHIR
            $asesi->delete();

            DB::commit();

            // Unlink file fisik SETELAH commit sukses
            $jumlahFile = 0;
            foreach (array_unique($filesToDelete) as $relPath) {
                $abs = public_path($relPath);
                if (file_exists($abs)) {
                    @unlink($abs);
                    $jumlahFile++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Peserta beserta seluruh data terkait berhasil dihapus',
                'data' => ['file_dihapus' => $jumlahFile],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus peserta: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HANDLER 4: HAPUS PENDAFTARAN PER SKEMA
    // ════════════════════════════════════════════════════════════════

    /**
     * DELETE /api/v1/admin/asesi-baru/{id}/pendaftaran/{idSkema}
     *
     * Padanan handler hapusskemaasesi: hapus asesi_doc + pembayaran + asesmen +
     * apl02 (+apl02doc) milik kombinasi (asesi, skema) tertentu.
     * Record asesi TETAP ADA — peserta bisa mendaftar lagi skema lain.
     */
    public function destroyPendaftaran($id, $idSkema): JsonResponse
    {
        DB::beginTransaction();
        try {
            $asesi = Asesi::lockForUpdate()->find($id);
            if (!$asesi) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Peserta tidak ditemukan',
                ], 404);
            }

            $np = $asesi->no_pendaftaran;
            $filesToDelete = [];

            AsesiDoc::where('id_asesi', $np)->where('id_skemakkni', $idSkema)->get()
                ->each(function ($doc) use (&$filesToDelete) {
                    if (!empty($doc->file) && !str_starts_with((string) $doc->file, 'http')) {
                        $filesToDelete[] = self::DIR_ASESI . '/' . $doc->file;
                    }
                });
            AsesiDoc::where('id_asesi', $np)->where('id_skemakkni', $idSkema)->delete();

            DB::table('asesi_pembayaran')->where('id_asesi', $np)->where('id_skemakkni', $idSkema)->delete();
            AsesiAsesmen::where('id_asesi', $np)->where('id_skemakkni', $idSkema)->delete();
            DB::table('asesi_apl02')->where('id_asesi', $np)->where('id_skemakkni', $idSkema)->delete();

            DB::table('asesi_apl02doc')->where('id_asesi', $np)->where('id_skemakkni', $idSkema)->get()
                ->each(function ($row) use (&$filesToDelete) {
                    if (!empty($row->file) && !str_starts_with((string) $row->file, 'http')) {
                        $filesToDelete[] = self::DIR_APL02 . '/' . $row->file;
                    }
                });
            DB::table('asesi_apl02doc')->where('id_asesi', $np)->where('id_skemakkni', $idSkema)->delete();

            DB::commit();

            $jumlahFile = 0;
            foreach (array_unique($filesToDelete) as $relPath) {
                $abs = public_path($relPath);
                if (file_exists($abs)) {
                    @unlink($abs);
                    $jumlahFile++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Pendaftaran skema berhasil dihapus (record peserta tetap ada)',
                'data' => ['file_dihapus' => $jumlahFile],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus pendaftaran skema: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HANDLER 5-7: TELEPON / WHATSAPP / SMS
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/asesi-baru/{id}/kontak
     * Padanan handler telepon & whatsapp: frontend tinggal membuka URL yang diberikan.
     * WhatsApp: konversi 0xxx → 62xxx + template pesan dari tabel identitas.
     */
    public function kontak($id): JsonResponse
    {
        $asesi = Asesi::find($id);
        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        $nohp = preg_replace('/[^0-9]/', '', (string) $asesi->nohp);
        $waNumber = $nohp;
        if (substr($nohp, 0, 1) === '0') {
            $waNumber = '62' . substr($nohp, 1);       // konversi 0xxx → 62xxx
        }

        // Template pesan dari tabel identitas (padanan native)
        $namaLsp = null;
        try {
            $namaLsp = DB::table('identitas')->value('nama_lsp');
        } catch (\Throwable $e) {
            $namaLsp = null;
        }

        $pesanWa = "Yth. {$asesi->nama}. Saya Admin " . ($namaLsp ?? 'LSK') . '.';

        return response()->json([
            'success' => true,
            'data' => [
                'tel_url' => $nohp ? 'tel:' . $asesi->nohp : null,
                'whatsapp_url' => $waNumber
                    ? 'https://api.whatsapp.com/send?phone=' . $waNumber . '&text=' . rawurlencode($pesanWa)
                    : null,
                'pesan_wa' => $pesanWa,
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/asesi-baru/{id}/sms  { pesan: string }
     * Padanan modal SMS (smsasesibaru.php): INSERT ke tabel outbox (antrean SMS gateway).
     */
    public function kirimSms(Request $request, $id): JsonResponse
    {
        $asesi = Asesi::find($id);
        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'pesan' => 'required|string|max:500',
        ], [
            'pesan.required' => 'Isi pesan SMS wajib diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $nohp = preg_replace('/[^0-9]/', '', (string) $asesi->nohp);
        if (strlen($nohp) <= 8) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor HP peserta tidak valid',
            ], 400);
        }

        // INSERT ke outbox (antrean SMS gateway) — idem native
        DB::table('outbox')->insert([
            'DestinationNumber' => $asesi->nohp,
            'TextDecoded' => $request->pesan,
            'CreatorID' => 'api-laravel',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'SMS masuk antrean pengiriman',
        ]);
    }
}
