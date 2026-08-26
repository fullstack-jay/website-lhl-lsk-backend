<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tuk extends Model
{
    protected $table = 'tuk';

    public $timestamps = false;

    protected $fillable = [
        'kode_tuk',
        'id_tuk_bnsp',
        'nama',
        'penanggungjawab',
        'jenis_tuk',
        'lsp_induk',
        'institusi_induk',
        'alamat',
        'kelurahan',
        'id_wilayah',
        'kodepos',
        'telepon',
        'email',
        'fax',
        'tgl_pendirian',
        'no_lisensi',
        'masa_berlaku',
        'id_skkni',
    ];

    protected $casts = [
        'masa_berlaku' => 'date',
        'tgl_pendirian' => 'date',
    ];

    /**
     * Relationship to DataWilayah
     */
    public function wilayah()
    {
        return $this->belongsTo(DataWilayah::class, 'id_wilayah', 'id_wil');
    }

    /**
     * Relationship to JadwalAsesmen
     */
    public function jadwalAsesmen(): HasMany
    {
        return $this->hasMany(JadwalAsesmen::class, 'tempat_asesmen');
    }

    /**
     * Get full address
     */
    public function getFullAddressAttribute()
    {
        $parts = [];

        if ($this->alamat) {
            $parts[] = $this->alamat;
        }

        if ($this->kelurahan) {
            $parts[] = $this->kelurahan;
        }

        if ($this->wilayah) {
            $kecamatan = $this->wilayah;
            $kota = $kecamatan->parent ?? null;
            $provinsi = $kota ? $kota->parent ?? null : null;

            if ($kecamatan) {
                $parts[] = "Kec. {$kecamatan->nm_wil}";
            }

            if ($kota) {
                $parts[] = $kota->nm_wil;
            }

            if ($provinsi) {
                $parts[] = $provinsi->nm_wil;
            }
        }

        if ($this->kodepos) {
            $parts[] = $this->kodepos;
        }

        return implode(', ', $parts);
    }

    /**
     * Check if lisensi is active
     */
    public function isLisensiActive()
    {
        return $this->masa_berlaku && $this->masa_berlaku >= now()->startOfDay();
    }

    /**
     * Scope for active TUK
     */
    public function scopeActive($query)
    {
        return $query->where('masa_berlaku', '>=', now()->startOfDay());
    }

    /**
     * Scope for search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where('nama', 'like', '%' . $search . '%')
                ->orWhere('kode_tuk', 'like', '%' . $search . '%');
        }
        return $query;
    }
}
