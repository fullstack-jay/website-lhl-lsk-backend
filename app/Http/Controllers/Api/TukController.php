<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tuk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TukController extends Controller
{
    /**
     * Get all TUK with pagination
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Tuk::with(['wilayah']);

        // Search
        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Filter by active lisensi
        if ($request->has('lisensi_active')) {
            if ($request->lisensi_active === 'true') {
                $query->active();
            }
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'id');
        $sortOrder = $request->get('sort_order', 'asc');

        if (!in_array($sortBy, ['id', 'nama', 'kode_tuk', 'masa_berlaku'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = min((int) $request->get('per_page', 20), 100);
        $page = (int) $request->get('page', 1);

        $tuk = $query->paginate($perPage, ['*'], 'page', $page);

        // Transform data
        $data = $tuk->map(function ($item) {
            return [
                'id' => $item->id,
                'kode_tuk' => $item->kode_tuk,
                'id_tuk_bnsp' => $item->id_tuk_bnsp,
                'nama' => $item->nama,
                'penanggungjawab' => $item->penanggungjawab,
                'jenis_tuk' => $item->jenis_tuk,
                'lsp_induk' => $item->lsp_induk,
                'institusi_induk' => $item->institusi_induk,
                'alamat' => $item->alamat,
                'kelurahan' => $item->kelurahan,
                'kodepos' => $item->kodepos,
                'telepon' => $item->telepon,
                'email' => $item->email,
                'fax' => $item->fax,
                'no_lisensi' => $item->no_lisensi,
                'masa_berlaku' => $item->masa_berlaku ? $item->masa_berlaku->format('Y-m-d') : null,
                'lisensi_active' => $item->isLisensiActive(),
                'full_address' => $item->full_address,
                'wilayah' => $item->wilayah ? [
                    'id_wil' => $item->wilayah->id_wil,
                    'nm_wil' => $item->wilayah->nm_wil,
                    'id_level_wil' => $item->wilayah->id_level_wil,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $tuk->currentPage(),
                'per_page' => $tuk->perPage(),
                'total' => $tuk->total(),
                'last_page' => $tuk->lastPage(),
                'from' => $tuk->firstItem(),
                'to' => $tuk->lastItem(),
            ],
        ]);
    }

    /**
     * Get TUK detail by ID
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $tuk = Tuk::with(['wilayah'])->find($id);

        if (!$tuk) {
            return response()->json([
                'success' => false,
                'message' => 'TUK tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $tuk->id,
                'kode_tuk' => $tuk->kode_tuk,
                'id_tuk_bnsp' => $tuk->id_tuk_bnsp,
                'nama' => $tuk->nama,
                'penanggungjawab' => $tuk->penanggungjawab,
                'jenis_tuk' => $tuk->jenis_tuk,
                'lsp_induk' => $tuk->lsp_induk,
                'institusi_induk' => $tuk->institusi_induk,
                'alamat' => $tuk->alamat,
                'kelurahan' => $tuk->kelurahan,
                'kodepos' => $tuk->kodepos,
                'telepon' => $tuk->telepon,
                'email' => $tuk->email,
                'fax' => $tuk->fax,
                'tgl_pendirian' => $tuk->tgl_pendirian,
                'no_lisensi' => $tuk->no_lisensi,
                'masa_berlaku' => $tuk->masa_berlaku ? $tuk->masa_berlaku->format('Y-m-d') : null,
                'id_skkni' => $tuk->id_skkni,
                'lisensi_active' => $tuk->isLisensiActive(),
                'full_address' => $tuk->full_address,
                'wilayah' => $tuk->wilayah ? [
                    'id_wil' => $tuk->wilayah->id_wil,
                    'nm_wil' => $tuk->wilayah->nm_wil,
                    'id_level_wil' => $tuk->wilayah->id_level_wil,
                ] : null,
            ],
        ]);
    }

    /**
     * Get TUK options for dropdown
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function options()
    {
        $tuk = Tuk::active()
            ->orderBy('nama')
            ->get(['id', 'nama', 'kode_tuk']);

        return response()->json([
            'success' => true,
            'data' => $tuk->map(function ($item) {
                return [
                    'value' => $item->id,
                    'label' => $item->kode_tuk ? "{$item->kode_tuk} - {$item->nama}" : $item->nama,
                ];
            }),
        ]);
    }

    /**
     * Get TUK statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics()
    {
        $total = Tuk::count();
        $active = Tuk::active()->count();
        $expired = Tuk::where('masa_berlaku', '<', now()->startOfDay())->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'active' => $active,
                'expired' => $expired,
            ],
        ]);
    }
}
