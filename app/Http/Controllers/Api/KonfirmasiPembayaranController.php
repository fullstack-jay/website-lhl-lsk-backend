<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesi;
use App\Models\AsesiAsesmen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Konfirmasi Pembayaran (konfpay) — Portal Peserta
 * Implementasi modul `konfpay` PHP Native versi API.
 * Sesuai docs/BACKEND_KONFIRMASI_PEMBAYARAN.md:
 *
 * Pelaporan pembayaran manual (BUKAN payment gateway): peserta transfer/
 * tunai ke rekening LSK lalu melaporkannya + upload bukti.
 *
 * Pipeline: submit → INSERT asesi_pembayaran (status='P') +
 *           UPDATE asesi_asesmen.biaya_asesmen 'P'→'K' (pipeline maju)
 * Hapus:    unlink bukti + biaya_asesmen mundur ke 'P' + DELETE
 *           (✅ guard: tolak jika status='V' — fix inkonsistensi native)
 *
 * Perbaikan atas native:
 * - Validasi numeric dropdown & nominal > 0 (fix data kotor: placeholder
 *   "-- Pilih Skema --" dan nominal 0 tersimpan di data aktual)
 * - Dup-check 8 field (anti double-submit)
 * - Transaction wrap (fix partial-save)
 * - Guard hapus status='V'
 */
class KonfirmasiPembayaranController extends Controller
{
    /** Direktori upload bukti transfer. */
    private const UPLOAD_DIR = 'foto_asesibayar';

    // ════════════════════════════════════════════════════════════════
    // HALAMAN UTAMA — 3 KONDISI (form / riwayat / warning)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/peserta/konfirmasi-pembayaran
     * Render halaman: data dropdown form + daftar pendaftaran + riwayat.
     */
    public function index(Request $request): JsonResponse
    {
        [$user, $asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        // Pendaftaran skema peserta + flag butuh konfirmasi (biaya='P')
        $pendaftaran = AsesiAsesmen::where('id_asesi', $asesi->no_pendaftaran)
            ->orderBy('id', 'desc')
            ->get();

        $perluKonfirmasi = $pendaftaran->contains(fn ($m) => $m->biaya_asesmen === 'P');

        // Dropdown skema: value = asesi_asesmen.id (BUKAN skema id — idem native)
        $dropdownSkema = $pendaftaran->map(function ($m) {
            $s = DB::table('skema_kkni')->where('id', $m->id_skemakkni)
                ->first(['kode_skema', 'judul']);
            return [
                'id_asesmen' => $m->id,
                'label' => $s ? "{$s->kode_skema} - {$s->judul}" : "Asesmen #{$m->id}",
                'biaya' => $m->biaya ? (int) $m->biaya : null,
                'biaya_formatted' => $m->biaya ? number_format((float) $m->biaya, 0, ',', '.') : null,
                'biaya_asesmen' => $m->biaya_asesmen,   // P = perlu konfirmasi
            ];
        });

        // Riwayat konfirmasi (CQ #1)
        $riwayat = DB::table('asesi_pembayaran')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->orderBy('waktu', 'desc')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'id_asesmen' => $p->id_asesmen,
                'metode_bayar' => $p->metode_bayar,
                'jalur_bayar' => $p->jalur_bayar,
                'tujuan_rek' => $p->tujuan_rek,
                'rekening_label' => $this->rekeningLabel($p->tujuan_rek),
                'nominal' => (int) $p->nominal,
                'nominal_formatted' => number_format((float) $p->nominal, 0, ',', '.'),
                'tgl_bayar' => $p->tgl_bayar,
                'jam_bayar' => $p->jam_bayar ? substr((string) $p->jam_bayar, 0, 5) : null,
                'file' => $p->file,
                'bukti_url' => !empty($p->file) ? asset(self::UPLOAD_DIR . '/' . $p->file) : null,
                'status' => $p->status,                 // P | V
                'status_label' => $p->status === 'P' ? 'Menunggu Validasi' : 'Telah Divalidasi',
                'waktu' => $p->waktu,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'peserta' => [
                    'nama' => $asesi->nama,
                    'no_pendaftaran' => $asesi->no_pendaftaran,
                ],
                // KONDISI 1: belum daftar skema
                'belum_daftar' => $pendaftaran->isEmpty(),
                // KONDISI 2: masih ada biaya='P' → tampilkan form
                'perlu_konfirmasi' => $perluKonfirmasi,
                // KONDISI 3: semua != 'P' → riwayat + pesan sukses
                'semua_terkonfirmasi' => !$pendaftaran->isEmpty() && !$perluKonfirmasi,
                // Dropdown form (value = asesi_asesmen.id)
                'dropdown_skema' => $dropdownSkema,
                // Riwayat konfirmasi
                'riwayat' => $riwayat,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // DROPDOWN BERANTAI (padanan AJAX jalurpembayaran.php + rekpembayaran.php)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/peserta/konfirmasi-pembayaran/jalur?metode=Transfer
     * Level 1: jalur per metode (Tunai → Tunai; Transfer → ATM/Teller/IB/MB)
     */
    public function jalur(Request $request): JsonResponse
    {
        $metode = $request->query('metode');
        if (!in_array($metode, ['Tunai', 'Transfer'])) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter metode harus Tunai atau Transfer',
            ], 422);
        }

