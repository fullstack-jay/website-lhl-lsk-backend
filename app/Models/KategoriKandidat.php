<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KategoriKandidat extends Model
{
    protected $table = 'kategori_kandidat';

    protected $fillable = [
        'kode',
        'deskripsi',
    ];

    protected $casts = [
        'kode' => 'integer',
    ];

    /**
     * Scope for filtering by kode
     */
    public function scopeByKode($query, $kode)
    {
        return $query->where('kode', $kode);
    }
}
