<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkemaKkni extends Model
{
    protected $table = 'skema_kkni';

    public $timestamps = false;

    protected $fillable = [
        'kode_skema',
        'judul',
        'judul_eng',
        'areakerja',
        'areakerja_eng',
        'kode_sektor',
        'kbli',
        'kbji',
        'jenjang',
        'keterangan_bukti',
        'apl02',
        'file',
        'jenis_skema',
        'skema_induk',
        'kodeskema_bnsp',
        'aktif',
        'id_skkni',
    ];

    protected $casts = [
        'jenjang' => 'integer',
        'skema_induk' => 'integer',
        'id_skkni' => 'integer',
    ];

    /**
     * Relationship to Unit Kompetensi
     */
    public function unitKompetensi(): HasMany
    {
        return $this->hasMany(UnitKompetensi::class, 'id_skemakkni');
    }

    /**
     * Relationship to Skema Persyaratan
     */
    public function persyaratan(): HasMany
    {
        return $this->hasMany(SkemaPersyaratan::class, 'id_skemakkni');
    }

    /**
     * Relationship to Skema Persyaratan TUK
     */
    public function persyaratanTuk(): HasMany
    {
        return $this->hasMany(SkemaPersyaratantuk::class, 'id_skemakkni');
    }

    /**
     * Relationship to Skema MAPA 1A
     */
    public function mapa1a(): HasMany
    {
        return $this->hasMany(SkemaMapa1a::class, 'id_skemakkni');
    }

    /**
     * Relationship to Skema MAPA 1B
     */
    public function mapa1b(): HasMany
    {
        return $this->hasMany(SkemaMapa1b::class, 'id_skemakkni');
    }

    /**
     * Relationship to Skema MAPA 2
     */
    public function mapa2(): HasMany
    {
        return $this->hasMany(SkemaMapa2::class, 'id_skema');
    }

    /**
     * Relationship to SKKNI
     */
    public function skkni(): BelongsTo
    {
        return $this->belongsTo(Skkni::class, 'id_skkni');
    }

    /**
     * Get file URL attribute
     */
    public function getFileUrlAttribute(): string
    {
        if ($this->file) {
            return asset('foto_skema/' . $this->file);
        }
        return '';
    }

    /**
     * Scope for active schemes
     */
    public function scopeActive($query)
    {
        return $query->where('aktif', 'Y');
    }

    /**
     * Scope for filtering by jenis skema
     */
    public function scopeJenisSkema($query, $jenis)
    {
        if ($jenis) {
            return $query->where('jenis_skema', $jenis);
        }
        return $query;
    }

    /**
     * Scope for filtering by jenjang KKNI
     */
    public function scopeJenjang($query, $jenjang)
    {
        if ($jenjang !== null) {
            return $query->where('jenjang', $jenjang);
        }
        return $query;
    }

    /**
     * Scope for search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where(function ($q) use ($search) {
                $q->where('kode_skema', 'like', '%' . $search . '%')
                    ->orWhere('judul', 'like', '%' . $search . '%')
                    ->orWhere('judul_eng', 'like', '%' . $search . '%')
                    ->orWhere('areakerja', 'like', '%' . $search . '%');
            });
        }
        return $query;
    }

    /**
     * Get statistics for this scheme
     */
    public function getStatisticsAttribute(): array
    {
        return [
            'jumlah_unit' => $this->unitKompetensi()->count(),
            'jumlah_elemen' => $this->unitKompetensi()
                ->withCount('elemenKompetensi')
                ->get()
                ->sum('elemen_kompetensi_count'),
            'jumlah_kuk' => $this->unitKompetensi()
                ->with('elemenKompetensi.kriteriaUnjukkerja')
                ->get()
                ->sum(function ($unit) {
                    return $unit->elemenKompetensi->sum(function ($elemen) {
                        return $elemen->kriteriaUnjukkerja->count();
                    });
                }),
        ];
    }
}
