<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterKeahlian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterKeahlianController extends Controller
{
    /**
     * GET /api/v1/keahlian-penyusun
     */
    public function index(): JsonResponse
    {
        $list = MasterKeahlian::query()
            ->orderBy('is_default', 'desc')
            ->orderBy('nama', 'asc')
            ->get(['id', 'nama', 'is_default']);

        return response()->json([
            'success' => true,
            'data' => $list,
        ]);
    }

    /**
     * POST /api/v1/keahlian-penyusun
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'nama' => 'required|string|max:150',
        ]);

        $formatted = MasterKeahlian::recordIfNew($request->nama);

        return response()->json([
            'success' => true,
            'message' => 'Keahlian berhasil disimpan',
            'data' => [
                'nama' => $formatted,
            ],
        ], 201);
    }
}
