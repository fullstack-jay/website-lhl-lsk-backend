<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lsp extends Model
{
    protected $table = 'lsp';

    public $timestamps = false;

    protected $fillable = [
        'nama',
        'alamat',
        'telepon',
        'email',
        'website',
        'kode_pos',
        'kota',
        'provinsi',
        'negara',
        'deskripsi',
        'logo',
        'aktif',
    ];

    protected $casts = [
        'aktif' => 'boolean',
    ];

    /**
     * Relationship to Biaya Sertifikasi
     */
    public function biayaSertifikasi(): HasMany
    {
        return $this->hasMany(BiayaSertifikasi::class, 'id_lsp');
    }

    /**
     * Relationship to Rekening Bayar
     */
    public function rekeningbayar(): HasMany
    {
        return $this->hasMany(Rekeningbayar::class, 'kode_lsp');
    }

    /**
     * Scope for active LSP
     */
    public function scopeActive($query)
    {
        return $query->where('aktif', true);
    }

    /**
     * Scope for search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where('nama', 'like', '%' . $search . '%');
        }
        return $query;
    }
}
