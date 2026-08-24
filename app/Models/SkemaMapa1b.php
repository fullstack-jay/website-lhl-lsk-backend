<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkemaMapa1b extends Model
{
    protected $table = 'skema_mapa1b';

    public $timestamps = false;

    protected $fillable = [
        'id_skemakkni',
        'id_unitkompetensi',
        'id_elemenkompetensi',
        'id_kriteria',
        'ket_bukti',
        'bukti_L',
        'bukti_TL',
        'bukti_T',
        'metode1',
        'metode2',
        'metode3',
        'metode4',
        'metode5',
        'metode6',
        'metode1t',
        'metode2t',
        'metode3t',
        'metode4t',
        'metode5t',
        'metode6t',
    ];

    protected $casts = [
        'id_skemakkni' => 'integer',
        'id_unitkompetensi' => 'integer',
        'id_elemenkompetensi' => 'integer',
        'id_kriteria' => 'integer',
    ];

    /**
     * Relationship to Skema KKNI
     */
    public function skemaKkni(): BelongsTo
    {
        return $this->belongsTo(SkemaKkni::class, 'id_skemakkni');
    }

    /**
     * Relationship to Unit Kompetensi
     */
    public function unitKompetensi(): BelongsTo
    {
        return $this->belongsTo(UnitKompetensi::class, 'id_unitkompetensi');
    }

    /**
     * Relationship to Elemen Kompetensi
     */
    public function elemenKompetensi(): BelongsTo
    {
        return $this->belongsTo(ElemenKompetensi::class, 'id_elemenkompetensi');
    }

    /**
     * Relationship to Kriteria Unjuk Kerja
     */
    public function kriteriaUnjukkerja(): BelongsTo
    {
        return $this->belongsTo(KriteriaUnjukkerja::class, 'id_kriteria');
    }

    /**
     * Scope for filtering by skema
     */
    public function scopeBySkema($query, $skemaId)
    {
        return $query->where('id_skemakkni', $skemaId);
    }

    /**
     * Scope for filtering by unit kompetensi
     */
    public function scopeByUnit($query, $unitId)
    {
        return $query->where('id_unitkompetensi', $unitId);
    }
}
