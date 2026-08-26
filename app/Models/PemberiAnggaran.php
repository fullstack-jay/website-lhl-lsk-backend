<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PemberiAnggaran extends Model
{
    protected $table = 'pemberi_anggaran';

    public $timestamps = false;

    protected $fillable = [
        'nama_instansi',
    ];
}
