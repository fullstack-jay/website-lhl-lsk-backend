<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSkkniRequest;
use App\Models\Skkni;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Str;

class SkkniController extends Controller
{
    /**
     * Get list standar kompetensi (Admin/Public)
     * GET /api/v1/skkni
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Skkni::query();

        // Filter by jenis standar
        if ($request->has('jenis_standar') && $request->jenis_standar) {
            $query->jenisStandar($request->jenis_standar);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $query->search($request->search);
        }

        // Sorting
        $sortBy = $request->sort_by ?? 'id';
        $sortOrder = $request->sort_order ?? 'asc';
        $validSorts = ['id', 'nama', 'no_skkni', 'jenis_standar', 'sektor'];

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
     * Get detail standar kompetensi (Admin/Public)
     * GET /api/v1/skkni/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $skkni = Skkni::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $skkni,
        ]);
    }

    /**
     * Create new standar kompetensi (Admin)
     * POST /api/v1/admin/skkni
     *
     * @param StoreSkkniRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreSkkniRequest $request)
    {
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
                $destinationPath = public_path('foto_skkni');

                if (!file_exists($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }

                $file->move($destinationPath, $fileName);
            }

            // Create standar kompetensi
            $skkni = Skkni::create([
                'no_skkni' => $request->no_skkni,
                'nama' => $request->nama,
                'jenis_standar' => $request->jenis_standar,
                'sektor' => $request->sektor,
                'subsektor' => $request->subsektor,
                'legalitas' => $request->legalitas,
                'penyusun' => $request->penyusun,
                'file' => $fileName,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Standar kompetensi berhasil ditambahkan',
                'data' => $skkni,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan standar kompetensi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update standar kompetensi (Admin)
     * PUT/PATCH /api/v1/admin/skkni/{id}
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $skkni = Skkni::findOrFail($id);

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
                if ($skkni->file) {
                    $oldFilePath = public_path('foto_skkni/' . $skkni->file);
                    if (file_exists($oldFilePath)) {
                        unlink($oldFilePath);
                    }
                }

                // Generate unique filename
                $timestamp = time();
                $hash = md5($file->getClientOriginalName() . microtime());
                $fileName = $timestamp . $hash . '.' . $extension;

                // Move file to storage
                $destinationPath = public_path('foto_skkni');
                $file->move($destinationPath, $fileName);

                $skkni->file = $fileName;
            }

            // Update fields (use input() for FormData compatibility)
            $fillableFields = [
                'no_skkni', 'nama', 'jenis_standar',
                'sektor', 'subsektor', 'legalitas', 'penyusun'
            ];

            foreach ($fillableFields as $field) {
                if ($request->input($field) !== null) {
                    $skkni->$field = $request->input($field);
                }
            }

            $skkni->save();

            return response()->json([
                'success' => true,
                'message' => 'Standar kompetensi berhasil diperbarui',
                'data' => $skkni->fresh(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui standar kompetensi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete standar kompetensi (Admin)
     * DELETE /api/v1/admin/skkni/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $skkni = Skkni::findOrFail($id);

            // Delete file if exists
            if ($skkni->file) {
                $filePath = public_path('foto_skkni/' . $skkni->file);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            // Delete record
            $skkni->delete();

            return response()->json([
                'success' => true,
                'message' => 'Standar kompetensi berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus standar kompetensi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get list jenis standar
     * GET /api/v1/skkni/jenis
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function jenisList()
    {
        $jenis = [
            ['value' => 'SKKNI', 'label' => 'SKKNI - Standar Kompetensi Kerja Nasional Indonesia'],
            ['value' => 'SKK', 'label' => 'SKK - Standar Kompetensi Khusus'],
            ['value' => 'SI', 'label' => 'SI - Standar Internasional'],
        ];

        return response()->json([
            'success' => true,
            'data' => $jenis,
        ]);
    }
}
