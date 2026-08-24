<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PengaduanRiwayat extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'pengaduan_riwayat';

    /**
     * The attributes that are mass assignable
     */
    protected $fillable = [
        'id_pengaduan',
        'status_sebelumnya',
        'status_baru',
        'aksi',
        'oleh',
        'catatan',
    ];

    /**
     * The attributes that should be cast
     */
    protected $casts = [
        'waktu' => 'datetime',
    ];

    /**
     * Disable timestamps (uses 'waktu' instead)
     */
    public $timestamps = false;

    /**
     * Get the pengaduan that owns the riwayat
     */
    public function pengaduan(): BelongsTo
    {
        return $this->belongsTo(Pengaduan::class, 'id_pengaduan');
    }

    /**
     * Scope to get history for a specific pengaduan
     */
    public function scopeForPengaduan($query, $pengaduanId)
    {
        return $query->where('id_pengaduan', $pengaduanId);
    }

    /**
     * Scope to filter by action type
     */
    public function scopeByAction($query, $action)
    {
        return $query->where('aksi', $action);
    }

    /**
     * Get action label
     */
    public function getAksiLabelAttribute(): string
    {
        return match($this->aksi) {
            'create' => 'Pengaduan Baru',
            'update' => 'Update Data',
            'respon' => 'Respon Admin',
            'status' => 'Update Status',
            'delete' => 'Hapus',
            default => ucfirst($this->aksi),
        };
    }
}