        $jalur = DB::table('rekeningbayar_jalur')
            ->where('metode', $metode)
            ->get(['jalur', 'metode']);

        return response()->json([
            'success' => true,
            'data' => $jalur->map(fn ($j) => ['jalur' => $j->jalur, 'metode' => $j->metode]),
        ]);
    }

    /**
     * GET /api/v1/peserta/konfirmasi-pembayaran/rekening?metode=Transfer
     * Level 2: rekening tujuan.
     * ✅ Perbaikan atas native: filter by id_rekeningbayar bila dikirim
     *    (native filter by metode saja — semua rekening metode sama muncul).
     */
    public function rekening(Request $request): JsonResponse
    {
        $metode = $request->query('metode');
        $jalur = $request->query('jalur');
        $idRekening = $request->query('id_rekeningbayar');

        if (!in_array($metode, ['Tunai', 'Transfer'])) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter metode harus Tunai atau Transfer',
            ], 422);
        }

        $query = DB::table('rekeningbayar')->where('aktif', 'Y')->where('metode', $metode);

        // Filter spesifik via jalur → id_rekeningbayar (perbaikan desain native)
        if ($jalur) {
            $ids = DB::table('rekeningbayar_jalur')
                ->where('jalur', $jalur)
                ->where('metode', $metode)
                ->pluck('id_rekeningbayar');
            $query->whereIn('id', $ids);
        } elseif ($idRekening) {
            $query->where('id', $idRekening);
        }

        $rekening = $query->get(['id', 'bank', 'norek', 'atasnama', 'logo']);

        return response()->json([
            'success' => true,
            'data' => $rekening->map(fn ($r) => [
                'id' => $r->id,
                'bank' => $r->bank,
                'norek' => $r->norek,
                'atasnama' => $r->atasnama,
                'logo_url' => $r->logo ? asset('foto_rekening/' . $r->logo) : null,
                // "{bank} {norek} {atasnama}" — idem format dropdown native
                'label' => "{$r->bank} {$r->norek} a.n. {$r->atasnama}",
            ]),
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // SUBMIT (padanan handler konfirmbayar)
    // ════════════════════════════════════════════════════════════════

    /**
     * POST /api/v1/peserta/konfirmasi-pembayaran  (multipart/form-data)
     *
     * 1. Validasi (numeric id & nominal > 0 — fix data kotor native)
     * 2. Dup-check 8 field → 409
     * 3. INSERT asesi_pembayaran (status='P', file bukti opsional)
     * 4. ⭐ UPDATE biaya_asesmen 'P'→'K' (pipeline maju) — transaction
     */
    public function store(Request $request): JsonResponse
    {
        [$user, $asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'id_asesmen' => 'required|integer|exists:asesi_asesmen,id',
            'metode_bayar' => 'required|in:Tunai,Transfer',
            'jalur_bayar' => 'required|string',
            'tujuan_rek' => 'required|integer|exists:rekeningbayar,id',
            'nominal' => 'required|integer|min:1',
            'tgl_bayar' => 'required|date|before_or_equal:today',
            'jam_bayar' => 'nullable|date_format:H:i:s,H:i',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ], [
            'id_asesmen.required' => 'Skema wajib dipilih',
            'id_asesmen.exists' => 'Pendaftaran asesmen tidak ditemukan',
            'metode_bayar.required' => 'Metode pembayaran wajib dipilih',
            'jalur_bayar.required' => 'Jalur pembayaran wajib dipilih',
            'tujuan_rek.required' => 'Rekening tujuan wajib dipilih',
            'nominal.required' => 'Nominal wajib diisi',
            'nominal.min' => 'Nominal harus lebih dari 0',
            'tgl_bayar.required' => 'Tanggal bayar wajib diisi',
            'file.mimes' => 'Bukti transfer harus PDF/JPG/PNG',
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
            // 1. Resolve pendaftaran milik peserta ini (skema dropdown = asesi_asesmen.id)
            $asesmen = AsesiAsesmen::where('id', $request->id_asesmen)
                ->where('id_asesi', $asesi->no_pendaftaran)
                ->first();

            if (!$asesmen) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Pendaftaran asesmen tidak ditemukan',
                ], 404);
            }

            // 2. Dup-check 8 field (anti double-submit, idem native)
            $exists = DB::table('asesi_pembayaran')
                ->where('id_asesmen', $asesmen->id)
                ->where('id_asesi', $asesi->no_pendaftaran)
                ->where('id_skemakkni', $asesmen->id_skemakkni)
                ->where('metode_bayar', $request->metode_bayar)
                ->where('jalur_bayar', $request->jalur_bayar)
                ->where('tujuan_rek', $request->tujuan_rek)
                ->where('nominal', $request->nominal)
                ->whereDate('tgl_bayar', $request->tgl_bayar)
                ->exists();

            if ($exists) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Data telah Anda konfirmasi sebelumnya',
                ], 409);
            }

            // 3. Upload bukti (opsional)
            $fileName = null;
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $ext = strtolower($file->getClientOriginalExtension());
                $fileName = time() . md5($file->getClientOriginalName() . microtime()) . '.' . $ext;

                $dest = public_path(self::UPLOAD_DIR);
                if (!file_exists($dest)) mkdir($dest, 0755, true);
                $file->move($dest, $fileName);
            }

            // 4. INSERT konfirmasi (status='P' = menunggu validasi admin)
            $pembayaranId = DB::table('asesi_pembayaran')->insertGetId([
                'id_asesmen' => $asesmen->id,
                'id_asesi' => $asesi->no_pendaftaran,
                'id_skemakkni' => $asesmen->id_skemakkni,   // derived dari asesi_asesmen
                'metode_bayar' => $request->metode_bayar,
                'jalur_bayar' => $request->jalur_bayar,
                'tujuan_rek' => $request->tujuan_rek,
                'nominal' => (int) $request->nominal,
                'tgl_bayar' => $request->tgl_bayar,
                'jam_bayar' => $request->jam_bayar,
                'file' => $fileName,
                'status' => 'P',
                'waktu' => now(),
            ]);

            // 5. ⭐ PIPELINE MAJU: biaya_asesmen 'P' → 'K'
            $asesmen->biaya_asesmen = 'K';
            $asesmen->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Konfirmasi Pembayaran Berhasil. Menunggu validasi admin.',
                'data' => [
                    'id_pembayaran' => $pembayaranId,
                    'id_asesmen' => $asesmen->id,
                    'biaya_asesmen' => $asesmen->biaya_asesmen,   // K
                    'status' => 'P',
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat konfirmasi pembayaran',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HAPUS (padanan handler hapuskonfpay — REVERSIBLE)
    // ════════════════════════════════════════════════════════════════

    /**
     * DELETE /api/v1/peserta/konfirmasi-pembayaran/{id}
     *
     * unlink bukti → biaya_asesmen mundur ke 'P' → DELETE baris.
     * ✅ Guard (perbaikan atas native): tolak jika status='V' — mencegah
     * inkonsistensi pipeline (peserta menghapus konfirmasi yang SUDAH
     * divalidasi admin).
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        [$user, $asesi, $error] = $this->resolvePeserta($request);
        if ($error) {
            return $error;
        }

        $pembayaran = DB::table('asesi_pembayaran')
            ->where('id', $id)
            ->where('id_asesi', $asesi->no_pendaftaran)   // ownership check
            ->first();

        if (!$pembayaran) {
            return response()->json([
                'success' => false,
                'message' => 'Data konfirmasi pembayaran tidak ditemukan',
            ], 404);
        }

        // ✅ Guard status='V' (fix inkonsistensi pipeline native)
        if ($pembayaran->status === 'V') {
            return response()->json([
                'success' => false,
                'message' => 'Konfirmasi ini telah divalidasi admin dan tidak dapat dihapus',
            ], 400);
        }

        DB::beginTransaction();
        try {
            // a. Unlink bukti (guard !empty + file_exists)
            if (!empty($pembayaran->file) && !str_starts_with((string) $pembayaran->file, 'http')) {
                $filePath = public_path(self::UPLOAD_DIR . '/' . $pembayaran->file);
                if (file_exists($filePath)) @unlink($filePath);
            }

            // b. Pipeline mundur: biaya_asesmen kembali ke 'P'
            if ($pembayaran->id_asesmen) {
                AsesiAsesmen::where('id', $pembayaran->id_asesmen)
                    ->update(['biaya_asesmen' => 'P']);
            }

            // c. DELETE baris konfirmasi
            DB::table('asesi_pembayaran')->where('id', $id)->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Konfirmasi pembayaran berhasil dihapus. Silakan lakukan konfirmasi ulang.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus konfirmasi pembayaran',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    /**
     * Resolve peserta dari token. Return [user, asesi, errorResponse]
     */
    private function resolvePeserta(Request $request): array
    {
        $user = $request->user();
        if (!$user) {
            return [null, null, response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401)];
        }
        if (!$user->isPeserta()) {
            return [null, null, response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus peserta.',
            ], 403)];
        }

        $asesi = Asesi::where('no_ktp', $user->no_ktp)
            ->orWhere('nohp', $user->no_telp)
            ->first();

        if (!$asesi) {
            return [null, null, response()->json([
                'success' => false,
                'message' => 'Profil peserta belum lengkap. Lengkapi profil terlebih dahulu.',
            ], 404)];
        }

        return [$user, $asesi, null];
    }

    /**
     * Label rekening dari tujuan_rek (id) — untuk riwayat.
     */
    private function rekeningLabel($tujuanRek): ?string
    {
        if (empty($tujuanRek) || !ctype_digit((string) $tujuanRek)) {
            return $tujuanRek;   // data kotor native: placeholder literal
        }
        $r = DB::table('rekeningbayar')->where('id', $tujuanRek)->first();
        return $r ? "{$r->bank} {$r->norek} a.n. {$r->atasnama}" : $tujuanRek;
    }
}
