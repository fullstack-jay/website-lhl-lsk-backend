<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AsesiPersyaratanpokok;
use App\Models\Asesi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Pengaturan Dokumen Pokok Peserta (devsyarat) — implementasi modul PHP Native
 * versi API. Sesuai docs/BACKEND_DEVSYARAT.md:
 *
 * KONFIGURASI-ONLY MODULE: TIDAK ada create/delete (7 dokumen = fixture).
 * Hanya 4 handler toggle (pola identik native):
 *   wajibkan      → UPDATE wajib='Y'
 *   tidakwajibkan → UPDATE wajib='N'
 *   aktifkan      → UPDATE aktif='Y'
 *   nonaktifkan   → UPDATE aktif='N'
 * Semua: exist-check dulu → 404 "Maaf Persyaratan Dokumen tersebut Tidak Ditemukan".
 *
 * Perbaikan atas native:
 * - Guard state jebakan (aktif=N + wajib=Y) → nonaktifkan otomatis memaksa wajib='N'
 * - Validasi shortcode ↔ kolom tabel asesi (relasi by-name rawan typo)
 * - Response JSON dengan pesan persis pola native
 */
class DevSyaratController extends Controller
{
    // ════════════════════════════════════════════════════════════════
    // DAFTAR KONFIGURASI (padanan render view devsyarat)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/devsyarat
     * List konfigurasi ORDER BY id (idem native) + audit shortcode valid +
     * flag state jebakan (wajib tapi nonaktif).
     */
    public function index(): JsonResponse
    {
        $list = AsesiPersyaratanpokok::orderBy('id', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $list->map(fn ($p) => $this->transformConfig($p)),
        ]);
    }

    /**
     * Endpoint konsumen publik — dropdown upload dokumen peserta
     * (idem Konsumen 3: SELECT ... WHERE aktif='Y' ORDER BY id).
     */
    public function aktif(): JsonResponse
    {
        $list = AsesiPersyaratanpokok::aktif()->orderBy('id', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $list->map(fn ($p) => [
                'id' => $p->id,
                'persyaratan' => $p->persyaratan,
                'shortcode' => $p->shortcode,
                'sifat_label' => $p->sifat_label,   // "wajib" | "Tambahan (Opsional)"
                'wajib' => $p->wajib,
            ]),
        ]);
    }

    /**
     * Cek kelengkapan dokumen WAJIB satu peserta (idem Konsumen 1 badge
     * "Ada/Belum Ada" — loop shortcode → cek kolom asesi terisi).
     * GET /api/v1/admin/devsyarat/kelengkapan/{noPendaftaran}
     */
    public function kelengkapan($noPendaftaran): JsonResponse
    {
        $asesi = Asesi::where('no_pendaftaran', $noPendaftaran)->first();
        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Peserta tidak ditemukan',
            ], 404);
        }

        $wajib = AsesiPersyaratanpokok::wajib()->orderBy('id', 'asc')->get();

        $dokumen = $wajib->map(function ($p) use ($asesi) {
            $file = $asesi->{$p->shortcode} ?? null;
            return [
                'id' => $p->id,
                'persyaratan' => $p->persyaratan,
                'shortcode' => $p->shortcode,
                'file' => $file,
                'url' => $file ? asset('foto_asesi/' . $file) : null,
                'ada' => !empty($file),     // badge hijau "Ada" / merah "Belum Ada"
            ];
        });

        $ada = $dokumen->where('ada', true)->count();

        return response()->json([
            'success' => true,
            'data' => [
                'no_pendaftaran' => $noPendaftaran,
                'dokumen_wajib' => $dokumen,
                'skor_kelengkapan' => "{$ada}/" . $dokumen->count(),
                'lengkap' => $ada === $dokumen->count(),
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // 4 HANDLER TOGGLE (pola identik native)
    // ════════════════════════════════════════════════════════════════

    /**
     * PUT /api/v1/admin/devsyarat/{id}/wajibkan
     * UPDATE wajib='Y' → "berhasil diubah sebagai PERSYARATAN WAJIB"
     */
    public function wajibkan($id): JsonResponse
    {
        return $this->toggle($id, 'wajib', 'Y', 'berhasil diubah sebagai PERSYARATAN WAJIB');
    }

    /**
     * PUT /api/v1/admin/devsyarat/{id}/tidak-wajibkan
     * UPDATE wajib='N' → "PERSYARATAN TAMBAHAN (OPSIONAL)"
     */
    public function tidakWajibkan($id): JsonResponse
    {
        return $this->toggle($id, 'wajib', 'N', 'diubah menjadi PERSYARATAN TAMBAHAN (OPSIONAL)');
    }

    /**
     * PUT /api/v1/admin/devsyarat/{id}/aktifkan
     * UPDATE aktif='Y' → "DIAKTIFKAN"
     */
    public function aktifkan($id): JsonResponse
    {
        return $this->toggle($id, 'aktif', 'Y', 'DIAKTIFKAN');
    }

    /**
     * PUT /api/v1/admin/devsyarat/{id}/nonaktifkan
     * UPDATE aktif='N' → "DINONAKTIFKAN"
     * ⭐ Guard state jebakan (rekomendasi docs): nonaktifkan dokumen wajib
     *   otomatis memaksa wajib='N' — mencegah state (aktif=N, wajib=Y)
     *   di mana admin cek "Belum Ada" terus tapi peserta tak bisa upload.
     */
    public function nonaktifkan($id): JsonResponse
    {
        $p = AsesiPersyaratanpokok::find($id);
        if (!$p) {
            return $this->notFound();
        }

        $pesanTambahan = '';
        if ($p->wajib === 'Y') {
            $p->wajib = 'N';   // guard state jebakan
            $pesanTambahan = ' (sifat otomatis diubah menjadi Tambahan karena dokumen dinonaktifkan)';
        }

        $p->aktif = 'N';
        $p->save();

        return response()->json([
            'success' => true,
            'message' => 'Dokumen persyaratan DINONAKTIFKAN' . $pesanTambahan,
            'data' => $this->transformConfig($p),
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    /**
     * Toggle generik — pola identik 4 handler native:
     * exist-check → UPDATE flag → pesan.
     */
    private function toggle($id, string $field, string $value, string $pesan): JsonResponse
    {
        $p = AsesiPersyaratanpokok::find($id);
        if (!$p) {
            return $this->notFound();
        }

        $p->{$field} = $value;
        $p->save();

        return response()->json([
            'success' => true,
            'message' => 'Dokumen persyaratan ' . $pesan,
            'data' => $this->transformConfig($p),
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Maaf Persyaratan Dokumen tersebut Tidak Ditemukan',
        ], 404);
    }

    private function transformConfig(AsesiPersyaratanpokok $p): array
    {
        return [
            'id' => $p->id,
            'persyaratan' => $p->persyaratan,
            'shortcode' => $p->shortcode,
            'wajib' => $p->wajib,
            'aktif' => $p->aktif,
            'sifat_label' => $p->sifat_label,       // Wajib | Tambahan (Opsional)
            'aktif_label' => $p->aktif_label,       // Aktif | Tidak Aktif
            // Tombol toggle berlawanan sesuai state (frontend pakai ini):
            'toggle_tersedia' => [
                'wajib' => $p->wajib === 'Y' ? 'tidak-wajibkan' : 'wajibkan',
                'aktif' => $p->aktif === 'Y' ? 'nonaktifkan' : 'aktifkan',
            ],
            // Audit relasi by-name: shortcode harus cocok kolom tabel asesi
            'shortcode_valid' => $p->shortcodeValid(),
            // ⚠️ State jebakan (Common Query #5): wajib tapi nonaktif
            'state_jebakan' => ($p->wajib === 'Y' && $p->aktif === 'N'),
        ];
    }
}
