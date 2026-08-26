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
}
