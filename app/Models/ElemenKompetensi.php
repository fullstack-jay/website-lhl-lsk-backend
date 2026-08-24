<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElemenKompetensi extends Model
{
    protected $table = 'elemen_kompetensi';

    public $timestamps = false;

    protected $fillable = [
        'elemen_kompetensi',
        'id_unitkompetensi',
    ];

    protected $casts = [
        'id_unitkompetensi' => 'integer',
    ];

    /**
     * Relationship to Kriteria Unjuk Kerja
     */
    public function kriteriaUnjukkerja(): HasMany
    {
        return $this->hasMany(KriteriaUnjukkerja::class, 'id_elemenkompetensi');
    }

    /**
     * Relationship to Unit Kompetensi
     */
    public function unitKompetensi(): BelongsTo
    {
        return $this->belongsTo(UnitKompetensi::class, 'id_unitkompetensi');
    }

    /**
     * Relationship to MAPA 1B
     */
    public function mapa1b(): HasMany
    {
        return $this->hasMany(SkemaMapa1b::class, 'id_elemenkompetensi');
    }

    /**
     * Scope for filtering by unit kompetensi
     */
    public function scopeByUnit($query, $unitId)
    {
        return $query->where('id_unitkompetensi', $unitId);
    }

    /**
     * Get jumlah KUK accessor
     */
    public function getJumlahKukAttribute(): int
    {
        return $this->kriteriaUnjukkerja()->count();
    }
}
