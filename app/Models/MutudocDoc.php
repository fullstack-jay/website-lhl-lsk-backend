<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MutudocDoc extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'mutudoc_doc';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable
     */
    protected $fillable = [
        'jenis',
        'kategori',
        'judul',
        'deskripsi',
        'tgl_terbit',
        'no_dokumen',
        'no_revisi',
        'penyusun',
        'pengesahan',
        'file',
    ];

    /**
     * The attributes that should be cast
     */
    protected $casts = [
        // 'tgl_terbit' => 'date', // Removed to avoid timezone conversion
        'no_revisi' => 'integer',
        'waktu_upload' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = [
        'no_revisi_formatted',
        'tgl_terbit_formatted',
        'file_url',
    ];

    /**
     * Relationship dengan jenis dokumen
     */
    public function jenisDoc(): BelongsTo
    {
        return $this->belongsTo(MutudocJenisdoc::class, 'jenis');
    }

    /**
     * Relationship dengan kategori dokumen
     */
    public function kategoriDoc(): BelongsTo
    {
        return $this->belongsTo(MutudocKategoridoc::class, 'kategori');
    }

    /**
     * Get formatted no revisi (R.{no})
     */
    public function getNoRevisiFormattedAttribute(): string
    {
        return 'R.' . $this->no_revisi;
    }

    /**
     * Get formatted tanggal terbit (Indonesian format)
     */
    public function getTglTerbitFormattedAttribute(): string
    {
        if (!$this->tgl_terbit) {
            return '-';
        }

        $bulanIndonesia = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        $date = \Carbon\Carbon::parse($this->tgl_terbit);
        return $date->day . ' ' . $bulanIndonesia[$date->month] . ' ' . $date->year;
    }

    /**
     * Get file URL
     */
    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file) {
            return null;
        }

        return url('foto_mutudoc/' . $this->file);
    }

    /**
     * Scope untuk filter jenis
     */
    public function scopeJenis($query, $jenis)
    {
        if ($jenis) {
            return $query->where('jenis', $jenis);
        }
        return $query;
    }

    /**
     * Scope untuk filter kategori
     */
    public function scopeKategori($query, $kategori)
    {
        if ($kategori) {
            return $query->where('kategori', $kategori);
        }
        return $query;
    }

    /**
     * Scope untuk search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where(function($q) use ($search) {
                $q->where('judul', 'like', "%{$search}%")
                  ->orWhere('no_dokumen', 'like', "%{$search}%")
                  ->orWhere('deskripsi', 'like', "%{$search}%");
            });
        }
        return $query;
    }
}
