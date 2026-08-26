<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Komite extends Model
{
    protected $table = 'komite';

    public $timestamps = false;

    protected $fillable = [
        'nama',
        'gelar_depan',
        'gelar_blk',
        'no_telp',
        'email',
    ];

    /**
     * Relationship to JadwalAsesmen through komite_keputusan
     */
    public function jadwalAsesmen(): BelongsToMany
    {
        return $this->belongsToMany(JadwalAsesmen::class, 'komite_keputusan', 'id_asesor', 'id_jadwal')
            ->withPivot('keputusan', 'waktu');
    }

    /**
     * Get full name
     */
    public function getFullNameAttribute()
    {
        $parts = [];

        if ($this->gelar_depan) {
            $parts[] = $this->gelar_depan;
        }

        $parts[] = $this->nama;

        if ($this->gelar_blk) {
            $parts[] = $this->gelar_blk;
        }

        return implode(' ', $parts);
    }
}
