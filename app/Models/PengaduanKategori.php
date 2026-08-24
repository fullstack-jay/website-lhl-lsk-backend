<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PengaduanKategori extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'pengaduan_kategori';

    /**
     * The attributes that are mass assignable
     */
    protected $fillable = [
        'nama_kategori',
        'deskripsi',
        'aktif',
        'urutan',
    ];

    /**
     * The attributes that should be cast
     */
    protected $casts = [
        'aktif' => 'boolean',
    ];

    /**
     * Scope for active categories
     */
    public function scopeActive($query)
    {
        return $query->where('aktif', 'Y');
    }

    /**
     * Scope ordered by urutan
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('urutan');
    }

    /**
     * Check if category is active
     */
    public function isActive(): bool
    {
        return $this->aktif === 'Y';
    }
}
