<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SkemaKkni;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SkemaKkniController extends Controller
{
    /**
     * Get list skema sertifikasi (Admin/Public)
     * GET /api/v1/skema
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = SkemaKkni::query();

        // Filter by aktif status
        if ($request->has('aktif') && $request->aktif) {
            $query->where('aktif', $request->aktif);
        }

        // Filter by jenis skema
        if ($request->has('jenis_skema') && $request->jenis_skema) {
            $query->jenisSkema($request->jenis_skema);
        }

        // Filter by jenjang
        if ($request->has('jenjang') && $request->jenjang !== null) {
            $query->jenjang($request->jenjang);
        }

        // Filter by sektor
        if ($request->has('kode_sektor') && $request->kode_sektor) {
            $query->where('kode_sektor', $request->kode_sektor);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $query->search($request->search);
        }

        // Sorting
        $sortBy = $request->sort_by ?? 'id';
        $sortOrder = $request->sort_order ?? 'asc';
        $validSorts = ['id', 'kode_skema', 'judul', 'jenjang', 'areakerja', 'aktif'];

        if (!in_array($sortBy, $validSorts)) {
            $sortBy = 'id';
        }
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = min((int)($request->per_page ?? 20), 100);
        $page = max(1, (int)($request->page ?? 1));

        $result = $query->paginate($perPage, ['*'], 'page', $page);

        // Add statistics to each item
        $items = collect($result->items())->map(function ($skema) {
            $skema->statistics = $skema->statistics;
            return $skema;
        });

        return response()->json([
            'success' => true,
            'data' => $items,
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
     * Get detail skema sertifikasi (Admin/Public)
     * GET /api/v1/skema/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $skema = SkemaKkni::with([
            'unitKompetensi',
            'persyaratan',
            'persyaratanTuk',
            'skkni'
        ])->findOrFail($id);

        $skema->statistics = $skema->statistics;

        return response()->json([
            'success' => true,
            'data' => $skema,
        ]);
    }

    /**
     * Create new skema sertifikasi (Admin)
     * POST /api/v1/admin/skema
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kode_skema' => 'required|string|max:100|unique:skema_kkni,kode_skema',
            'judul' => 'required|string|max:255',
            'judul_eng' => 'nullable|string|max:255',
            'areakerja' => 'required|string|max:255',
            'areakerja_eng' => 'nullable|string|max:255',
            'kode_sektor' => 'nullable|string|max:50',
            'kbli' => 'nullable|string|max:50',
            'kbji' => 'nullable|string|max:50',
            'jenjang' => 'nullable|integer|min:1|max:9',
            'keterangan_bukti' => 'nullable|string',
            'apl02' => 'nullable|in:elemen,KUK',
            'jenis_skema' => 'nullable|in:Okupasi,KKNI,Klaster',
            'skema_induk' => 'nullable|integer|exists:skema_kkni,id',
            'kodeskema_bnsp' => 'nullable|string|max:100',
            'aktif' => 'nullable|in:Y,N',
            'id_skkni' => 'nullable|integer|exists:skkni,id',
            'file' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Handle file upload if exists
            $fileName = null;
            if ($request->hasFile('file')) {
                $file = $request->file('file');

                // Validate file extension
                $allowedExtensions = ['pdf', 'doc', 'docx'];
                $extension = strtolower($file->getClientOriginalExtension());

                if (!in_array($extension, $allowedExtensions)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'File harus berupa PDF, DOC, atau DOCX',
                    ], 422);
                }

                // Generate unique filename
                $timestamp = time();
                $hash = md5($file->getClientOriginalName() . microtime());
                $fileName = $timestamp . $hash . '.' . $extension;

                // Move file to storage
                $destinationPath = public_path('foto_skema');

                if (!file_exists($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }

                $file->move($destinationPath, $fileName);
            }

            // Create skema sertifikasi
            $skema = SkemaKkni::create([
                'kode_skema' => strip_tags(trim($request->kode_skema)),
                'judul' => strip_tags(trim($request->judul)),
                'judul_eng' => $request->filled('judul_eng') ? strip_tags(trim($request->judul_eng)) : null,
                'areakerja' => strip_tags(trim($request->areakerja)),
                'areakerja_eng' => $request->filled('areakerja_eng') ? strip_tags(trim($request->areakerja_eng)) : null,
                'kode_sektor' => $request->filled('kode_sektor') ? strip_tags(trim($request->kode_sektor)) : null,
                'kbli' => $request->filled('kbli') ? strip_tags(trim($request->kbli)) : null,
                'kbji' => $request->filled('kbji') ? strip_tags(trim($request->kbji)) : null,
                'jenjang' => $request->jenjang,
                'keterangan_bukti' => $request->filled('keterangan_bukti') ? $request->keterangan_bukti : null,
                'apl02' => $request->filled('apl02') ? $request->apl02 : null,
                'jenis_skema' => $request->filled('jenis_skema') ? $request->jenis_skema : null,
                'skema_induk' => $request->filled('skema_induk') ? $request->skema_induk : null,
                'kodeskema_bnsp' => $request->filled('kodeskema_bnsp') ? strip_tags(trim($request->kodeskema_bnsp)) : null,
                'aktif' => $request->filled('aktif') ? $request->aktif : 'Y',
                'id_skkni' => $request->filled('id_skkni') ? $request->id_skkni : null,
                'file' => $fileName,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Skema sertifikasi berhasil ditambahkan',
                'data' => $skema,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan skema sertifikasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update skema sertifikasi (Admin)
     * POST /api/v1/admin/skema/{id}?_method=PUT
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $skema = SkemaKkni::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'kode_skema' => 'nullable|string|max:100|unique:skema_kkni,kode_skema,' . $id,
            'judul' => 'nullable|string|max:255',
            'judul_eng' => 'nullable|string|max:255',
            'areakerja' => 'nullable|string|max:255',
            'areakerja_eng' => 'nullable|string|max:255',
            'kode_sektor' => 'nullable|string|max:50',
            'kbli' => 'nullable|string|max:50',
            'kbji' => 'nullable|string|max:50',
            'jenjang' => 'nullable|integer|min:1|max:9',
            'keterangan_bukti' => 'nullable|string',
            'apl02' => 'nullable|in:elemen,KUK',
            'jenis_skema' => 'nullable|in:Okupasi,KKNI,Klaster',
            'skema_induk' => 'nullable|integer|exists:skema_kkni,id',
            'kodeskema_bnsp' => 'nullable|string|max:100',
            'aktif' => 'nullable|in:Y,N',
            'id_skkni' => 'nullable|integer|exists:skkni,id',
            'file' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Handle file upload if exists
            if ($request->hasFile('file')) {
                $file = $request->file('file');

                // Validate file extension
                $allowedExtensions = ['pdf', 'doc', 'docx'];
                $extension = strtolower($file->getClientOriginalExtension());

                if (!in_array($extension, $allowedExtensions)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'File harus berupa PDF, DOC, atau DOCX',
                    ], 422);
                }

                // Delete old file if exists
                if ($skema->file) {
                    $oldFilePath = public_path('foto_skema/' . $skema->file);
                    if (file_exists($oldFilePath)) {
                        unlink($oldFilePath);
                    }
                }

                // Generate unique filename
                $timestamp = time();
                $hash = md5($file->getClientOriginalName() . microtime());
                $fileName = $timestamp . $hash . '.' . $extension;

                // Move file to storage
                $destinationPath = public_path('foto_skema');
                $file->move($destinationPath, $fileName);

                $skema->file = $fileName;
            }

            // Update fields (use input() for FormData compatibility)
            $updateData = [];
            $fields = [
                'kode_skema', 'judul', 'judul_eng', 'areakerja', 'areakerja_eng',
                'kode_sektor', 'kbli', 'kbji', 'jenjang', 'keterangan_bukti',
                'apl02', 'jenis_skema', 'skema_induk', 'kodeskema_bnsp', 'aktif', 'id_skkni'
            ];

            foreach ($fields as $field) {
                if ($request->input($field) !== null) {
                    $updateData[$field] = in_array($field, ['kode_skema', 'judul', 'areakerja', 'kode_sektor', 'kbli', 'kbji', 'kodeskema_bnsp'])
                        ? strip_tags(trim($request->input($field)))
                        : $request->input($field);
                }
            }

            if (!empty($updateData)) {
                $skema->update($updateData);
            }

            if (isset($fileName)) {
                $skema->file = $fileName;
                $skema->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Skema sertifikasi berhasil diperbarui',
                'data' => $skema->fresh(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui skema sertifikasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete skema sertifikasi (Admin)
     * DELETE /api/v1/admin/skema/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $skema = SkemaKkni::findOrFail($id);

            // Check if there are related data
            if ($skema->unitKompetensi()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat menghapus skema yang masih memiliki unit kompetensi',
                ], 400);
            }

            if ($skema->persyaratan()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat menghapus skema yang masih memiliki persyaratan',
                ], 400);
            }

            if ($skema->persyaratanTuk()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat menghapus skema yang masih memiliki persyaratan TUK',
                ], 400);
            }

            // Delete file if exists
            if ($skema->file) {
                $filePath = public_path('foto_skema/' . $skema->file);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            // Delete record
            $skema->delete();

            return response()->json([
                'success' => true,
                'message' => 'Skema sertifikasi berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus skema sertifikasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle active status (Admin)
     * PUT /api/v1/admin/skema/{id}/toggle
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleActive($id)
    {
        try {
            $skema = SkemaKkni::findOrFail($id);
            $skema->aktif = $skema->aktif === 'Y' ? 'N' : 'Y';
            $skema->save();

            return response()->json([
                'success' => true,
                'message' => 'Status skema berhasil diperbarui',
                'data' => $skema,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui status skema',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get statistics for all skema (Admin)
     * GET /api/v1/admin/skema/statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics()
    {
        try {
            $totalSkema = SkemaKkni::count();
            $skemaAktif = SkemaKkni::where('aktif', 'Y')->count();
            $skemaNonAktif = SkemaKkni::where('aktif', 'N')->count();

            // Jenjang statistics
            $jenjangStats = [];
            for ($i = 1; $i <= 9; $i++) {
                $jenjangStats['KKNI ' . $i] = SkemaKkni::where('jenjang', $i)->count();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'total_skema' => $totalSkema,
                    'skema_aktif' => $skemaAktif,
                    'skema_non_aktif' => $skemaNonAktif,
                    'by_jenjang' => $jenjangStats,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil statistik',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get list options for dropdown
     * GET /api/v1/skema/options
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function options()
    {
        $skema = SkemaKkni::active()
            ->orderBy('kode_skema', 'asc')
            ->get(['id', 'kode_skema', 'judul', 'jenjang']);

        return response()->json([
            'success' => true,
            'data' => $skema->map(function ($item) {
                return [
                    'value' => $item->id,
                    'label' => $item->kode_skema . ' - ' . $item->judul,
                    'jenjang' => $item->jenjang,
                ];
            }),
        ]);
    }
}
