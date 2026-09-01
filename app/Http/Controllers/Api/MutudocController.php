<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMutudocRequest;
use App\Models\MutudocDoc;
use App\Models\MutudocJenisdoc;
use App\Models\MutudocKategoridoc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MutudocController extends Controller
{
    /**
     * Get list dokumen mutu (Admin/Public)
     * GET /api/v1/mutudoc
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = MutudocDoc::with(['jenisDoc', 'kategoriDoc']);

        // Filter by jenis
        if ($request->has('jenis') && $request->jenis) {
            $query->jenis($request->jenis);
        }

        // Filter by kategori
        if ($request->has('kategori') && $request->kategori) {
            $query->kategori($request->kategori);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $query->search($request->search);
        }

        // Sorting
        $sortBy = $request->sort_by ?? 'id';
        $sortOrder = $request->sort_order ?? 'desc';
        $validSorts = ['id', 'judul', 'tgl_terbit', 'no_dokumen', 'created_at'];

        if (!in_array($sortBy, $validSorts)) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = min((int)($request->per_page ?? 20), 100);
        $page = max(1, (int)($request->page ?? 1));

        $result = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $result->items(),
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
     * Group documents by jenis (for accordion view)
     * GET /api/v1/mutudoc/grouped
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function grouped()
    {
        $jenisList = MutudocJenisdoc::active()->get();

        $result = [];
        foreach ($jenisList as $jenis) {
            $documents = MutudocDoc::with(['kategoriDoc'])
                ->where('jenis', $jenis->id)
                ->orderBy('id', 'desc')
                ->get();

            $result[] = [
                'id' => $jenis->id,
                'jenis' => $jenis->jenis,
                'documents' => $documents,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Get detail dokumen mutu (Admin/Public)
     * GET /api/v1/mutudoc/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $document = MutudocDoc::with(['jenisDoc', 'kategoriDoc'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $document,
        ]);
    }

    /**
     * Create new dokumen mutu (Admin)
     * POST /api/v1/admin/mutudoc
     *
     * @param StoreMutudocRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreMutudocRequest $request)
    {
        // Dup-check 7 field (idem native tambahdocmutudoc; fix: response JSON
        // alih-alih redirect ke module 'indoc' yang tidak ada)
        $duplikat = MutudocDoc::where('jenis', $request->jenis)
            ->where('kategori', $request->kategori)
            ->where('judul', $request->judul)
            ->where('deskripsi', $request->deskripsi)
            ->where('tgl_terbit', $request->tgl_terbit)
            ->where('no_dokumen', $request->no_dokumen)
            ->where('no_revisi', $request->no_revisi ?? 0)
            ->exists();

        if ($duplikat) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Data Tersebut Sudah Ada',
            ], 409);
        }

        try {
            // Handle file upload if exists
            $fileName = null;
            if ($request->hasFile('file')) {
                $file = $request->file('file');

                // Validate file extension
                $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
                $extension = strtolower($file->getClientOriginalExtension());

                if (!in_array($extension, $allowedExtensions)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'File harus berupa PDF atau gambar (JPG, PNG, GIF)',
                    ], 422);
                }

                // Generate unique filename
                $timestamp = time();
                $hash = md5($file->getClientOriginalName() . microtime());
                $fileName = $timestamp . $hash . '.' . $extension;

                // Move file to storage
                $destinationPath = public_path('foto_mutudoc');

                if (!file_exists($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }

                $file->move($destinationPath, $fileName);
            }

            // Create document
            $document = MutudocDoc::create([
                'jenis' => $request->jenis,
                'kategori' => $request->kategori,
                'judul' => $request->judul,
                'deskripsi' => $request->deskripsi,
                'tgl_terbit' => $request->tgl_terbit,
                'no_dokumen' => $request->no_dokumen,
                'no_revisi' => $request->no_revisi ?? 0,
                'penyusun' => $request->penyusun,
                'pengesahan' => $request->pengesahan,
                'file' => $fileName,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Dokumen mutu berhasil ditambahkan',
                'data' => $document->load(['jenisDoc', 'kategoriDoc']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan dokumen',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update dokumen mutu (Admin)
     * PUT/PATCH /api/v1/admin/mutudoc/{id}
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $document = MutudocDoc::findOrFail($id);

            // Handle file upload if exists
            if ($request->hasFile('file')) {
                $file = $request->file('file');

                // Validate file extension
                $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
                $extension = strtolower($file->getClientOriginalExtension());

                if (!in_array($extension, $allowedExtensions)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'File harus berupa PDF atau gambar (JPG, PNG, GIF)',
                    ], 422);
                }

                // Delete old file if exists
                if ($document->file) {
                    $oldFilePath = public_path('foto_mutudoc/' . $document->file);
                    if (file_exists($oldFilePath)) {
                        unlink($oldFilePath);
                    }
                }

                // Generate unique filename
                $timestamp = time();
                $hash = md5($file->getClientOriginalName() . microtime());
                $fileName = $timestamp . $hash . '.' . $extension;

                // Move file to storage
                $destinationPath = public_path('foto_mutudoc');
                $file->move($destinationPath, $fileName);

                $document->file = $fileName;
            }

            // Update fields - check if field exists in request (works with FormData)
            $fillableFields = [
                'jenis', 'kategori', 'judul', 'deskripsi',
                'tgl_terbit', 'no_dokumen', 'no_revisi',
                'penyusun', 'pengesahan'
            ];

            foreach ($fillableFields as $field) {
                // Use input() instead of has() - works better with FormData
                if ($request->input($field) !== null) {
                    $document->$field = $request->input($field);
                }
            }

            $document->save();

            return response()->json([
                'success' => true,
                'message' => 'Dokumen mutu berhasil diperbarui',
                'data' => $document->fresh()->load(['jenisDoc', 'kategoriDoc']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui dokumen',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete dokumen mutu (Admin)
     * DELETE /api/v1/admin/mutudoc/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $document = MutudocDoc::find($id);

            if (!$document) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maaf Dokumen tersebut Tidak Ditemukan',
                ], 404);
            }

            // Delete file (guard: file kosong/file_exists — fix unlink tanpa guard
            // pada dokumen metadata-only di native)
            if (!empty($document->file) && !str_starts_with((string) $document->file, 'http')) {
                $filePath = public_path('foto_mutudoc/' . $document->file);
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            $document->delete();

            return response()->json([
                'success' => true,
                'message' => 'Hapus Data Dokumen Sukses',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus dokumen',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get list jenis dokumen
     * GET /api/v1/mutudoc/jenis
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function jenisList()
    {
        $jenis = MutudocJenisdoc::active()->get();

        return response()->json([
            'success' => true,
            'data' => $jenis,
        ]);
    }

    /**
     * Versi terakhir per nomor dokumen (Common Query #3).
     * Revisi dokumen = baris baru (immutable versioning) — endpoint ini
     * menyaring hanya revisi tertinggi per no_dokumen per jenis.
     *
     * GET /api/v1/mutudoc/versi-terakhir
     */
    public function versiTerakhir()
    {
        $rows = MutudocDoc::with(['jenisDoc', 'kategoriDoc'])
            ->whereNotNull('no_dokumen')
            ->where('no_dokumen', '!=', '')
            ->get()
            ->groupBy(function ($d) {
                return $d->jenis . '|' . $d->no_dokumen;
            })
            ->map(function ($group) {
                return $group->sortByDesc('no_revisi')->first();
            })
            ->values()
            ->sortBy('jenis')
            ->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * Dokumen tanpa berkas / metadata-only (Common Query #4) — daftar
     * dokumen yang perlu di-upload file-nya.
     *
     * GET /api/v1/mutudoc/tanpa-berkas
     */
    public function tanpaBerkas()
    {
        $rows = MutudocDoc::with(['jenisDoc', 'kategoriDoc'])
            ->where(function ($q) {
                $q->whereNull('file')->orWhere('file', '');
            })
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'jumlah' => $rows->count(),
        ]);
    }

    /**
     * Get list kategori dokumen
     * GET /api/v1/mutudoc/kategori
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function kategoriList()
    {
        $kategori = MutudocKategoridoc::all();

        return response()->json([
            'success' => true,
            'data' => $kategori,
        ]);
    }
}
