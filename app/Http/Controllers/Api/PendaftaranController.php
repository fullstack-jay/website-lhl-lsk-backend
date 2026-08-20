<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\StorePendaftaranRequest;
use App\Http\Resources\PendaftaranResource;
use App\Services\PendaftaranService;
use Illuminate\Http\Request;

class PendaftaranController extends ApiController
{
    protected PendaftaranService $service;

    public function __construct(PendaftaranService $service)
    {
        $this->service = $service;
    }

    /**
     * Store new pendaftaran
     * POST /api/pendaftaran
     */
    public function store(StorePendaftaranRequest $request)
    {
        try {
            $result = $this->service->register($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Pendaftaran berhasil! Data Anda telah tersimpan dengan nomor pendaftaran dan password di bawah.',
                'data' => [
                    'no_pendaftaran' => $result['no_pendaftaran'],
                    'nama' => $result['pendaftaran']->nama,
                    'no_hp' => $result['pendaftaran']->no_hp,
                    'email' => $result['pendaftaran']->email,
                    'password' => $result['plain_password'], // 6 digit angka acak
                    'status' => $result['pendaftaran']->status,
                    // Login credentials info
                    'login_info' => [
                        'username' => $result['user']->username, // no_ktp untuk login
                        'no_hp_login' => $result['user']->no_telp, // no_hp juga bisa untuk login
                        'role' => $result['user']->getRoleAttribute(),
                    ],
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses pendaftaran',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get pendaftaran by no_pendaftaran
     * GET /api/pendaftaran/{no_pendaftaran}
     */
    public function show($noPendaftaran)
    {
        $pendaftaran = $this->service->findByNoPendaftaran($noPendaftaran);

        if (!$pendaftaran) {
            return response()->json([
                'success' => false,
                'message' => 'Data pendaftaran tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new PendaftaranResource($pendaftaran),
        ]);
    }

    /**
     * Update status pendaftaran (untuk admin)
     * PUT /api/pendaftaran/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:DIVERIFIKASI,DISETUJUI,DITOLAK',
            'catatan' => 'nullable|string',
        ]);

        try {
            $pendaftaran = $this->service->updateStatus(
                $id,
                $request->status,
                $request->catatan,
                auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Status pendaftaran berhasil diperbarui',
                'data' => new PendaftaranResource($pendaftaran),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all pendaftaran (untuk admin)
     * GET /api/pendaftaran
     */
    public function index(Request $request)
    {
        $filters = [
            'status' => $request->input('status'),
            'search' => $request->input('search'),
        ];

        $perPage = $request->input('per_page', 15);
        $result = $this->service->getPendaftarans($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => PendaftaranResource::collection($result['data']),
            'pagination' => $result['pagination'],
        ]);
    }

    /**
     * Get statistics
     * GET /api/pendaftaran/statistics
     */
    public function statistics()
    {
        $stats = $this->service->getStatistics();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Delete pendaftaran
     * DELETE /api/pendaftaran/{id}
     */
    public function destroy($id)
    {
        try {
            $this->service->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Data pendaftaran berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
