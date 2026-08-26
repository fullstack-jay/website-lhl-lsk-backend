<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * Relationship to DataWilayah (Kecamatan)
     */
    public function wilayah(): BelongsTo
    {
        return $this->belongsTo(DataWilayah::class, 'id_wilayah', 'id_wil');
    }

    /**
     * Relationship to SkemaKkni (SKKNI)
     */
    public function skema(): BelongsTo
    {
        return $this->belongsTo(SkemaKkni::class, 'id_skkni');
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
    public function getLisensiActiveAttribute()
    {
        return $this->masa_berlaku && $this->masa_berlaku >= now()->startOfDay();
    }

    /**
     * Get status lisensi label
     */
    public function getStatusLisensiAttribute()
    {
        if (!$this->masa_berlaku) {
            return 'not_specified';
        }

        return $this->lisensi_active ? 'active' : 'expired';
    }

    /**
     * Get sisa hari masa berlaku
     */
    public function getSisaHariAttribute()
    {
        if (!$this->masa_berlaku) {
            return null;
        }

        return now()->startOfDay()->diffInDays($this->masa_berlaku, false);
    }

    /**
     * Get jumlah jadwal yang menggunakan TUK ini
     */
    public function getJumlahJadwalAttribute()
    {
        return $this->jadwalAsesmen()->count();
    }

    /**
     * Check if TUK can be deleted (not used in any jadwal)
     */
    public function canBeDeleted()
    {
        return $this->jumlah_jadwal == 0;
    }

    /**
     * Scope for active TUK (lisensi masih berlaku)
     */
    public function scopeActive($query)
    {
        return $query->where('masa_berlaku', '>=', now()->startOfDay());
    }

    /**
     * Scope for expired TUK
     */
    public function scopeExpired($query)
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
                ->orWhere('kode_tuk', 'like', '%' . $search . '%')
                ->orWhere('penanggungjawab', 'like', '%' . $search . '%');
        }
        return $query;
    }

    /**
     * Scope by jenis TUK
     */
    public function scopeByJenis($query, $jenisTuk)
    {
        return $query->where('jenis_tuk', $jenisTuk);
    }

    /**
     * Scope by LSP induk
     */
    public function scopeByLsp($query, $lspId)
    {
        return $query->where('lsp_induk', $lspId);
    }

    /**
     * Scope by SKKNI
     */
    public function scopeBySkkni($query, $skkniId)
    {
        return $query->where('id_skkni', $skkniId);
    }
}
