<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lsp extends Model
{
    protected $table = 'lsp';

    public $timestamps = false;

    protected $fillable = [
        'kode_lsp',
        'nama',
        'direktur',
        'nama_jabatanpimpinan',
        'penanggungjawab',
        'manajer_sertifikasi',
        'jenis_lsp',
        'institusi_induk',
        'alamat',
        'kelurahan',
        'id_wilayah',
        'kodepos',
        'telepon',
        'email',
        'email_alternatif',
        'fax',
        'wa',
        'website',
        'tgl_pendirian',
        'no_lisensi',
        'masa_berlaku',
        'id_skkni',
        'googlemapcode',
        'meta_keywords',
        'ttddigital',
        'logo',
    ];

    protected $casts = [
        'tgl_pendirian' => 'date',
        'masa_berlaku' => 'date',
    ];

    /**
     * Relationship to LspJenis
     */
    public function jenisLsp()
    {
        return $this->belongsTo(LspJenis::class, 'jenis_lsp', 'kode');
    }

    /**
     * Relationship to DataWilayah
     */
    public function wilayah()
    {
        return $this->belongsTo(DataWilayah::class, 'id_wilayah', 'id_wil');
    }

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
     * Get logo URL
     */
    public function getLogoUrlAttribute()
    {
        if ($this->logo) {
            return asset('images/' . $this->logo);
        }
        return null;
    }

    /**
     * Get TTD digital URL
     */
    public function getTtdUrlAttribute()
    {
        if ($this->ttddigital) {
            return asset('images/' . $this->ttddigital);
        }
        return null;
    }

    /**
     * Check if license is active
     */
    public function isLicenseActive()
    {
        return $this->masa_berlaku && $this->masa_berlaku >= now()->startOfDay();
    }

    /**
     * Get license status
     */
    public function getLicenseStatusAttribute()
    {
        if (!$this->masa_berlaku) {
            return [
                'status' => 'unknown',
                'label' => 'Tidak ada masa berlaku',
                'color' => 'gray',
            ];
        }

        if ($this->masa_berlaku >= now()->startOfDay()) {
            return [
                'status' => 'active',
                'label' => 'Aktif',
                'color' => 'green',
            ];
        }

        return [
            'status' => 'expired',
            'label' => 'Kadaluarsa',
            'color' => 'red',
        ];
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
     * Scope for active license
     */
    public function scopeLicenseActive($query)
    {
        return $query->where('masa_berlaku', '>=', now()->startOfDay());
    }

    /**
     * Scope for expired license
     */
    public function scopeLicenseExpired($query)
    {
        return $query->where('masa_berlaku', '<', now()->startOfDay());
    }

    /**
     * Scope for search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where('nama', 'like', '%' . $search . '%')
                ->orWhere('kode_lsp', 'like', '%' . $search . '%')
                ->orWhere('no_lisensi', 'like', '%' . $search . '%');
        }
        return $query;
    }
}
