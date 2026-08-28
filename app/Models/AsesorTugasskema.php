<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penetapan skema yang boleh diujikan oleh seorang Penguji (Asesor),
 * berdasarkan nomor Surat Keputusan (SK).
 */
class AsesorTugasskema extends Model
{
    protected $table = 'asesor_tugasskema';

    public $timestamps = false;

    protected $fillable = [
        'id_asesor',
        'id_skemakkni',
        'no_sk',
        'tanggal_sk',
    ];

    protected $casts = [
        'id_asesor' => 'integer',
        'id_skemakkni' => 'integer',
        'tanggal_sk' => 'date',
    ];

    /**
     * Relationship to Asesor
     */
    public function asesor(): BelongsTo
    {
        return $this->belongsTo(Asesor::class, 'id_asesor');
    }

    /**
     * Relationship to Skema KKNI
     */
    public function skemaKkni(): BelongsTo
    {
        return $this->belongsTo(SkemaKkni::class, 'id_skemakkni');
    }
}
