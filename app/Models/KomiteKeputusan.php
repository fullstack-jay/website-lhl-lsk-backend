<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KomiteKeputusan extends Model
{
    protected $table = 'komite_keputusan';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id_skemakkni',
        'id_asesi',
        'id_jadwal',
        'id_komite',
        'keputusan',
    ];

    protected $casts = [
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
     * Relationship to Komite
     */
    public function komite()
    {
        return $this->belongsTo(Komite::class, 'id_komite');
    }

    /**
     * Relationship to Asesi
     */
    public function asesi()
    {
        return $this->belongsTo(Asesi::class, 'id_asesi');
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
        return match($this->keputusan) {
            'K' => 'Kompeten',
            'BK' => 'Belum Kompeten',
            'TL' => 'Tidak Lulus',
            'P' => 'Pending',
            default => 'Unknown',
        };
    }
}
