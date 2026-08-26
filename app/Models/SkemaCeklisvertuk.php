<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkemaCeklisvertuk extends Model
{
    protected $table = 'skema_ceklisvertuk';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id_asesor',
        'id_jadwal',
        'id_skemakkni',
        'id_perlengkapan',
        'jumlah',
        'baik',
        'rusak',
        'keterangan',
    ];

    protected $casts = [
        'waktu' => 'datetime',
        'jumlah' => 'integer',
        'baik' => 'integer',
        'rusak' => 'integer',
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
     * Get status verifikasi label
     */
    public function getStatusVerifikasiLabelAttribute()
    {
        if ($this->rusak > 0) {
            return 'Perlu Perbaikan';
        }
        if ($this->baik > 0) {
            return 'Memadai';
        }
        return 'Belum Diverifikasi';
    }
}
