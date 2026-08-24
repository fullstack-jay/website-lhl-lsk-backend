<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KriteriaUnjukkerja extends Model
{
    protected $table = 'kriteria_unjukkerja';

    protected $fillable = [
        'kriteria',
        'kriteria_pasif',
        'id_elemenkompetensi',
    ];

    protected $casts = [
        'id_elemenkompetensi' => 'integer',
    ];

    /**
     * Relationship to Elemen Kompetensi
     */
    public function elemenKompetensi(): BelongsTo
    {
        return $this->belongsTo(ElemenKompetensi::class, 'id_elemenkompetensi');
    }

    /**
     * Relationship to MAPA 1B
     */
    public function mapa1b(): BelongsTo
    {
        return $this->belongsTo(SkemaMapa1b::class, 'id_kriteria');
    }

    /**
     * Scope for filtering by elemen kompetensi
     */
    public function scopeByElemen($query, $elemenId)
    {
        return $query->where('id_elemenkompetensi', $elemenId);
    }

    /**
     * Scope for search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where('kriteria', 'like', '%' . $search . '%')
                ->orWhere('kriteria_pasif', 'like', '%' . $search . '%');
        }
        return $query;
    }
}
