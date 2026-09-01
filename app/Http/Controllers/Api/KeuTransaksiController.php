<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KeuTransaksi;
use App\Models\KeuKodeakun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Laporan Transaksi Keuangan LSK (inlapkeu) — implementasi modul PHP Native
 * versi API. Sesuai docs/BACKEND_KEUANGAN.md:
 *
 * - CRUD lengkap: create (dup-check no_trx), update, delete (+unlink guarded)
 * - Audit trail: `clerk` = username yang input/ubah (auto, last-writer)
 * - Pemasukan/pengeluaran dirender dari 1 kolom nominal + flag IN/OUT
 * - File bukti: pdf/jpg/jpeg/bmp/png → foto_lapkeuangan/, rename timestamp.md5
 *
 * Perbaikan atas native:
 * - Update: cek by id (bukan no_trx baru yang selalu ditolak) + dup-check
 *   no_trx terpisah saat no_trx diubah
 * - Ganti bukti: unlink file lama (fix orphan native)
 * - Nominal: validasi numerik server-side (native tersimpan 0 saat "abc")
 * - tgl_transaksi kosong → NULL (anti zero-date MySQL 8 strict)
 * - Ringkasan saldo + rekap per akun + filter periode (Common Queries #2/#3/#4)
 */
class KeuTransaksiController extends Controller
{
    /** Direktori upload bukti transaksi. */
    private const UPLOAD_DIR = 'foto_lapkeuangan';

    // ════════════════════════════════════════════════════════════════
    // LIST (padanan render view inlapkeu — CQ #1)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/keuangan?jenis=&dari=&sampai=&search=&page=&per_page=
     * Buku kas ORDER BY tgl_transaksi DESC + ringkasan saldo.
     */
    public function index(Request $request): JsonResponse
    {
        $query = KeuTransaksi::query()
            ->join('keu_kodeakun as k', 'k.kode_akun', '=', 'keu_transaksi.kode_akun')
            ->select('keu_transaksi.*', 'k.keterangan as akun_keterangan')
            ->orderBy('keu_transaksi.tgl_transaksi', 'desc')
            ->orderBy('keu_transaksi.id', 'desc');

        // Filter jenis (IN/OUT)
        if ($request->filled('jenis')) {
            $query->jenis($request->jenis);
        }

        // Filter periode (CQ #4 — laporan bulanan BNSP)
        if ($request->filled('dari') && $request->filled('sampai')) {
            $query->periode($request->dari, $request->sampai);
        }

        // Search
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Hanya transaksi tanpa bukti (CQ #5 — audit kelengkapan)
        if ($request->boolean('tanpa_bukti')) {
            $query->tanpaBukti();
        }

        $perPage = min((int) ($request->per_page ?? 20), 100);
        $result = $query->paginate($perPage);

        $data = collect($result->items())->map(fn ($row) => $this->transformTrx($row));

        return response()->json([
            'success' => true,
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
     * GET /api/v1/admin/keuangan/ringkasan?dari=&sampai=
     * Padanan Common Query #2: total pemasukan/pengeluaran/saldo
     * + rekap per kode akun (CQ #3) + aktivitas clerk (CQ #6).
     */
    public function ringkasan(Request $request): JsonResponse
    {
        $base = KeuTransaksi::query();
        if ($request->filled('dari') && $request->filled('sampai')) {
            $base->periode($request->dari, $request->sampai);
        }

        // CQ #2: ringkasan saldo
        $totals = (clone $base)->selectRaw("
            COALESCE(SUM(CASE WHEN jenis_transaksi='IN' THEN nominal ELSE 0 END), 0) AS total_pemasukan,
            COALESCE(SUM(CASE WHEN jenis_transaksi='OUT' THEN nominal ELSE 0 END), 0) AS total_pengeluaran,
            COALESCE(SUM(CASE WHEN jenis_transaksi='IN' THEN nominal ELSE -nominal END), 0) AS saldo
        ")->first();

        // CQ #3: rekap per kode akun
        $perAkun = DB::table('keu_kodeakun as k')
            ->leftJoin('keu_transaksi as t', function ($join) use ($request) {
                $join->on('t.kode_akun', '=', 'k.kode_akun');
                if ($request->filled('dari') && $request->filled('sampai')) {
                    $join->whereBetween('t.tgl_transaksi', [$request->dari, $request->sampai]);
                }
            })
            ->selectRaw("
                k.kode_akun, k.keterangan,
                COALESCE(SUM(CASE WHEN t.jenis_transaksi='IN' THEN t.nominal ELSE 0 END), 0) AS masuk,
                COALESCE(SUM(CASE WHEN t.jenis_transaksi='OUT' THEN t.nominal ELSE 0 END), 0) AS keluar
            ")
            ->groupBy('k.kode_akun', 'k.keterangan')
            ->orderBy('k.kode_akun')
            ->get();

        // CQ #6: aktivitas clerk
        $perClerk = (clone $base)
            ->selectRaw("clerk, COUNT(*) as jumlah_trx,
                COALESCE(SUM(CASE WHEN jenis_transaksi='IN' THEN nominal ELSE 0 END), 0) AS total_input")
            ->groupBy('clerk')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_pemasukan' => (int) $totals->total_pemasukan,
                'total_pengeluaran' => (int) $totals->total_pengeluaran,
                'saldo' => (int) $totals->saldo,
                'total_pemasukan_formatted' => number_format($totals->total_pemasukan, 0, ',', '.'),
                'total_pengeluaran_formatted' => number_format($totals->total_pengeluaran, 0, ',', '.'),
                'saldo_formatted' => number_format($totals->saldo, 0, ',', '.'),
                'per_kode_akun' => $perAkun,
                'per_clerk' => $perClerk,
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/keuangan/{id}
     * Detail untuk pre-fill form edit (padanan LOAD ubahtrx).
     */
    public function show($id): JsonResponse
    {
        $trx = KeuTransaksi::find($id);
        if (!$trx) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->transformTrx($trx, detail: true),
        ]);
    }

    /**
     * GET /api/v1/admin/keuangan/kode-akun
     * Dropdown master akun — "{keterangan} ({kode_akun})" ORDER BY kode (idem native).
     */
    public function kodeAkun(): JsonResponse
    {
        $akun = KeuKodeakun::orderBy('kode_akun', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $akun->map(fn ($a) => [
                'kode_akun' => $a->kode_akun,
                'keterangan' => $a->keterangan,
                'label' => "{$a->keterangan} ({$a->kode_akun})",
            ]),
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // CREATE (padanan handler tambahkan)
    // ════════════════════════════════════════════════════════════════

    /**
     * POST /api/v1/admin/keuangan
     *
     * Dup-check no_trx → INSERT + clerk dari user login (audit).
     * Perbaikan: nominal validasi numerik (native tersimpan 0 saat "abc"),
     * tgl kosong → NULL (anti zero-date).
     */
    public function store(Request $request): JsonResponse
    {
        $validator = $this->validateTrx($request);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Dup-check no_trx (idem native — nomor bukti tidak boleh ganda)
        if (KeuTransaksi::where('no_trx', $request->no_trx)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Transaksi dengan Nomor Tersebut Sudah Ada',
            ], 409);
        }

        DB::beginTransaction();
        try {
            $trx = new KeuTransaksi([
                'no_trx' => strip_tags(trim($request->no_trx)),
                'nama' => strip_tags(trim($request->nama)),
                'jenis_transaksi' => $request->jenis_transaksi,   // IN | OUT
                'kode_akun' => $request->kode_akun,
                'nominal' => (int) $request->nominal,
                // Zero-date guard: kosong → NULL
                'tgl_transaksi' => $request->filled('tgl_transaksi') ? $request->tgl_transaksi : null,
                'clerk' => $request->user()?->username ?? 'system',   // ⭐ audit dari session
            ]);
            $trx->save();

            // Upload bukti (opsional)
            $this->handleUpload($request, $trx, isCreate: true);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Input Data Transaksi Sukses',
                'data' => $this->transformTrx($trx->fresh(), detail: true),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan transaksi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // UPDATE (padanan module ubahtrx)
    // ════════════════════════════════════════════════════════════════

    /**
     * PUT/POST /api/v1/admin/keuangan/{id}  (multipart/form-data didukung)
     *
     * Perbaikan atas native:
     * - Existence-check by ID (native cek by no_trx baru → mengubah no_trx
     *   selalu gagal!) + dup-check no_trx terpisah bila diubah
     * - Ganti bukti: unlink file lama (native → orphan)
     * - clerk berubah jadi editor (idem native — audit last-writer)
     */
    public function update(Request $request, $id): JsonResponse
    {
        $trx = KeuTransaksi::find($id);
        if (!$trx) {
            return $this->notFound();
        }

        $validator = $this->validateTrx($request);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Dup-check no_trx TERPISAH (jika no_trx diubah ke nilai milik transaksi lain)
        if ($request->no_trx !== $trx->no_trx
            && KeuTransaksi::where('no_trx', $request->no_trx)->where('id', '!=', $id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Transaksi dengan Nomor Tersebut Sudah Ada',
            ], 409);
        }

        DB::beginTransaction();
        try {
            // Ganti bukti: unlink lama dulu (fix orphan native)
            $this->handleUpload($request, $trx, isCreate: false);

            $trx->no_trx = strip_tags(trim($request->no_trx));
            $trx->nama = strip_tags(trim($request->nama));
            $trx->jenis_transaksi = $request->jenis_transaksi;
            $trx->kode_akun = $request->kode_akun;
            $trx->nominal = (int) $request->nominal;
            $trx->tgl_transaksi = $request->filled('tgl_transaksi') ? $request->tgl_transaksi : null;
            $trx->clerk = $request->user()?->username ?? 'system';   // clerk = editor

            $trx->save();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Ubah Data Transaksi Sukses',
                'data' => $this->transformTrx($trx->fresh(), detail: true),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui transaksi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // DELETE (padanan handler hapustrx — unlink guarded ✅ pola terbaik)
    // ════════════════════════════════════════════════════════════════

    /**
     * DELETE /api/v1/admin/keuangan/{id}
     * Delete + unlink bukti (guard !empty — idem pola terbaik native).
     */
    public function destroy($id): JsonResponse
    {
        $trx = KeuTransaksi::find($id);
        if (!$trx) {
            return $this->notFound();
        }

        try {
            // Unlink bukti dengan guard (idem native hapustrx — pola benar)
            if (!empty($trx->file) && !str_starts_with((string) $trx->file, 'http')) {
                $filePath = public_path(self::UPLOAD_DIR . '/' . $trx->file);
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            $trx->delete();

            return response()->json([
                'success' => true,
                'message' => 'Hapus Data Transaksi Sukses',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus transaksi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    /**
     * Validasi transaksi (shared store/update).
     * Nominal: integer + min 1 (fix native yang menerima "abc" → 0).
     */
    private function validateTrx(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'no_trx' => 'required|string|max:255',
            'nama' => 'required|string|max:255',
            'jenis_transaksi' => 'required|in:IN,OUT',
            'kode_akun' => 'required|string|exists:keu_kodeakun,kode_akun',
            'nominal' => 'required|integer|min:1',
            'tgl_transaksi' => 'nullable|date',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,bmp,png|max:10240',
        ], [
            'no_trx.required' => 'No. Bukti/Transaksi wajib diisi',
            'nama.required' => 'Nama transaksi wajib diisi',
            'jenis_transaksi.required' => 'Jenis transaksi wajib dipilih',
            'jenis_transaksi.in' => 'Jenis transaksi harus IN (Pemasukan) atau OUT (Pengeluaran)',
            'kode_akun.required' => 'Kode akun wajib dipilih',
            'kode_akun.exists' => 'Kode akun tidak ditemukan',
            'nominal.required' => 'Nominal wajib diisi',
            'nominal.integer' => 'Nominal harus berupa angka (tanpa tanda baca)',
            'nominal.min' => 'Nominal minimal 1',
            'file.mimes' => 'File bukti harus berupa PDF atau gambar (JPG, JPEG, BMP, PNG)',
            'file.max' => 'Ukuran file maksimal 10MB',
        ]);
    }

    /**
     * Upload bukti scan — rename {timestamp}{md5}.ext → foto_lapkeuangan/.
     * Update: unlink lama dulu (fix orphan native).
     */
    private function handleUpload(Request $request, KeuTransaksi $trx, bool $isCreate): void
    {
        if (!$request->hasFile('file')) {
            return;
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $fileName = time() . md5($file->getClientOriginalName() . microtime()) . '.' . $ext;

        $dest = public_path(self::UPLOAD_DIR);
        if (!file_exists($dest)) mkdir($dest, 0755, true);

        // Update: unlink bukti lama (fix orphan)
        if (!$isCreate && !empty($trx->file) && !str_starts_with((string) $trx->file, 'http')) {
            $oldFile = public_path(self::UPLOAD_DIR . '/' . $trx->file);
            if (file_exists($oldFile)) @unlink($oldFile);
        }

        $file->move($dest, $fileName);
        $trx->file = $fileName;
    }

    private function transformTrx($row, bool $detail = false): array
    {
        // akun_keterangan bisa dari JOIN index() atau relasi
        $akunKet = $row->akun_keterangan ?? $row->kodeAkun?->keterangan;

        $data = [
            'id' => $row->id,
            'no_trx' => $row->no_trx,
            'nama' => $row->nama,
            'jenis_transaksi' => $row->jenis_transaksi,    // IN | OUT
            'jenis_label' => $row->jenis_transaksi === 'IN' ? 'Pemasukan' : 'Pengeluaran',
            'kode_akun' => $row->kode_akun,
            'akun_keterangan' => $akunKet,
            'nominal' => (int) $row->nominal,
            'nominal_formatted' => $row->nominal_formatted,  // 1.500.000
            // Render kolom debit/kredit (idem native: satu sisi nominal, sisi lain 0)
            'pemasukan' => $row->pemasukan,
            'pengeluaran' => $row->pengeluaran,
            'tgl_transaksi' => $row->tgl_transaksi ? date('Y-m-d', strtotime($row->tgl_transaksi)) : null,
            'tgl_input' => $row->tgl_input ? $row->tgl_input->toDateTimeString() : null,
            'clerk' => $row->clerk,
            'file' => $row->file,
            'file_url' => $row->file_url,
        ];

        return $data;
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Maaf Transaksi dengan ID Tersebut Tidak Ditemukan',
        ], 404);
    }
}
