<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Muk extends Model
{
    protected $table = 'muk';

    protected $fillable = [
        'judul',
    ];

    /**
     * Relationship to Skema MAPA 2
     */
    public function mapa2(): HasMany
    {
        return $this->hasMany(SkemaMapa2::class, 'id_muk');
    }

    /**
     * Scope for search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where('judul', 'like', '%' . $search . '%');
        }
        return $query;
    }
}
