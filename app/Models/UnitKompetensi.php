<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitKompetensi extends Model
{
    protected $table = 'unit_kompetensi';

    public $timestamps = false;

    protected $fillable = [
        'kode_unit',
        'judul',
        'judul_eng',
        'id_skemakkni',
        'id_skkni',
        'jenis',
    ];

    protected $casts = [
        'id_skemakkni' => 'integer',
        'id_skkni' => 'integer',
    ];

    /**
     * Relationship to Elemen Kompetensi
     */
    public function elemenKompetensi(): HasMany
    {
        return $this->hasMany(ElemenKompetensi::class, 'id_unitkompetensi');
    }

    /**
     * Relationship to Skema KKNI
     */
    public function skemaKkni(): BelongsTo
    {
        return $this->belongsTo(SkemaKkni::class, 'id_skemakkni');
    }

    /**
     * Relationship to SKKNI
     */
    public function skkni(): BelongsTo
    {
        return $this->belongsTo(Skkni::class, 'id_skkni');
    }

    /**
     * Relationship to MAPA 1B
     */
    public function mapa1b(): HasMany
    {
        return $this->hasMany(SkemaMapa1b::class, 'id_unitkompetensi');
    }

    /**
     * Relationship to MAPA 2
     */
    public function mapa2(): HasMany
    {
        return $this->hasMany(SkemaMapa2::class, 'id_unitkompetensi');
    }

    /**
     * Scope for filtering by skema
     */
    public function scopeBySkema($query, $skemaId)
    {
        return $query->where('id_skemakkni', $skemaId);
    }

    /**
     * Get jumlah elemen accessor
     */
    public function getJumlahElemenAttribute(): int
    {
        return $this->elemenKompetensi()->count();
    }

    /**
     * Get jumlah KUK accessor
     */
    public function getJumlahKukAttribute(): int
    {
        return $this->elemenKompetensi()
            ->withCount('kriteriaUnjukkerja')
            ->get()
            ->sum('kriteria_unjukkerja_count');
    }

    /**
     * Get elemen count alias
     */
    public function getElemenCountAttribute(): int
    {
        return $this->jumlah_elemen;
    }

    /**
     * Get KUK count alias
     */
    public function getKukCountAttribute(): int
    {
        return $this->jumlah_kuk;
    }

    /**
     * Scope search by kode or judul
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where('kode_unit', 'like', '%' . $search . '%')
                ->orWhere('judul', 'like', '%' . $search . '%')
                ->orWhere('judul_eng', 'like', '%' . $search . '%');
        }
        return $query;
    }

    /**
     * Check if unit can be deleted (only if no elemen exists)
     */
    public function canBeDeleted(): bool
    {
        return $this->jumlah_elemen === 0;
    }

    /**
     * Get full unit name with code
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->kode_unit} - {$this->judul}";
    }

    /**
     * Get jenis label
     */
    public function getJenisLabelAttribute(): string
    {
        return match($this->jenis) {
            'SKKNI' => 'SKKNI',
            'Standar Khusus' => 'Standar Khusus',
            'Standar Internasional' => 'Standar Internasional',
            default => 'Unknown',
        };
    }
}
