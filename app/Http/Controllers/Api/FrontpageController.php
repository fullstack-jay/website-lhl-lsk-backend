<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Frontpage;
use App\Models\FrontpageKategori;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Konten Frontpage Website LSK — implementasi modul `konten` PHP Native versi API.
 * Sesuai docs/BACKEND_KONTENFRONTKONTENFRONTPAGE.md:
 *
 * ADMIN:
 * - List + filter kategori + search (ORDER BY tanggal_terbit DESC)
 * - Create: dup-check 4 field (judul, sub_judul, konten, kategori) → upload foto
 * - Update: exist-check + ganti foto (unlink lama — fix bug $rowAgen native)
 * - Delete: unlink file + DELETE row (fix orphan file native)
 *
 * PUBLIK:
 * - GET /frontpage/{slug} — konten per section untuk render halaman depan
 *   (kategori slug = kontrak frontend).
 *
 * Perbaikan atas native: transaction, unlink saat delete, pesan validasi jelas,
 * penanganan zero-date tanggal_terbit (NULL-safe).
 */
class FrontpageController extends Controller
{
    /** Direktori upload gambar konten. */
    private const UPLOAD_DIR = 'foto_konten';

    // ════════════════════════════════════════════════════════════════
    // ADMIN — DAFTAR KONTEN (padanan module konten)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/konten?kategori={id}&search=&page=&per_page=
     * List semua konten + label kategori, ORDER BY tanggal_terbit DESC (idem native).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Frontpage::query()
            ->join('frontpage_kategori as fk', 'fk.id', '=', 'frontpage.kategori')
            ->select('frontpage.*', 'fk.kategori as kategori_slug')
            ->orderByRaw('frontpage.tanggal_terbit IS NULL DESC, frontpage.tanggal_terbit DESC');

        // Filter kategori (dropdown section)
        if ($request->filled('kategori')) {
            $query->where('frontpage.kategori', $request->kategori);
        }

