<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AsesiAsesmen;
use App\Models\AsesiDoc;
use App\Models\AsesmenUnitkompetensi;
use App\Models\BiayaSertifikasi;
use App\Models\SkemaKkni;
use App\Models\SkemaPersyaratan;
use App\Models\UnitKompetensi;
use App\Services\PesertaGateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Skema Sertifikasi — Portal Peserta (auth peserta, BUKAN admin).
 *
 * Implementasi alur docs/BACKEND_PESERTA_SKEMA_SERTIFIKASI.md:
 *   index            → module=skema  (daftar skema aktif + biaya + badge)
 *   show             → module=syarat (detail + gate + dokumen saya)
 *   storeDokumen     → handler tambahdocasesi (upload dokumen persyaratan)
 *   library          → daftar file reusable lintas skema
 *   storeFromLibrary → handler addfromlib (pakai file existing)
 *   destroyDokumen   → handler hapusdocasesi (hanya status P)
 *
 * Perbaikan atas bug legacy:
 *   - dup-check revisi dokumen (cabang elseif salah → duplikat baris)
 *   - hapus dokumen TIDAK unlink file fisik (file di-share via library)
 */
class PesertaSkemaController extends Controller
{
    public function __construct(private PesertaGateService $gate) {}

    // ════════════════════════════════════════════════════════════════
    // GET /peserta/skema — daftar skema aktif + agregat
    // ════════════════════════════════════════════════════════════════

