<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pilihan unit kompetensi peserta untuk satu pendaftaran skema
 * (tabel legacy — kolom bertipe text, tanpa timestamps).
 *
 * Pola sinkronisasi: DELETE semua baris (id_asesi + id_skemakkni)
 * lalu INSERT ulang unit yang tercentang (docs/BACKEND_PESERTA_SKEMA_SERTIFIKASI.md
 * step 5 daftarasesmen).
 */
class AsesmenUnitkompetensi extends Model
{
    protected $table = 'asesmen_unitkompetensi';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'id_asesi',
        'id_skemakkni',
        'id_unitkompetensi',
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
     * Relationship to UnitKompetensi
     */
    public function unitKompetensi(): BelongsTo
    {
        return $this->belongsTo(UnitKompetensi::class, 'id_unitkompetensi');
    }
}