        // Search judul/sub_judul/konten
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('frontpage.judul', 'like', "%{$s}%")
                    ->orWhere('frontpage.sub_judul', 'like', "%{$s}%")
                    ->orWhere('frontpage.konten', 'like', "%{$s}%");
            });
        }

        $perPage = min((int) ($request->per_page ?? 20), 100);
        $result = $query->paginate($perPage);

        $data = collect($result->items())->map(fn ($row) => $this->transformKonten($row));

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
     * GET /api/v1/admin/konten/kategori — daftar kategori (dropdown form) + rekap jumlah.
     * Padanan Common Query #3 (rekap per section) & #5 (section kosong).
     */
    public function kategori(): JsonResponse
    {
        $rows = DB::table('frontpage_kategori as k')
            ->leftJoin('frontpage as f', 'f.kategori', '=', 'k.id')
            ->selectRaw('k.id, k.kategori, COUNT(f.id) as jumlah_konten')
            ->groupBy('k.id', 'k.kategori')
            ->orderBy('k.id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'slug' => $r->kategori,
                'jumlah_konten' => (int) $r->jumlah_konten,
                'kosong' => (int) $r->jumlah_konten === 0,   // section belum ada konten
            ]),
        ]);
    }

    /**
     * GET /api/v1/admin/konten/{id}
     * Detail lengkap untuk pre-fill form edit (padanan LOAD updatekonten).
     */
    public function show($id): JsonResponse
    {
        $konten = Frontpage::find($id);
        if (!$konten) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Konten tersebut Tidak Ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->transformKonten($konten, true),
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // ADMIN — CREATE (padanan module tambahkonten)
    // ════════════════════════════════════════════════════════════════

    /**
     * POST /api/v1/admin/konten  (multipart/form-data didukung)
     *
     * Dup-check 4 field (judul, sub_judul, konten, kategori) → 409;
     * upload gambar jpg/png/gif/jpeg/webp → rename timestamp.md5.ext.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'judul' => 'nullable|string',
            'sub_judul' => 'nullable|string',
            'konten' => 'nullable|string',
            'kategori' => 'required|integer|exists:frontpage_kategori,id',
            'tanggal_terbit' => 'nullable|date',
            'waktu_terbit' => 'nullable|date_format:H:i:s,H:i',

            // Upload gambar
            'file' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
        ], [
            'kategori.required' => 'Kategori wajib dipilih',
            'kategori.exists' => 'Kategori tidak ditemukan',
            'file.image' => 'File harus berupa gambar',
            'file.mimes' => 'Gambar harus berformat jpg/png/gif/jpeg/webp',
            'file.max' => 'Ukuran gambar maksimal 5MB',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Dup-check 4 field (idem native tambahkonten)
        $duplikat = Frontpage::where('judul', $request->judul)
            ->where('sub_judul', $request->sub_judul)
            ->where('konten', $request->konten)
            ->where('kategori', $request->kategori)
            ->exists();

        if ($duplikat) {
            return response()->json([
                'success' => false,
                'message' => 'Konten dengan judul tersebut sudah ada',
            ], 409);
        }

        DB::beginTransaction();
        try {
            $konten = new Frontpage([
                'judul' => $request->judul,
                'sub_judul' => $request->sub_judul,
                'konten' => $request->konten,
                'kategori' => $request->kategori,
                // Zero-date guard: tanggal kosong → NULL (bukan '0000-00-00' — crash MySQL 8 strict)
                'tanggal_terbit' => $request->filled('tanggal_terbit') ? $request->tanggal_terbit : null,
                'waktu_terbit' => $request->filled('waktu_terbit') ? $request->waktu_terbit : null,
            ]);
            $konten->save();

            // Upload foto (jika ada)
            $this->handleUpload($request, $konten, isCreate: true);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Konten berhasil ditambahkan',
                'data' => $this->transformKonten($konten->fresh()),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan konten',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // ADMIN — UPDATE (padanan module updatekonten&id={id})
    // ════════════════════════════════════════════════════════════════

    /**
     * PUT/POST /api/v1/admin/konten/{id}  (multipart/form-data didukung)
     *
     * Ganti foto = unlink lama + upload baru (fix bug $rowAgen native yang
     * unlink salah variabel — file lama jadi orphan).
     */
    public function update(Request $request, $id): JsonResponse
    {
        $konten = Frontpage::find($id);
        if (!$konten) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Konten tersebut Tidak Ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'judul' => 'nullable|string',
            'sub_judul' => 'nullable|string',
            'konten' => 'nullable|string',
            'kategori' => 'nullable|integer|exists:frontpage_kategori,id',
            'tanggal_terbit' => 'nullable|date',
            'waktu_terbit' => 'nullable|date_format:H:i:s,H:i',

            'file' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
        ], [
            'kategori.exists' => 'Kategori tidak ditemukan',
            'file.image' => 'File harus berupa gambar',
            'file.mimes' => 'Gambar harus berformat jpg/png/gif/jpeg/webp',
            'file.max' => 'Ukuran gambar maksimal 5MB',
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
            // Update field yang dikirim saja
            foreach (['judul', 'sub_judul', 'konten', 'kategori'] as $field) {
                if ($request->has($field)) {
                    $konten->{$field} = $request->input($field);
                }
            }
            // Zero-date guard: kosong → NULL
            foreach (['tanggal_terbit', 'waktu_terbit'] as $field) {
                if ($request->has($field)) {
                    $val = $request->input($field);
                    $konten->{$field} = ($val === '' || $val === null) ? null : $val;
                }
            }

            // Ganti foto (jika ada upload baru)
            $this->handleUpload($request, $konten, isCreate: false);

            $konten->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Konten berhasil diperbarui',
                'data' => $this->transformKonten($konten->fresh()),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui konten',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // ADMIN — DELETE (padanan handler hapuskonten)
    // ════════════════════════════════════════════════════════════════

    /**
     * DELETE /api/v1/admin/konten/{id}
     * Perbaikan atas native: unlink gambar dulu (dengan file_exists guard),
     * baru hapus row — tidak ada orphan file.
     */
    public function destroy($id): JsonResponse
    {
        $konten = Frontpage::find($id);
        if (!$konten) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Konten tersebut Tidak Ditemukan',
            ], 404);
        }

        try {
            // Unlink file (guard URL eksternal & file_exists — idem pola asesi-baru)
            if (!empty($konten->konten_foto) && !str_starts_with((string) $konten->konten_foto, 'http')) {
                $abs = public_path(self::UPLOAD_DIR . '/' . $konten->konten_foto);
                if (file_exists($abs)) {
                    @unlink($abs);
                }
            }

            $konten->delete();

            return response()->json([
                'success' => true,
                'message' => 'Hapus Data Sukses',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus konten',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // PUBLIK — KONTEN PER SECTION (konsumsi halaman depan)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/frontpage/{slug}?limit=N
     * Padanan Common Query #2 — frontend publik menarik konten per section
     * via slug kategori (slidebanner, welcome, layanan, berita, faq, dst).
     * Urutan: tanggal_terbit DESC lalu waktu_terbit DESC.
     */
    public function byKategoriSlug(Request $request, $slug): JsonResponse
    {
        $kategori = FrontpageKategori::where('kategori', $slug)->first();
        if (!$kategori) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori section tidak ditemukan',
            ], 404);
        }

        $limit = min((int) ($request->limit ?? 50), 200);

        $rows = Frontpage::where('kategori', $kategori->id)
            ->orderByRaw('tanggal_terbit IS NULL DESC, tanggal_terbit DESC, waktu_terbit DESC')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'section' => $kategori->kategori,
                'jumlah' => $rows->count(),
                'konten' => $rows->map(fn ($row) => $this->transformKonten($row)),
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    /**
     * Handle upload gambar konten (create & update).
     * Rename: {unix_timestamp}{md5(nama+mikrotime)}.ext → foto_konten/.
     * Update: unlink file lama dulu (fix bug $rowAgen native).
     */
    private function handleUpload(Request $request, Frontpage $konten, bool $isCreate): void
    {
        if (!$request->hasFile('file')) {
            return;
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $fileName = time() . md5($file->getClientOriginalName() . microtime()) . '.' . $ext;

        $dest = public_path(self::UPLOAD_DIR);
        if (!file_exists($dest)) {
            mkdir($dest, 0755, true);
        }

        // Update mode: hapus file lama (dengan guard) — fix bug native $rowAgen
        if (!$isCreate && !empty($konten->konten_foto) && !str_starts_with((string) $konten->konten_foto, 'http')) {
            $oldFile = public_path(self::UPLOAD_DIR . '/' . $konten->konten_foto);
            if (file_exists($oldFile)) {
                @unlink($oldFile);
            }
        }

        $file->move($dest, $fileName);
        $konten->konten_foto = $fileName;
    }

    /**
     * Transform baris konten menjadi payload (dipakai admin & publik).
     */
    private function transformKonten($row, bool $detail = false): array
    {
        $kategoriSlug = null;
        if (!empty($row->kategori_slug)) {
            $kategoriSlug = $row->kategori_slug;
        } elseif ($row->relationLoaded('kategoriRef') && $row->kategoriRef) {
            $kategoriSlug = $row->kategoriRef->kategori;
        } elseif ($row->kategori) {
            $kategoriSlug = FrontpageKategori::find($row->kategori)?->kategori;
        }

        // Zero-date / NULL-safe tanggal (fix "31 Desember 1969" native)
        $tanggal = null;
        if (!empty($row->tanggal_terbit) && $row->tanggal_terbit !== '0000-00-00') {
            $tanggal = date('Y-m-d', strtotime($row->tanggal_terbit));
        }

        $data = [
            'id' => $row->id,
            'judul' => $row->judul,
            'sub_judul' => $row->sub_judul,
            'konten' => $row->konten,
            'kategori' => (int) $row->kategori,
            'kategori_slug' => $kategoriSlug,
            'konten_foto' => $row->konten_foto,
            'konten_foto_url' => $row->konten_foto ? asset(self::UPLOAD_DIR . '/' . $row->konten_foto) : null,
            'tanggal_terbit' => $tanggal,
            'waktu_terbit' => $row->waktu_terbit ? substr((string) $row->waktu_terbit, 0, 5) : null,
            'preview_konten' => $row->preview_konten,   // 300 char strip-tags
        ];

        return $data;
    }
}
