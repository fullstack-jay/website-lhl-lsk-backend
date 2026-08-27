<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsesiPembayaran extends Model
{
    protected $table = 'asesi_pembayaran';

    public $timestamps = false;

    protected $fillable = [
        'id_asesi',
        'id_skemakkni',
        'tanggal_bayar',
        'jumlah_bayar',
        'bukti_bayar',
        'status_bayar',
        'verifikasi_bayar',
    ];

    protected $casts = [
        'tanggal_bayar' => 'date',
        'jumlah_bayar' => 'decimal:2',
        'verifikasi_bayar' => 'boolean',
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
     * Scope for verified payments
     */
    public function scopeVerified($query)
    {
        return $query->where('verifikasi_bayar', 'Y');
    }

    /**
     * Scope for lunas
     */
    public function scopeLunas($query)
    {
        return $query->where('status_bayar', 'Lunas');
    }

    /**
     * Scope for DP (Down Payment)
     */
    public function scopeDP($query)
    {
        return $query->where('status_bayar', 'DP');
    }

    /**
     * Scope for belum bayar
     */
    public function scopeBelum($query)
    {
        return $query->where('status_bayar', 'Belum');
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute()
    {
        return match($this->status_bayar) {
            'Lunas', 'lunas' => 'Lunas',
            'DP', 'dp' => 'Down Payment',
            'Belum', 'belum' => 'Belum Bayar',
            default => 'Unknown',
        };
    }

    /**
     * Check if verified
     */
    public function isVerified()
    {
        return $this->verifikasi_bayar === 'Y' || $this->verifikasi_bayar === true;
    }

    /**
     * Get file URL
     */
    public function getBuktiUrlAttribute()
    {
        if ($this->bukti_bayar) {
            return asset('uploads/pembayaran/' . $this->bukti_bayar);
        }
        return null;
    }
}