    public function index(Request $request): JsonResponse
    {
        [$user, $asesi, $error] = $this->guardPeserta($request);
        if ($error !== null) {
            return $error;
        }

        // Satu query dengan subquery agregat (Common Query #1 spek)
        $skemaList = SkemaKkni::query()
            ->where('aktif', 'Y')
            ->select('id', 'kode_skema', 'judul', 'jenjang')
            ->selectRaw('(SELECT COUNT(*) FROM skema_persyaratan sp WHERE sp.id_skemakkni = skema_kkni.id) AS jumlah_persyaratan')
            ->selectRaw('(SELECT COUNT(*) FROM unit_kompetensi uk WHERE uk.id_skemakkni = skema_kkni.id) AS jumlah_unit')
            ->selectRaw('COALESCE((SELECT SUM(b.nominal) FROM biaya_sertifikasi b WHERE b.id_skemakkni = skema_kkni.id), 0) AS total_biaya')
            ->orderBy('id')
            ->orderBy('kode_skema')
            ->get();

        // Skema yang sudah didaftar peserta (dup gate status_asesmen != 'K')
        $sudahDaftar = AsesiAsesmen::where('id_asesi', $asesi->no_pendaftaran)
            ->where('status_asesmen', '!=', 'K')
            ->pluck('id_skemakkni')
            ->all();

        $data = $skemaList->map(function ($s) use ($sudahDaftar) {
            return [
                'id' => (int) $s->id,
                'kode_skema' => $s->kode_skema,
                'judul' => $s->judul,
                'jenjang' => $s->jenjang !== null ? (int) $s->jenjang : null,
                'jumlah_persyaratan' => (int) $s->jumlah_persyaratan,
                'jumlah_unit' => (int) $s->jumlah_unit,
                'total_biaya' => (int) $s->total_biaya,
                'total_biaya_formatted' => 'Rp. '.number_format((float) $s->total_biaya, 0, ',', '.'),
                'sudah_daftar' => in_array((string) $s->id, array_map('strval', $sudahDaftar), true),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /peserta/skema/{id} — detail + gate + dokumen saya
    // ════════════════════════════════════════════════════════════════

    public function show(Request $request, $id): JsonResponse
    {
        [$user, $asesi, $error] = $this->guardPeserta($request);
        if ($error !== null) {
            return $error;
        }

        $skema = SkemaKkni::find($id);
        if (! $skema || $skema->aktif !== 'Y') {
            return response()->json(['success' => false, 'message' => 'Skema tidak ditemukan'], 404);
        }

        // ── Panel persyaratan + status upload per kategori (dropdown cerdas) ──
        $persyaratan = SkemaPersyaratan::where('id_skemakkni', $id)->orderBy('id')->get()
            ->map(function ($p) use ($asesi, $id) {
                $docs = AsesiDoc::where('id_asesi', $asesi->no_pendaftaran)
                    ->where('id_skemakkni', (string) $id)
                    ->where('skema_persyaratan', (string) $p->id);
                $jumlah = (clone $docs)->count();
                $jumlahDitolak = (clone $docs)->ditolak()->count();

                return [
                    'id' => (int) $p->id,
                    'persyaratan' => $p->persyaratan,
                    'jumlah_doc' => $jumlah,
                    'status_upload' => $jumlah === 0 ? 'belum' : ($jumlahDitolak > 0 ? 'revisi' : 'ok'),
                ];
            });

        // ── Panel biaya: rincian per jenis + total ──
        $biayaRows = BiayaSertifikasi::bySkema($id)->with('jenisBiaya')->orderBy('jenis_biaya')->get();
        $totalBiaya = (int) $biayaRows->sum('nominal');
        $biaya = [
            'rincian' => $biayaRows->map(fn ($b) => [
                'id' => (int) $b->id,
                'jenis_biaya_id' => (int) $b->jenis_biaya,
                'jenis_label' => $b->jenisBiaya?->jenis_biaya,
                'nominal' => (int) $b->nominal,
                'nominal_formatted' => 'Rp. '.number_format((float) $b->nominal, 0, ',', '.'),
            ]),
            'total_biaya' => $totalBiaya,
            'total_biaya_formatted' => 'Rp. '.number_format($totalBiaya, 0, ',', '.'),
        ];

        // ── Panel unit kompetensi + pilihan peserta (pre-fill checkbox) ──
        $unit = UnitKompetensi::bySkema($id)->orderBy('id')->get(['id', 'kode_unit', 'judul']);
        $unitTerpilih = AsesmenUnitkompetensi::where('id_asesi', $asesi->no_pendaftaran)
            ->where('id_skemakkni', (string) $id)
            ->pluck('id_unitkompetensi')
            ->map(fn ($v) => (int) $v)
            ->all();

        // ── Gate 3 syarat ──
        $gate = $this->gate->hitungGate($asesi, $skema);

        // ── Dokumen saya utk skema ini ──
        $dokumenSaya = AsesiDoc::where('id_asesi', $asesi->no_pendaftaran)
            ->where('id_skemakkni', (string) $id)
            ->orderBy('id')
            ->get()
            ->map(fn ($d) => $this->formatDokumen($d));

        // ── Pendaftaran yang sudah ada (pre-fill / info edit-mode) ──
        $pendaftaran = AsesiAsesmen::where('id_asesi', $asesi->no_pendaftaran)
            ->where('id_skemakkni', (string) $id)
            ->where('status_asesmen', '!=', 'K')
            ->first();

        // ── Dokumen Persyaratan Profil (Syarat Pokok & Tambahan Sinkron dari Profil) ──
        $baseUrl = url('/');
        $sertifikatAmdal = $asesi->sertifikat_amdal ?: $asesi->sertifikat;
        $buktiKeterlibatan = $asesi->bukti_keterlibatan ?: $asesi->suket;
        $sertifikatKompetensiLain = $asesi->sertifikat_kompetensi_lain ?: $asesi->transkrip;

        $verifDok = is_array($asesi->verifikasi_dokumen)
            ? $asesi->verifikasi_dokumen
            : (is_string($asesi->verifikasi_dokumen) ? (json_decode($asesi->verifikasi_dokumen, true) ?: []) : []);

        $dokumenPersyaratanProfil = [
            'syarat_pokok' => [
                [
                    'key' => 'ijazah',
                    'label' => 'Scan Ijazah',
                    'sublabel' => 'Minimal S1 / D4',
                    'wajib' => true,
                    'file' => $asesi->ijazah,
                    'file_url' => $asesi->ijazah ? "{$baseUrl}/storage/foto_asesi/" . $asesi->ijazah : null,
                    'status_verifikasi' => $verifDok['ijazah'] ?? ($asesi->verifikasi === 'V' ? 'terverifikasi' : null),
                ],
                [
                    'key' => 'sertifikat_amdal',
                    'label' => 'Sertifikat Pelatihan AMDAL',
                    'sublabel' => 'Dari LPK AMDAL yang terakreditasi',
                    'wajib' => true,
                    'file' => $sertifikatAmdal,
                    'file_url' => $sertifikatAmdal ? "{$baseUrl}/storage/foto_asesi/" . $sertifikatAmdal : null,
                    'status_verifikasi' => $verifDok['sertifikat_amdal'] ?? ($verifDok['sertifikat'] ?? ($asesi->verifikasi === 'V' ? 'terverifikasi' : null)),
                ],
                [
                    'key' => 'bukti_keterlibatan',
                    'label' => 'Bukti Keterlibatan AMDAL',
                    'sublabel' => 'Nama tercantum dalam susunan tim',
                    'wajib' => true,
                    'file' => $buktiKeterlibatan,
                    'file_url' => $buktiKeterlibatan ? "{$baseUrl}/storage/foto_asesi/" . $buktiKeterlibatan : null,
                    'status_verifikasi' => $verifDok['bukti_keterlibatan'] ?? ($verifDok['suket'] ?? ($asesi->verifikasi === 'V' ? 'terverifikasi' : null)),
                ],
                [
                    'key' => 'dokumen_amdal',
                    'label' => 'Salinan Dokumen AMDAL',
                    'sublabel' => 'ATPA: min 1 dok | KTPA: min 5 dok',
                    'wajib' => true,
                    'file' => $asesi->dokumen_amdal,
                    'file_url' => $asesi->dokumen_amdal ? "{$baseUrl}/storage/foto_asesi/" . $asesi->dokumen_amdal : null,
                    'status_verifikasi' => $verifDok['dokumen_amdal'] ?? ($asesi->verifikasi === 'V' ? 'terverifikasi' : null),
                ],
            ],
            'syarat_tambahan' => [
                [
                    'key' => 'cv',
                    'label' => 'Curriculum Vitae (CV)',
                    'sublabel' => 'Daftar riwayat hidup terbaru',
                    'wajib' => false,
                    'file' => $asesi->cv,
                    'file_url' => $asesi->cv ? "{$baseUrl}/storage/foto_asesi/" . $asesi->cv : null,
                    'status_verifikasi' => $verifDok['cv'] ?? ($asesi->verifikasi === 'V' ? 'terverifikasi' : null),
                ],
                [
                    'key' => 'foto',
                    'label' => 'Pas Foto (3×4)',
                    'sublabel' => 'Latar belakang merah',
                    'wajib' => false,
                    'file' => $asesi->foto,
                    'file_url' => $asesi->foto ? "{$baseUrl}/storage/foto_asesi/" . $asesi->foto : null,
                    'status_verifikasi' => $verifDok['foto'] ?? ($asesi->verifikasi === 'V' ? 'terverifikasi' : null),
                ],
                [
                    'key' => 'ktp',
                    'label' => 'Scan KTP',
                    'sublabel' => 'Identitas kependudukan',
                    'wajib' => false,
                    'file' => $asesi->ktp,
                    'file_url' => $asesi->ktp ? "{$baseUrl}/storage/foto_asesi/" . $asesi->ktp : null,
                    'status_verifikasi' => $verifDok['ktp'] ?? ($asesi->verifikasi === 'V' ? 'terverifikasi' : null),
                ],
                [
                    'key' => 'sertifikat_kompetensi_lain',
                    'label' => 'Sertifikat Pelatihan Relevan / Portofolio',
                    'sublabel' => 'Jika ada',
                    'wajib' => false,
                    'file' => $sertifikatKompetensiLain,
                    'file_url' => $sertifikatKompetensiLain ? "{$baseUrl}/storage/foto_asesi/" . $sertifikatKompetensiLain : null,
                    'status_verifikasi' => $verifDok['sertifikat_kompetensi_lain'] ?? ($asesi->verifikasi === 'V' ? 'terverifikasi' : null),
                ],
                [
                    'key' => 'form_pendaftaran',
                    'label' => 'Formulir Pendaftaran',
                    'sublabel' => 'Diisi & ditandatangani peserta',
                    'wajib' => false,
                    'file' => $asesi->form_pendaftaran,
                    'file_url' => $asesi->form_pendaftaran ? "{$baseUrl}/storage/foto_asesi/" . $asesi->form_pendaftaran : null,
                    'status_verifikasi' => $verifDok['form_pendaftaran'] ?? ($asesi->verifikasi === 'V' ? 'terverifikasi' : null),
                ],
                [
                    'key' => 'sertifikat_atpa_ktpa',
                    'label' => 'Sertifikat ATPA/KTPA Sebelumnya / Portofolio',
                    'sublabel' => 'Jika ada',
                    'wajib' => false,
                    'file' => $asesi->sertifikat_atpa_ktpa,
                    'file_url' => $asesi->sertifikat_atpa_ktpa ? "{$baseUrl}/storage/foto_asesi/" . $asesi->sertifikat_atpa_ktpa : null,
                    'status_verifikasi' => $verifDok['sertifikat_atpa_ktpa'] ?? ($asesi->verifikasi === 'V' ? 'terverifikasi' : null),
                ],
            ],
        ];

        // ── Range tahun dokumen: (tahun lahir + 10) .. tahun berjalan ──
        $dariTahun = $asesi->tgl_lahir
            ? (int) \Carbon\Carbon::parse($asesi->tgl_lahir)->format('Y') + 10
            : 1950;
        $tahunDocRange = [$dariTahun, (int) now()->year];

        return response()->json([
            'success' => true,
            'data' => [
                'skema' => [
                    'id' => (int) $skema->id,
                    'kode_skema' => $skema->kode_skema,
                    'judul' => $skema->judul,
                    'jenjang' => $skema->jenjang !== null ? (int) $skema->jenjang : null,
                    'file_url' => $skema->file ? asset('foto_skema/'.$skema->file) : null,
                ],
                'dokumen_persyaratan_profil' => $dokumenPersyaratanProfil,
                'persyaratan' => $persyaratan,
                'biaya' => $biaya,
                'unit_kompetensi' => [
                    'daftar' => $unit,
                    'unit_terpilih' => $unitTerpilih,
                ],
                'gate' => $gate,
                'dokumen_saya' => $dokumenSaya,
                'pendaftaran_saya' => $pendaftaran ? [
                    'id_asesmen' => (int) $pendaftaran->id,
                    'tujuan_sertifikasi' => $pendaftaran->tujuan_sertifikasi,
                    'tujuan_lainnya' => $pendaftaran->tujuan_lainnya,
                    'tgl_daftar' => $pendaftaran->tgl_daftar?->format('Y-m-d'),
                    'biaya' => (int) ($pendaftaran->biaya ?? 0),
                    'status' => $pendaftaran->status,
                    'status_asesmen' => $pendaftaran->status_asesmen,
                ] : null,
                'tahun_doc_range' => $tahunDocRange,
                'upload_max_mb' => $this->uploadMaxMb(),
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // POST /peserta/skema/{id}/dokumen — upload dokumen persyaratan
    // (handler tambahdocasesi)
    // ════════════════════════════════════════════════════════════════

    public function storeDokumen(Request $request, $id): JsonResponse
    {
        [$user, $asesi, $error] = $this->guardPeserta($request);
        if ($error !== null) {
            return $error;
        }

        $skema = SkemaKkni::find($id);
        if (! $skema || $skema->aktif !== 'Y') {
            return response()->json(['success' => false, 'message' => 'Skema tidak ditemukan'], 404);
        }

        $validator = Validator::make($request->all(), [
            'skema_persyaratan' => 'required|string|max:10',
            'nama_doc' => 'required|string|max:255',
            'tahun_doc' => 'required|integer|min:1900|max:'.now()->year,
            'nomor_doc' => 'nullable|string|max:100',
            'tgl_doc' => 'required|date',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx,zip,rar|max:10240',
        ], $this->pesanValidasi());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => collect($validator->errors()->all())->first() ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Kategori persyaratan harus milik skema ini
        $kategori = SkemaPersyaratan::where('id', $request->input('skema_persyaratan'))
            ->where('id_skemakkni', $id)
            ->first();
        if (! $kategori) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori persyaratan tidak valid untuk skema ini',
            ], 422);
        }

        // ── Dup-check 6 field (logika revisi diperbaiki — fix bug legacy) ──
        $dupQuery = AsesiDoc::where('id_asesi', $asesi->no_pendaftaran)
            ->where('id_skemakkni', (string) $id)
            ->where('skema_persyaratan', $request->input('skema_persyaratan'))
            ->where('nama_doc', trim((string) $request->input('nama_doc')))
            ->where('tahun_doc', (int) $request->input('tahun_doc'))
            ->where('nomor_doc', $this->normalisasiNomor($request->input('nomor_doc')));
        $dup = (clone $dupQuery)->count();
        $dupR = (clone $dupQuery)->ditolak()->count();

        if ($dup > 0 && $dupR === 0) {
            // Dokumen identik non-ditolak sudah ada → tolak
            // (legacy: INSERT lagi = celah duplikasi)
            return response()->json([
                'success' => false,
                'message' => 'Maaf, dokumen dengan data yang sama sudah ada.',
            ], 409);
        }
        $pesan = $dupR > 0
            ? 'Perbaikan Data Dokumen Sukses'
            : 'Tambah Data Dokumen Sukses';

        // ── Upload file (jika ada) ──
        $fileNama = null;
        if ($request->hasFile('file')) {
            $uploaded = $request->file('file');
            $fileNama = sprintf(
                '%s_doc%s_%s.%s',
                $asesi->no_pendaftaran,
                $kategori->id,
                uniqid(),
                strtolower($uploaded->getClientOriginalExtension())
            );
            $uploaded->storeAs('foto_asesi', $fileNama, 'public');
        }

        $doc = AsesiDoc::create([
            'id_asesi' => $asesi->no_pendaftaran,
            'id_skemakkni' => (string) $id,
            'skema_persyaratan' => (string) $kategori->id,
            'nama_doc' => trim((string) $request->input('nama_doc')),
            'tahun_doc' => (int) $request->input('tahun_doc'),
            'nomor_doc' => $this->normalisasiNomor($request->input('nomor_doc')),
            'tgl_doc' => $request->input('tgl_doc'),
            'file' => $fileNama,
            'status' => 'P',
        ]);

        return response()->json([
            'success' => true,
            'message' => $pesan,
            'data' => $this->formatDokumen($doc),
        ], 201);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /peserta/dokumen-library — daftar file reusable lintas skema
    // ════════════════════════════════════════════════════════════════

    public function library(Request $request): JsonResponse
    {
        [$user, $asesi, $error] = $this->guardPeserta($request);
        if ($error !== null) {
            return $error;
        }

        $rows = AsesiDoc::where('id_asesi', $asesi->no_pendaftaran)
            ->whereNotNull('file')
            ->where('file', '!=', '')
            ->orderBy('id', 'desc')
            ->get();

        // Dedup per file (satu file bisa direferensikan banyak baris)
        $seen = [];
        $data = [];
        foreach ($rows as $d) {
            if (isset($seen[$d->file])) {
                continue;
            }
            $seen[$d->file] = true;

            $skemaAsal = $d->id_skemakkni
                ? DB::table('skema_kkni')->where('id', $d->id_skemakkni)->first(['id', 'judul'])
                : null;

            $data[] = [
                'file' => $d->file,
                'nama_doc' => $d->nama_doc,
                'nomor_doc' => $d->nomor_doc,
                'tahun_doc' => $d->tahun_doc,
                'tgl_doc' => $d->tgl_doc?->format('Y-m-d'),
                'file_url' => $d->file_url,
                'skema_asal' => $skemaAsal ? ['id' => (int) $skemaAsal->id, 'judul' => $skemaAsal->judul] : null,
            ];
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ════════════════════════════════════════════════════════════════
    // POST /peserta/skema/{id}/dokumen-library — pakai file existing
    // (handler addfromlib)
    // ════════════════════════════════════════════════════════════════

    public function storeFromLibrary(Request $request, $id): JsonResponse
    {
        [$user, $asesi, $error] = $this->guardPeserta($request);
        if ($error !== null) {
            return $error;
        }

        $skema = SkemaKkni::find($id);
        if (! $skema || $skema->aktif !== 'Y') {
            return response()->json(['success' => false, 'message' => 'Skema tidak ditemukan'], 404);
        }

        $validator = Validator::make($request->all(), [
            'skema_persyaratan' => 'required|string|max:10',
            'nama_doc' => 'required|string|max:255',
            'tahun_doc' => 'required|integer|min:1900|max:'.now()->year,
            'nomor_doc' => 'nullable|string|max:100',
            'tgl_doc' => 'required|date',
            'file' => 'required|string|max:100',
        ], $this->pesanValidasi());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => collect($validator->errors()->all())->first() ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $kategori = SkemaPersyaratan::where('id', $request->input('skema_persyaratan'))
            ->where('id_skemakkni', $id)
            ->first();
        if (! $kategori) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori persyaratan tidak valid untuk skema ini',
            ], 422);
        }

        // Ownership check: file harus milik dokumen peserta sendiri
        // (mencegah referensi file arbitrer di foto_asesi/)
        $fileMilikSendiri = AsesiDoc::where('id_asesi', $asesi->no_pendaftaran)
            ->where('file', $request->input('file'))
            ->exists();
        if (! $fileMilikSendiri) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan di dokumen Anda',
            ], 422);
        }

        // Dup-check 6 field (fix yang sama dgn upload biasa)
        $dupQuery = AsesiDoc::where('id_asesi', $asesi->no_pendaftaran)
            ->where('id_skemakkni', (string) $id)
            ->where('skema_persyaratan', $request->input('skema_persyaratan'))
            ->where('nama_doc', trim((string) $request->input('nama_doc')))
            ->where('tahun_doc', (int) $request->input('tahun_doc'))
            ->where('nomor_doc', $this->normalisasiNomor($request->input('nomor_doc')));
        $dup = (clone $dupQuery)->count();
        $dupR = (clone $dupQuery)->ditolak()->count();

        if ($dup > 0 && $dupR === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf, dokumen dengan data yang sama sudah ada.',
            ], 409);
        }
        $pesan = $dupR > 0
            ? 'Perbaikan Data Dokumen Sukses'
            : 'Tambah Data Dokumen Sukses';

        $doc = AsesiDoc::create([
            'id_asesi' => $asesi->no_pendaftaran,
            'id_skemakkni' => (string) $id,
            'skema_persyaratan' => (string) $kategori->id,
            'nama_doc' => trim((string) $request->input('nama_doc')),
            'tahun_doc' => (int) $request->input('tahun_doc'),
            'nomor_doc' => $this->normalisasiNomor($request->input('nomor_doc')),
            'tgl_doc' => $request->input('tgl_doc'),
            'file' => $request->input('file'),   // file fisik di-share, tanpa upload
            'status' => 'P',
        ]);

        return response()->json([
            'success' => true,
            'message' => $pesan,
            'data' => $this->formatDokumen($doc),
        ], 201);
    }

