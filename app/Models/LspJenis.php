<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LspJenis extends Model
{
    protected $table = 'lsp_jenis';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'kode',
        'nama_kategori',
        'deskripsi',
    ];

    /**
     * Relationship to LSP
     */
    public function lsps()
    {
        return $this->hasMany(Lsp::class, 'jenis_lsp', 'kode');
    }
}
