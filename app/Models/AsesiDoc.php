<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dokumen persyaratan per skema yang diunggah peserta
 * (modul syarat — docs/BACKEND_PESERTA_SKEMA_SERTIFIKASI.md).
 *
 * Struktur kolom mengikuti tabel live `asesi_doc` (varchar id_skemakkni /
 * skema_persyaratan → simpan sebagai string; status enum P/A/R).
 */
class AsesiDoc extends Model
{
    protected $table = 'asesi_doc';

    public $timestamps = false;

    protected $fillable = [
        'id_asesi',
        'id_skemakkni',
        'skema_persyaratan',
        'nama_doc',
        'tahun_doc',
        'nomor_doc',
        'tgl_doc',
        'file',
        'status',
    ];

    protected $casts = [
        'tahun_doc' => 'integer',
        'tgl_doc' => 'date',
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
     * Scope for pending documents
     */
    public function scopePending($query)
    {
        return $query->where('status', 'P');
    }

    /**
     * Scope for approved documents
     */
    public function scopeDisetujui($query)
    {
        return $query->where('status', 'A');
    }

    /**
     * Scope for rejected documents
     */
    public function scopeDitolak($query)
    {
        return $query->where('status', 'R');
    }

    /**
     * Get status label (P/A/R)
     */
    public function getStatusLabelAttribute()
    {
        return match ($this->status) {
            'A' => 'Disetujui',
            'R' => 'Ditolak',
            'P' => 'Menunggu Persetujuan',
            default => 'Unknown',
        };
    }

    /**
     * Peserta boleh menghapus sendiri hanya yang belum diverifikasi admin
     */
    public function getBisaHapusAttribute()
    {
        return $this->status === 'P';
    }

    /**
     * Get file URL (storage disk public — foto_asesi)
     */
    public function getFileUrlAttribute()
    {
        if ($this->file) {
            return url('storage/foto_asesi/'.$this->file);
        }

    }
}
