<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkemaMapa2 extends Model
{
    protected $table = 'skema_mapa2';

    public $timestamps = false;

    protected $fillable = [
        'id_skema',
        'id_unitkompetensi',
        'id_muk',
        'kandidat1',
        'kandidat2',
        'kandidat3',
        'kandidat4',
        'kandidat5',
    ];

    protected $casts = [
        'id_skema' => 'integer',
        'id_unitkompetensi' => 'integer',
        'id_muk' => 'integer',
    ];

    /**
     * Relationship to Skema KKNI
     */
    public function skemaKkni(): BelongsTo
    {
        return $this->belongsTo(SkemaKkni::class, 'id_skema');
    }

    /**
     * Relationship to Unit Kompetensi
     */
    public function unitKompetensi(): BelongsTo
    {
        return $this->belongsTo(UnitKompetensi::class, 'id_unitkompetensi');
    }

    /**
     * Relationship to Muk (Metode)
     */
    public function muk(): BelongsTo
    {
        return $this->belongsTo(Muk::class, 'id_muk');
    }

    /**
     * Scope for filtering by skema
     */
    public function scopeBySkema($query, $skemaId)
    {
        return $query->where('id_skema', $skemaId);
    }

    /**
     * Scope for filtering by unit kompetensi
     */
    public function scopeByUnit($query, $unitId)
    {
        return $query->where('id_unitkompetensi', $unitId);
    }
}
