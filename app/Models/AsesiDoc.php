<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsesiDoc extends Model
{
    protected $table = 'asesi_doc';

    public $timestamps = false;

    protected $fillable = [
        'id_asesi',
        'id_skemakkni',
        'jenis_doc',
        'file',
        'verifikasi',
        'catatan',
    ];

    protected $casts = [
        'verifikasi' => 'boolean',
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
     * Scope for verified documents
     */
    public function scopeVerified($query)
    {
        return $query->where('verifikasi', 'Y');
    }

    /**
     * Scope for pending documents
     */
    public function scopePending($query)
    {
        return $query->where('verifikasi', 'P');
    }

    /**
     * Scope for rejected documents
     */
    public function scopeRejected($query)
    {
        return $query->where('verifikasi', 'N');
    }

    /**
     * Get verification label
     */
    public function getVerifikasiLabelAttribute()
    {
        return match($this->verifikasi) {
            'Y' => 'Terverifikasi',
            'N' => 'Ditolak',
            'P' => 'Pending',
            default => 'Unknown',
        };
    }

    /**
     * Get file URL
     */
    public function getFileUrlAttribute()
    {
        if ($this->file) {
            return asset('uploads/asesi/' . $this->file);
        }
        return null;
    }
}
