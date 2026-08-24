<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkemaMapa1a extends Model
{
    protected $table = 'skema_mapa1a';

    public $timestamps = false;

    protected $fillable = [
        'id_skemakkni',
        'profil_kandidat',
        'pendekatan',
        'pendekatan_ket',
        'tujuan',
        'tujuanket',
        'konteks_a',
        'konteks_b',
        'konteks_c1',
        'konteks_c2',
        'konteks_c3',
        'konteks_d',
        'konfirmasi1',
        'konfirmasi2',
        'konfirmasi3',
        'konfirmasi4',
        'konfirmasi4_ket',
        'toluk1',
        'toluk2',
        'toluk3',
        'toluk4',
        'toluk5',
        'toluk1_ket',
        'toluk2_ket',
        'toluk3_ket',
        'toluk4_ket',
        'toluk5_ket',
        'konfirm1',
        'konfirm2',
        'konfirm3',
        'konfirm4',
        'konfirm1_ket',
        'konfirm2_ket',
        'konfirm3_ket',
        'konfirm4_ket',
        'modkon1a',
        'modkon1b',
        'modkon1a_ket',
        'modkon1b_ket',
        'modkon2',
        'modkon3',
        'modkon4',
        'modkon2_ket',
        'modkon3_ket',
        'modkon4_ket',
    ];

    protected $casts = [
        'id_skemakkni' => 'integer',
        'profil_kandidat' => 'integer',
    ];

    /**
     * Relationship to Skema KKNI
     */
    public function skemaKkni(): BelongsTo
    {
        return $this->belongsTo(SkemaKkni::class, 'id_skemakkni');
    }

    /**
     * Scope for filtering by skema
     */
    public function scopeBySkema($query, $skemaId)
    {
        return $query->where('id_skemakkni', $skemaId);
    }

    /**
     * Scope for filtering by profil kandidat
     */
    public function scopeByProfil($query, $profil)
    {
        return $query->where('profil_kandidat', $profil);
    }
}
