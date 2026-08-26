<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsesorVerifikatortuk extends Model
{
    protected $table = 'asesor_verifikatortuk';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id_asesor',
        'id_jadwal',
        'id_skemakkni',
        'tgl_verifikasi',
        'no_surattugas',
        'tgl_surattugas',
        'file_surattugas',
        'keputusanverifikasi',
    ];

    protected $casts = [
        'tgl_verifikasi' => 'date',
        'tgl_surattugas' => 'date',
        'waktu' => 'datetime',
    ];

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

    /**
     * Relationship to SkemaKkni
     */
    public function skema()
    {
        return $this->belongsTo(SkemaKkni::class, 'id_skemakkni');
    }

    /**
     * Get keputusan label
     */
    public function getKeputusanLabelAttribute()
    {
        return match($this->keputusanverifikasi) {
            'P' => 'Pending',
            'Y' => 'Ya',
            'N' => 'Tidak',
            default => 'Unknown',
        };
    }
}
