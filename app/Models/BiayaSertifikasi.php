<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiayaSertifikasi extends Model
{
    protected $table = 'biaya_sertifikasi';

    public $timestamps = false;

    protected $fillable = [
        'id_lsp',
        'id_skkni',
        'id_skemakkni',
        'jenis_biaya',
        'nominal',
    ];

    protected $casts = [
        'id_lsp' => 'integer',
        'id_skkni' => 'integer',
        'id_skemakkni' => 'integer',
        'jenis_biaya' => 'integer',
        'nominal' => 'decimal:2',
    ];

    /**
     * Relationship to LSP
     */
    public function lsp(): BelongsTo
    {
        return $this->belongsTo(Lsp::class, 'id_lsp');
    }

    /**
     * Relationship to SKKNI
     */
    public function skkni(): BelongsTo
    {
        return $this->belongsTo(Skkni::class, 'id_skkni');
    }

    /**
     * Relationship to Skema KKNI
     */
    public function skemaKkni(): BelongsTo
    {
        return $this->belongsTo(SkemaKkni::class, 'id_skemakkni');
    }

    /**
     * Relationship to Jenis Biaya
     */
    public function jenisBiaya(): BelongsTo
    {
        return $this->belongsTo(BiayaJenis::class, 'jenis_biaya');
    }

    /**
     * Get formatted nominal attribute (Rupiah)
     */
    public function getNominalFormatAttribute(): string
    {
        return 'Rp. ' . number_format($this->nominal, 0, ',', '.');
    }

    /**
     * Scope for filtering by LSP
     */
    public function scopeByLsp($query, $lspId)
    {
        return $query->where('id_lsp', $lspId);
    }

    /**
     * Scope for filtering by SKKNI
     */
    public function scopeBySkkni($query, $skkniId)
    {
        return $query->where('id_skkni', $skkniId);
    }

    /**
     * Scope for filtering by Skema
     */
    public function scopeBySkema($query, $skemaId)
    {
        return $query->where('id_skemakkni', $skemaId);
    }

    /**
     * Scope for filtering by Jenis Biaya
     */
    public function scopeByJenisBiaya($query, $jenisId)
    {
        return $query->where('jenis_biaya', $jenisId);
    }

    /**
     * Check if combination is unique
     */
    public static function isUnique($lspId, $skkniId, $skemaId, $jenisBiayaId, $excludeId = null)
    {
        $query = self::where('id_lsp', $lspId)
            ->where('id_skkni', $skkniId)
            ->where('id_skemakkni', $skemaId)
            ->where('jenis_biaya', $jenisBiayaId);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return !$query->exists();
    }
}
