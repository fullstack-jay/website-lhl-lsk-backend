<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SkemaMapa1a;
use App\Models\SkemaMapa1b;
use App\Models\SkemaMapa2;
use App\Models\SkemaKkni;
use App\Models\KategoriKandidat;
use App\Models\Muk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MapaController extends Controller
{
    // ==================== MAPA 1A - Pendekatan Asesmen ====================

    /**
     * Get MAPA 1A by skema and profil kandidat (Admin/Public)
     * GET /api/v1/skema/{id}/mapa1a/{profil}
     *
     * @param int $skemaId
     * @param int $profil
     * @return \Illuminate\Http\JsonResponse
     */
    public function showMapa1a($skemaId, $profil = 1)
    {
        $skema = SkemaKkni::findOrFail($skemaId);
        $mapa = SkemaMapa1a::bySkema($skemaId)
            ->byProfil($profil)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'skema' => $skema,
                'profil_kandidat' => $profil,
                'mapa1a' => $mapa,
            ],
        ]);
    }

    /**
     * Store or update MAPA 1A (Admin)
     * POST /api/v1/admin/skema/{id}/mapa1a
     *
     * @param Request $request
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeMapa1a(Request $request, $skemaId)
    {
        $skema = SkemaKkni::findOrFail($skemaId);

        $validator = Validator::make($request->all(), [
            'profil_kandidat' => 'required|integer|min:1|max:5',
            'pendekatan' => 'nullable|in:1,2,3',
            'pendekatan_ket' => 'nullable|string',
            'tujuan' => 'nullable|in:1,2,3,4,5',
            'tujuanket' => 'nullable|string',
            'konteks_a' => 'nullable|in:1,2',
            'konteks_b' => 'nullable|in:1,2',
            'konteks_c1' => 'nullable|in:1,0',
            'konteks_c2' => 'nullable|in:2,0',
            'konteks_c3' => 'nullable|in:3,0',
            'konteks_d' => 'nullable|in:1,2,3',
            'konfirmasi1' => 'nullable|string',
            'konfirmasi2' => 'nullable|string',
            'konfirmasi3' => 'nullable|string',
            'konfirmasi4' => 'nullable|string',
            'konfirmasi4_ket' => 'nullable|string',
            'toluk1' => 'nullable|string',
            'toluk2' => 'nullable|string',
            'toluk3' => 'nullable|string',
            'toluk4' => 'nullable|string',
            'toluk5' => 'nullable|string',
            'toluk1_ket' => 'nullable|string',
            'toluk2_ket' => 'nullable|string',
            'toluk3_ket' => 'nullable|string',
            'toluk4_ket' => 'nullable|string',
            'toluk5_ket' => 'nullable|string',
            'konfirm1' => 'nullable|string',
            'konfirm2' => 'nullable|string',
            'konfirm3' => 'nullable|string',
            'konfirm4' => 'nullable|string',
            'konfirm1_ket' => 'nullable|string',
            'konfirm2_ket' => 'nullable|string',
            'konfirm3_ket' => 'nullable|string',
            'konfirm4_ket' => 'nullable|string',
            'modkon1a' => 'nullable|string',
            'modkon1b' => 'nullable|string',
            'modkon1a_ket' => 'nullable|string',
            'modkon1b_ket' => 'nullable|string',
            'modkon2' => 'nullable|string',
            'modkon3' => 'nullable|string',
            'modkon4' => 'nullable|string',
            'modkon2_ket' => 'nullable|string',
            'modkon3_ket' => 'nullable|string',
            'modkon4_ket' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $profil = $request->profil_kandidat;

            // Check if exists
            $mapa = SkemaMapa1a::bySkema($skemaId)
                ->byProfil($profil)
                ->first();

            $data = [
                'id_skemakkni' => $skemaId,
                'profil_kandidat' => $profil,
            ];

            // Add all fields from request
            $fields = [
                'pendekatan', 'pendekatan_ket', 'tujuan', 'tujuanket',
                'konteks_a', 'konteks_b', 'konteks_c1', 'konteks_c2', 'konteks_c3', 'konteks_d',
                'konfirmasi1', 'konfirmasi2', 'konfirmasi3', 'konfirmasi4', 'konfirmasi4_ket',
                'toluk1', 'toluk2', 'toluk3', 'toluk4', 'toluk5',
                'toluk1_ket', 'toluk2_ket', 'toluk3_ket', 'toluk4_ket', 'toluk5_ket',
                'konfirm1', 'konfirm2', 'konfirm3', 'konfirm4',
                'konfirm1_ket', 'konfirm2_ket', 'konfirm3_ket', 'konfirm4_ket',
                'modkon1a', 'modkon1b', 'modkon1a_ket', 'modkon1b_ket',
                'modkon2', 'modkon3', 'modkon4',
                'modkon2_ket', 'modkon3_ket', 'modkon4_ket',
            ];

            foreach ($fields as $field) {
                if ($request->input($field) !== null) {
                    $data[$field] = $request->input($field);
                }
            }

            if ($mapa) {
                $mapa->update($data);
                $message = 'MAPA 1A berhasil diperbarui';
            } else {
                $mapa = SkemaMapa1a::create($data);
                $message = 'MAPA 1A berhasil ditambahkan';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $mapa,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan MAPA 1A',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete MAPA 1A (Admin)
     * DELETE /api/v1/admin/skema/{id}/mapa1a/{profil}
     *
     * @param int $skemaId
     * @param int $profil
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyMapa1a($skemaId, $profil)
    {
        try {
            $mapa = SkemaMapa1a::bySkema($skemaId)
                ->byProfil($profil)
                ->first();

            if (!$mapa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data MAPA 1A tidak ditemukan',
                ], 404);
            }

            $mapa->delete();

            return response()->json([
                'success' => true,
                'message' => 'MAPA 1A berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus MAPA 1A',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== MAPA 1B - Perangkat Asesmen per Unit ====================

    /**
     * Get MAPA 1B by skema (Admin/Public)
     * GET /api/v1/skema/{id}/mapa1b
     *
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function showMapa1b($skemaId)
    {
        $skema = SkemaKkni::with(['unitKompetensi.elemenKompetensi.kriteriaUnjukkerja'])
            ->findOrFail($skemaId);

        $mapa = SkemaMapa1b::bySkema($skemaId)
            ->with(['unitKompetensi', 'elemenKompetensi', 'kriteriaUnjukkerja'])
            ->get()
            ->groupBy(['id_unitkompetensi', 'id_elemenkompetensi']);

        return response()->json([
            'success' => true,
            'data' => [
                'skema' => $skema,
                'mapa1b' => $mapa,
            ],
        ]);
    }

    /**
     * Store or update MAPA 1B (Admin)
     * POST /api/v1/admin/skema/{id}/mapa1b
     *
     * @param Request $request
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeMapa1b(Request $request, $skemaId)
    {
        $skema = SkemaKkni::findOrFail($skemaId);

        $validator = Validator::make($request->all(), [
            'data' => 'required|array|min:1',
            'data.*.id_kriteria' => 'required|integer|exists:kriteria_unjukkerja,id',
            'data.*.ket_bukti' => 'nullable|string',
            'data.*.bukti_L' => 'nullable|in:L',
            'data.*.bukti_TL' => 'nullable|in:TL',
            'data.*.bukti_T' => 'nullable|in:T',
            'data.*.metode1' => 'nullable|string',
            'data.*.metode2' => 'nullable|string',
            'data.*.metode3' => 'nullable|string',
            'data.*.metode4' => 'nullable|string',
            'data.*.metode5' => 'nullable|string',
            'data.*.metode6' => 'nullable|string',
            'data.*.metode1t' => 'nullable|string',
            'data.*.metode2t' => 'nullable|string',
            'data.*.metode3t' => 'nullable|string',
            'data.*.metode4t' => 'nullable|string',
            'data.*.metode5t' => 'nullable|string',
            'data.*.metode6t' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $created = [];
            $updated = [];

            foreach ($request->data as $item) {
                $kriteriaId = $item['id_kriteria'];

                // Get related IDs
                $kriteria = \App\Models\KriteriaUnjukkerja::find($kriteriaId);
                if (!$kriteria) {
                    continue;
                }

                $elemenId = $kriteria->id_elemenkompetensi;
                $unitId = $kriteria->elemenKompetensi->id_unitkompetensi ?? null;

                // Check if exists
                $mapa = SkemaMapa1b::where('id_skemakkni', $skemaId)
                    ->where('id_kriteria', $kriteriaId)
                    ->first();

                $data = [
                    'id_skemakkni' => $skemaId,
                    'id_unitkompetensi' => $unitId,
                    'id_elemenkompetensi' => $elemenId,
                    'id_kriteria' => $kriteriaId,
                    'ket_bukti' => $item['ket_bukti'] ?? null,
                    'bukti_L' => $item['bukti_L'] ?? null,
                    'bukti_TL' => $item['bukti_TL'] ?? null,
                    'bukti_T' => $item['bukti_T'] ?? null,
                    'metode1' => $item['metode1'] ?? null,
                    'metode2' => $item['metode2'] ?? null,
                    'metode3' => $item['metode3'] ?? null,
                    'metode4' => $item['metode4'] ?? null,
                    'metode5' => $item['metode5'] ?? null,
                    'metode6' => $item['metode6'] ?? null,
                    'metode1t' => $item['metode1t'] ?? null,
                    'metode2t' => $item['metode2t'] ?? null,
                    'metode3t' => $item['metode3t'] ?? null,
                    'metode4t' => $item['metode4t'] ?? null,
                    'metode5t' => $item['metode5t'] ?? null,
                    'metode6t' => $item['metode6t'] ?? null,
                ];

                if ($mapa) {
                    $mapa->update($data);
                    $updated[] = $mapa;
                } else {
                    $mapa = SkemaMapa1b::create($data);
                    $created[] = $mapa;
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($created) . ' data dibuat, ' . count($updated) . ' data diperbarui',
                'data' => [
                    'created' => $created,
                    'updated' => $updated,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan MAPA 1B',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete MAPA 1B (Admin)
     * DELETE /api/v1/admin/mapa1b/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyMapa1b($id)
    {
        try {
            $mapa = SkemaMapa1b::findOrFail($id);
            $mapa->delete();

            return response()->json([
                'success' => true,
                'message' => 'MAPA 1B berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus MAPA 1B',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== MAPA 2 - Peta Instrumen ====================

    /**
     * Get MAPA 2 by skema (Admin/Public)
     * GET /api/v1/skema/{id}/mapa2
     *
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function showMapa2($skemaId)
    {
        $skema = SkemaKkni::with(['unitKompetensi'])->findOrFail($skemaId);
        $muk = Muk::all();

        $mapa = SkemaMapa2::bySkema($skemaId)
            ->with(['unitKompetensi', 'muk'])
            ->get()
            ->groupBy(['id_unitkompetensi']);

        return response()->json([
            'success' => true,
            'data' => [
                'skema' => $skema,
                'muk' => $muk,
                'mapa2' => $mapa,
            ],
        ]);
    }

    /**
     * Store or update MAPA 2 (Admin)
     * POST /api/v1/admin/skema/{id}/mapa2
     *
     * @param Request $request
     * @param int $skemaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeMapa2(Request $request, $skemaId)
    {
        $skema = SkemaKkni::findOrFail($skemaId);

        $validator = Validator::make($request->all(), [
            'data' => 'required|array|min:1',
            'data.*.id_unitkompetensi' => 'required|integer|exists:unit_kompetensi,id',
            'data.*.id_muk' => 'required|integer|exists:muk,id',
            'data.*.kandidat1' => 'nullable|in:1,0',
            'data.*.kandidat2' => 'nullable|in:1,0',
            'data.*.kandidat3' => 'nullable|in:1,0',
            'data.*.kandidat4' => 'nullable|in:1,0',
            'data.*.kandidat5' => 'nullable|in:1,0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $created = [];
            $updated = [];

            foreach ($request->data as $item) {
                $unitId = $item['id_unitkompetensi'];
                $mukId = $item['id_muk'];

                // Check if exists
                $mapa = SkemaMapa2::where('id_skema', $skemaId)
                    ->where('id_unitkompetensi', $unitId)
                    ->where('id_muk', $mukId)
                    ->first();

                $data = [
                    'id_skema' => $skemaId,
                    'id_unitkompetensi' => $unitId,
                    'id_muk' => $mukId,
                    'kandidat1' => $item['kandidat1'] ?? null,
                    'kandidat2' => $item['kandidat2'] ?? null,
                    'kandidat3' => $item['kandidat3'] ?? null,
                    'kandidat4' => $item['kandidat4'] ?? null,
                    'kandidat5' => $item['kandidat5'] ?? null,
                ];

                if ($mapa) {
                    $mapa->update($data);
                    $updated[] = $mapa;
                } else {
                    $mapa = SkemaMapa2::create($data);
                    $created[] = $mapa;
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($created) . ' data dibuat, ' . count($updated) . ' data diperbarui',
                'data' => [
                    'created' => $created,
                    'updated' => $updated,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan MAPA 2',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete MAPA 2 (Admin)
     * DELETE /api/v1/admin/mapa2/{id}
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyMapa2($id)
    {
        try {
            $mapa = SkemaMapa2::findOrFail($id);
            $mapa->delete();

            return response()->json([
                'success' => true,
                'message' => 'MAPA 2 berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus MAPA 2',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get kategori kandidat options
     * GET /api/v1/kategori-kandidat
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function kategoriKandidat()
    {
        $kategori = KategoriKandidat::orderBy('kode', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $kategori->map(function ($item) {
                return [
                    'value' => $item->kode,
                    'label' => $item->deskripsi,
                ];
            }),
        ]);
    }

    /**
     * Get muk options
     * GET /api/v1/muk
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function mukOptions()
    {
        $muk = Muk::orderBy('id', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $muk->map(function ($item) {
                return [
                    'value' => $item->id,
                    'label' => $item->judul,
                ];
            }),
        ]);
    }
}
