<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiwayatRespon extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'riwayat_ponses';

    protected $fillable = [
        'pengaduan_id',
        'tanggal',
        'waktu',
        'admin',
        'isi',
        'lampiran',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'waktu' => 'datetime:H:i',
    ];

    /**
     * Relationship dengan pengaduan
     */
    public function pengaduan(): BelongsTo
    {
        return $this->belongsTo(Pengaduan::class, 'pengaduan_id');
    }
}
