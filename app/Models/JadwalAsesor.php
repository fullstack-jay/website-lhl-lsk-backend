<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JadwalAsesor extends Model
{
    protected $table = 'jadwal_asesor';

    public $timestamps = false;

    protected $fillable = [
        'id_jadwal',
        'id_asesor',
        'nama_asesor',
        'gelar_depan',
        'gelar_blk',
        'no_lisensi',
        'no_ktp',
    ];

    /**
     * Nama lengkap penguji (mengambil dari relasi asesor jika masih ada, atau dari snapshot nama_asesor)
     */
    public function getNamaLengkapAttribute(): string
    {
        if ($this->asesor) {
            return $this->asesor->full_name;
        }
        $depan = $this->gelar_depan ? $this->gelar_depan . ' ' : '';
        $blk = $this->gelar_blk ? ', ' . $this->gelar_blk : '';
        return trim($depan . ($this->nama_asesor ?: 'Penguji LSK') . $blk);
    }

    /**
     * Relationship to JadwalAsesmen
     */
    public function jadwal()
    {
        return $this->belongsTo(JadwalAsesmen::class, 'id_jadwal');
    }

    /**
     * Relationship to Asesor
     */
    public function asesor()
    {
        return $this->belongsTo(Asesor::class, 'id_asesor');
    }
}
