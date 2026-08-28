<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsesiAsesmen extends Model
{
    protected $table = 'asesi_asesmen';

    protected $primaryKey = 'id';

    /**
     * Tabel asesi_asesmen tidak punya kolom updated_at/created_at Laravel
     * (hanya kolom lama: tgl_daftar, waktu, dst) — timestamps dimatikan
     * agar create/update tidak menyisipkan kolom yang tidak ada.
     */
    public $timestamps = false;

    protected $fillable = [
        'id_asesi',
        'id_skemakkni',
        'id_jadwal',
        'id_asesor',
        'peninjau_ia11',
        'tgl_asesmen',
        'status',
        'status_asesmen',
        'catatan_asesmen',
        'no_lisensi',
        'no_serisertifikat',
        'masa_berlaku',
        'foto_sertifikat',
        'is_apl02',
        'created_at',
    ];

    protected $casts = [
        'tgl_asesmen' => 'date',
        'masa_berlaku' => 'date',
        'created_at' => 'datetime',
        'is_apl02' => 'boolean',
    ];

    /**
     * Relationship to Asesi
     */
    public function asesi(): BelongsTo
    {
        return $this->belongsTo(Asesi::class, 'id_asesi', 'no_pendaftaran');
    }

    /**
     * Relationship to SkemaKkni
     */
    public function skema(): BelongsTo
    {
        return $this->belongsTo(SkemaKkni::class, 'id_skemakkni');
    }

    /**
     * Relationship to JadwalAsesmen
     */
    public function jadwal(): BelongsTo
    {
        return $this->belongsTo(JadwalAsesmen::class, 'id_jadwal');
    }

    /**
     * Relationship to Asesor (asesor penilai)
     */
    public function asesor(): BelongsTo
    {
        return $this->belongsTo(Asesor::class, 'id_asesor');
    }

    /**
     * Relationship to Asesor (peninjau IA 11)
     */
    public function peninjau(): BelongsTo
    {
        return $this->belongsTo(Asesor::class, 'peninjau_ia11');
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute()
    {
        return match($this->status) {
            'A' => 'Disetujui',
            'R' => 'Ditolak',
            'P' => 'Pending',
            default => 'Unknown',
        };
    }

    /**
     * Get status asesmen label
     */
    public function getStatusAsesmenLabelAttribute()
    {
        return match($this->status_asesmen) {
            'K' => 'Kompeten',
            'BK' => 'Belum Kompeten',
            default => null,
        };
    }

    /**
     * Check if kompeten
     */
    public function isKompeten()
    {
        return $this->status_asesmen === 'K';
    }

    /**
     * Check if sudah mendapat jadwal
     */
    public function hasJadwal()
    {
        return $this->id_jadwal !== null;
    }

    /**
     * Check if sudah mendapat asesor
     */
    public function hasAsesor()
    {
        return $this->id_asesor !== null;
    }
}
