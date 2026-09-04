<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Log tanda tangan digital (tabel legacy `logdigisign`).
 *
 * Kolom `waktu` diisi MySQL DEFAULT CURRENT_TIMESTAMP —
 * jangan di-set manual saat INSERT.
 */
class Logdigisign extends Model
{
    protected $table = 'logdigisign';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'id_dokumen',
        'url_ditandatangani',
        'nama_dokumen',
        'penandatangan',
        'file',
        'ip',
    ];

    protected $casts = [
        'waktu' => 'datetime',
    ];
}
