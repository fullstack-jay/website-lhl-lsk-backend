<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataWilayah;
use Illuminate\Http\Request;

class WilayahController extends Controller
{
    /**
     * Get all provinces (Provinsi)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProvinsi()
    {
        $provinsi = DataWilayah::provinsi()
            ->orderBy('nm_wil')
            ->get(['id_wil', 'nm_wil']);

        return response()->json([
            'success' => true,
            'data' => $provinsi->map(function ($item) {
                return [
                    'value' => $item->id_wil,
                    'label' => $item->nm_wil,
                ];
            }),
        ]);
    }

    /**
     * Get cities/kabupaten by province ID
     *
     * @param string $provinsiId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getKota($provinsiId)
    {
        $kota = DataWilayah::kota()
            ->byParent($provinsiId)
            ->orderBy('nm_wil')
            ->get(['id_wil', 'nm_wil']);

        return response()->json([
            'success' => true,
            'data' => $kota->map(function ($item) {
                return [
                    'value' => $item->id_wil,
                    'label' => $item->nm_wil,
                ];
            }),
        ]);
    }

    /**
     * Get districts/kecamatan by city ID
     *
     * @param string $kotaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getKecamatan($kotaId)
    {
        $kecamatan = DataWilayah::kecamatan()
            ->byParent($kotaId)
            ->orderBy('nm_wil')
            ->get(['id_wil', 'nm_wil']);

        return response()->json([
            'success' => true,
            'data' => $kecamatan->map(function ($item) {
                return [
                    'value' => $item->id_wil,
                    'label' => $item->nm_wil,
                ];
            }),
        ]);
    }

    /**
     * Get wilayah by ID with full hierarchy
     *
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDetail($id)
    {
        $wilayah = DataWilayah::find($id);

        if (!$wilayah) {
            return response()->json([
                'success' => false,
                'message' => 'Wilayah tidak ditemukan',
            ], 404);
        }

        $data = [
            'id_wil' => $wilayah->id_wil,
            'nm_wil' => $wilayah->nm_wil,
            'id_level_wil' => $wilayah->id_level_wil,
            'level_label' => $this->getLevelLabel($wilayah->id_level_wil),
        ];

        // Get parent hierarchy
        $parent = $wilayah->parent;
        $level = 2;

        while ($parent && $level <= 3) {
            $levelLabel = $this->getLevelLabel($parent->id_level_wil);
            $data[strtolower(str_replace(' ', '_', $levelLabel))] = [
                'id_wil' => $parent->id_wil,
                'nm_wil' => $parent->nm_wil,
            ];

            $parent = $parent->parent;
            $level++;
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get level label in Indonesian
     *
     * @param int $level
     * @return string
     */
    private function getLevelLabel($level)
    {
        return match($level) {
            1 => 'Provinsi',
            2 => 'Kota/Kabupaten',
            3 => 'Kecamatan',
            default => 'Unknown',
        };
    }
}
