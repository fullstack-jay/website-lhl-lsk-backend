<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SumberAnggaran extends Model
{
    protected $table = 'sumber_anggaran';

    public $timestamps = false;

    protected $fillable = [
        'jenis_anggaran',
    ];

    /**
     * Relationship to JadwalAsesmen
     */
    public function jadwalAsesmen(): HasMany
    {
        return $this->hasMany(JadwalAsesmen::class, 'sumber_anggaran');
    }
}
