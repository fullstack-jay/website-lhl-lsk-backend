<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master Kode Akun (chart of accounts mini) — 6 akun aktual:
 * 101 Kas, 102 Piutang, 201 Utang, 301 Modal, 401 Pendapatan, 501 Beban/Biaya.
 */
class KeuKodeakun extends Model
{
    protected $table = 'keu_kodeakun';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'kode_akun',
        'keterangan',
    ];

    /**
     * Transaksi-transaksi di akun ini
     */
    public function transaksi(): HasMany
    {
        return $this->hasMany(KeuTransaksi::class, 'kode_akun', 'kode_akun');
    }
}