    // ════════════════════════════════════════════════════════════════
    // DELETE /peserta/dokumen/{id} — hapus dokumen sendiri
    // (handler hapusdocasesi — hanya status P; file fisik TIDAK di-unlink
    // karena satu file bisa di-share banyak baris via library)
    // ════════════════════════════════════════════════════════════════

    public function destroyDokumen(Request $request, $id): JsonResponse
    {
        [$user, $asesi, $error] = $this->guardPeserta($request);
        if ($error !== null) {
            return $error;
        }

        $doc = AsesiDoc::where('id', $id)
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->first();
        if (! $doc) {
            return response()->json(['success' => false, 'message' => 'Dokumen tidak ditemukan'], 404);
        }

        if ($doc->status !== 'P') {
            return response()->json([
                'success' => false,
                'message' => 'Maaf, dokumen sudah diverifikasi admin dan tidak dapat dihapus.',
            ], 409);
        }

        $doc->delete();

        return response()->json(['success' => true, 'message' => 'Dokumen berhasil dihapus']);
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    /**
     * Guard berlapis: 401 sesi → 403 role → resolve asesi → 403 blokir.
     * Return [user, asesi, errorResponse?]
     */
    private function guardPeserta(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [null, null, response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401)];
        }
        if (! $user->isPeserta()) {
            return [$user, null, response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus peserta.',
            ], 403)];
        }

        $asesi = $this->gate->resolveAsesi($user);
        if (! $asesi) {
            return [$user, null, response()->json([
                'success' => false,
                'message' => 'Profil peserta belum lengkap. Lengkapi profil terlebih dahulu.',
            ], 404)];
        }
        if ($asesi->blokir === 'Y') {
            return [$user, $asesi, response()->json([
                'success' => false,
                'message' => 'Akun Anda diblokir. Hubungi admin LSK.',
            ], 403)];
        }

        return [$user, $asesi, null];
    }

    /**
     * Format satu baris asesi_doc untuk response.
     */
    private function formatDokumen(AsesiDoc $d): array
    {
        return [
            'id' => (int) $d->id,
            'skema_persyaratan' => $d->skema_persyaratan !== null ? (string) $d->skema_persyaratan : null,
            'nama_doc' => $d->nama_doc,
            'tahun_doc' => $d->tahun_doc,
            'nomor_doc' => $d->nomor_doc,
            'tgl_doc' => $d->tgl_doc?->format('Y-m-d'),
            'file' => $d->file,
            'file_url' => $d->file_url,
            'status' => $d->status,
            'status_label' => $d->status_label,
            'bisa_hapus' => $d->bisa_hapus,
        ];
    }

    /**
     * Normalisasi nomor dokumen utk dup-check & penyimpanan:
     * trim; string kosong → null (konsisten dgn kolom legacy).
     */
    private function normalisasiNomor($value): ?string
    {
        $v = trim((string) $value);

        return $v === '' ? null : $v;
    }

    /**
     * Batas upload efektif (MB): min(upload_max_filesize, post_max_size)
     * — padanan $upload_mb legacy, berguna utk validasi frontend.
     */
    private function uploadMaxMb(): int
    {
        $toBytes = function (string $val): int {
            $val = trim($val);
            $unit = strtolower(substr($val, -1));
            $num = (int) $val;

            return match ($unit) {
                'g' => $num * 1024,
                'm' => $num,
                'k' => max(1, (int) floor($num / 1024)),
                default => max(1, (int) floor($num / (1024 * 1024))),
            };
        };

        $upload = $toBytes(ini_get('upload_max_filesize') ?: '2M');
        $post = $toBytes(ini_get('post_max_size') ?: '8M');

        return min($upload, $post);
    }

    /**
     * Pesan validasi Bahasa Indonesia (pola PesertaProfilController).
     */
    private function pesanValidasi(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'string' => ':attribute harus berupa teks.',
            'integer' => ':attribute harus berupa angka.',
            'date' => ':attribute harus tanggal yang valid.',
            'file.file' => 'Berkas tidak valid.',
            'file.mimes' => 'Format berkas harus: jpg, jpeg, png, webp, pdf, doc, docx, zip, atau rar.',
            'file.max' => 'Ukuran berkas maksimal 10 MB.',
            'nama_doc.max' => 'Nama dokumen maksimal 255 karakter.',
            'nomor_doc.max' => 'Nomor dokumen maksimal 100 karakter.',
            'min' => [
                'numeric' => ':attribute minimal :min.',
            ],
            'max' => [
                'numeric' => ':attribute maksimal :max.',
            ],
        ];
    }
}
