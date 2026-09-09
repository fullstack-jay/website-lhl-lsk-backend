<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Form Penilaian Asesi — 4 Instrumen Uji (VP/PT/DPSK/PW)
 * Sesuai docs/BACKEND_FORM_PENILAIAN.md:
 * - Bobot: VP 20%, PT 10%, DPSK 35%, PW 35% (total 100%)
 * - Passing grade 70.00 → K (>=70) / BK (<70)
 * - Kalkulasi nilai terbobot & kelulusan = SINGLE SOURCE OF TRUTH di backend
 * UNIQUE (id_jadwal, no_pendaftaran)
 */
class PenilaianAsesi extends Model
{
    /** Bobot instrumen (total 100%). */
    public const BOBOT_VP = 0.20;
    public const BOBOT_PT = 0.10;
    public const BOBOT_DPSK = 0.35;
    public const BOBOT_PW = 0.35;

    /** Standar minimum kelulusan. */
    public const PASSING_GRADE = 70.00;

    protected $table = 'penilaian_asesi';

    protected $fillable = [
        'id_jadwal',
        'id_asesmen',
        'no_pendaftaran',
        'id_asesor',
        'nilai_vp',
        'nilai_pt',
        'nilai_dpsk',
        'nilai_pw',
        'skor_vp',
        'skor_pt',
        'skor_dpsk',
        'skor_pw',
        'total_skor',
        'rekomendasi',
        'catatan',
        'rubrik_detail',
        'tgl_penilaian',
    ];

    protected $casts = [
        'nilai_vp' => 'float',
        'nilai_pt' => 'float',
        'nilai_dpsk' => 'float',
        'nilai_pw' => 'float',
        'skor_vp' => 'float',
        'skor_pt' => 'float',
        'skor_dpsk' => 'float',
        'skor_pw' => 'float',
        'total_skor' => 'float',
        'rubrik_detail' => 'array',
        'tgl_penilaian' => 'date',
    ];

    public function jadwal(): BelongsTo
    {
        return $this->belongsTo(JadwalAsesmen::class, 'id_jadwal');
    }

    public function asesiAsesmen(): BelongsTo
    {
        return $this->belongsTo(AsesiAsesmen::class, 'id_asesmen');
    }

    public function asesor(): BelongsTo
    {
        // id_asesor merujuk ke asesor.id (bukan users)
        return $this->belongsTo(Asesor::class, 'id_asesor', 'id');
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->rekomendasi === 'K' ? 'Kompeten' : 'Belum Kompeten';
    }

    /**
     * Kalkulasi nilai terbobot + rekomendasi (single source of truth).
     * Return array lengkap siap simpan.
     */
    public static function hitung(array $nilai): array
    {
        $nilaiVP = round((float) ($nilai['nilai_vp'] ?? 0), 2);
        $nilaiPT = round((float) ($nilai['nilai_pt'] ?? 0), 2);
        $nilaiDPSK = round((float) ($nilai['nilai_dpsk'] ?? 0), 2);
        $nilaiPW = round((float) ($nilai['nilai_pw'] ?? 0), 2);

        $skorVP = round($nilaiVP * self::BOBOT_VP, 2);
        $skorPT = round($nilaiPT * self::BOBOT_PT, 2);
        $skorDPSK = round($nilaiDPSK * self::BOBOT_DPSK, 2);
        $skorPW = round($nilaiPW * self::BOBOT_PW, 2);

        $totalSkor = round($skorVP + $skorPT + $skorDPSK + $skorPW, 2);

        $isLulus = $totalSkor >= self::PASSING_GRADE;

        return [
            'nilai_vp' => $nilaiVP,
            'nilai_pt' => $nilaiPT,
            'nilai_dpsk' => $nilaiDPSK,
            'nilai_pw' => $nilaiPW,
            'skor_vp' => $skorVP,
            'skor_pt' => $skorPT,
            'skor_dpsk' => $skorDPSK,
            'skor_pw' => $skorPW,
            'total_skor' => $totalSkor,
            'rekomendasi' => $isLulus ? 'K' : 'BK',
            'is_lulus' => $isLulus,
        ];
    }
}
