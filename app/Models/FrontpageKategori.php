<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategori (section) Konten Frontpage — 11 slug aktif:
 * slidebanner, welcome, layanan, layanan2, asesor, tagline, portfolio,
 * berita, faq, profil-lsk, struktur-lsk.
 * Slug = kontrak render frontend publik — jangan rename tanpa sinkronisasi.
 */
class FrontpageKategori extends Model
{
    protected $table = 'frontpage_kategori';

    public $timestamps = false;

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $fillable = [
        'kategori',
    ];

    /**
     * Konten-konten dalam kategori ini
     */
    public function konten(): HasMany
    {
        return $this->hasMany(Frontpage::class, 'kategori');
    }
}
