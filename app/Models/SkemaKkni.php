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

    protected $appends = ['skkni_list', 'statistics'];

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
     * Get SKKNI list from unit kompetensi
     */
    public function getSkkniListAttribute(): \Illuminate\Support\Collection
    {
        // Get unique SKKNI from unit kompetensi
        $skkniIds = $this->unitKompetensi()
            ->whereNotNull('id_skkni')
            ->pluck('id_skkni')
            ->unique()
            ->filter();

        return Skkni::whereIn('id', $skkniIds)
            ->get(['id', 'no_skkni', 'nama', 'file']);
    }

    /**
     * Get statistics for this scheme
     */
    public function getStatisticsAttribute(): array
    {
        $unitCount = $this->unitKompetensi()->count();
        $elemenCount = $this->unitKompetensi()
            ->withCount('elemenKompetensi')
            ->get()
            ->sum('elemen_kompetensi_count');
        $kukCount = $this->unitKompetensi()
            ->with('elemenKompetensi.kriteriaUnjukkerja')
            ->get()
            ->sum(function ($unit) {
                return $unit->elemenKompetensi->sum(function ($elemen) {
                    return $elemen->kriteriaUnjukkerja->count();
                });
            });

        // Get peserta count using raw query to avoid relationship issues
        $pesertaCount = \DB::table('asesi_asesmen')
            ->where('id_skemakkni', $this->id)
            ->distinct('id_asesi')
            ->count('id_asesi');

        // Get jadwal asesmen count (using id_skemakkni column)
        $jadwalCount = \DB::table('jadwal_asesmen')
            ->where('id_skemakkni', $this->id)
            ->count();

        // Get persyaratan counts
        $persyaratanPesertaCount = $this->persyaratan()->count();
        $persyaratanTukCount = $this->persyaratanTuk()->count();

        return [
            'jumlah_unit' => $unitCount,
            'jumlah_elemen' => $elemenCount,
            'jumlah_kuk' => $kukCount,
            'jumlah_peserta' => $pesertaCount ?? 0,
            'jumlah_jadwal' => $jadwalCount ?? 0,
            'jumlah_persyaratan_peserta' => $persyaratanPesertaCount,
            'jumlah_persyaratan_tuk' => $persyaratanTukCount,
        ];
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
}
