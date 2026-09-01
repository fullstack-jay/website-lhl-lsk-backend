<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Konten Frontpage Website LSK — sesuai docs/BACKEND_KONTENFRONTPAGE.md.
 * Setiap baris = satu konten; kolom `kategori` (FK frontpage_kategori.id)
 * menentukan di section mana konten dirender di halaman depan.
 */
class Frontpage extends Model
{
    protected $table = 'frontpage';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'judul',
        'sub_judul',
        'konten',
        'kategori',
        'konten_foto',
        'tanggal_terbit',
        'waktu_terbit',
    ];

    protected $casts = [
        'kategori' => 'integer',
    ];

    /**
     * Relationship ke kategori (section frontpage)
     */
    public function kategoriRef(): BelongsTo
    {
        return $this->belongsTo(FrontpageKategori::class, 'kategori');
    }

    /**
     * URL publik gambar konten
     */
    public function getKontenFotoUrlAttribute(): ?string
    {
        if ($this->konten_foto) {
            return asset('foto_konten/' . $this->konten_foto);
        }
        return null;
    }

    /**
     * Preview isi konten 300 karakter, strip HTML dulu agar tag tidak pecah
     * (padanan substr(strip_tags(konten), 0, 300) native).
     */
    public function getPreviewKontenAttribute(): string
    {
        return mb_substr(strip_tags((string) $this->konten), 0, 300);
    }

    /**
     * Scope filter per kategori
     */
    public function scopeByKategori($query, $kategoriId)
    {
        return $query->where('kategori', $kategoriId);
    }
}
