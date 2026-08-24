<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BiayaJenis extends Model
{
    protected $table = 'biaya_jenis';

    public $timestamps = false;

    protected $fillable = [
        'jenis_biaya',
    ];

    /**
     * Relationship to Biaya Sertifikasi
     */
    public function biayaSertifikasi()
    {
        return $this->hasMany(BiayaSertifikasi::class, 'jenis_biaya');
    }

    /**
     * Scope for search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where('jenis_biaya', 'like', '%' . $search . '%');
        }
        return $query;
    }
}
