<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rekeningbayar extends Model
{
    protected $table = 'rekeningbayar';

    public $timestamps = false;

    protected $fillable = [
        'kode_lsp',
        'bank',
        'norek',
        'atasnama',
        'logo',
        'metode',
        'aktif',
    ];

    protected $casts = [
        'kode_lsp' => 'integer',
    ];

    /**
     * Relationship to LSP
     */
    public function lsp(): BelongsTo
    {
        return $this->belongsTo(Lsp::class, 'kode_lsp');
    }

    /**
     * Scope for active accounts
     */
    public function scopeActive($query)
    {
        return $query->where('aktif', 'Y');
    }

    /**
     * Scope for filtering by LSP
     */
    public function scopeByLsp($query, $lspId)
    {
        return $query->where('kode_lsp', $lspId);
    }

    /**
     * Scope for filtering by bank
     */
    public function scopeByBank($query, $bank)
    {
        return $query->where('bank', $bank);
    }

    /**
     * Get logo URL attribute
     */
    public function getLogoUrlAttribute(): string
    {
        if ($this->logo) {
            return asset('images/bank/' . $this->logo);
        }
        return '';
    }

    /**
     * Get logo filename based on bank name
     */
    public static function getLogoByBank($bank)
    {
        $logoMapping = [
            'Tunai' => '',
            'BRI' => 'bri.png',
            'BNI' => 'bni.png',
            'Mandiri' => 'mandiri.png',
            'BTN' => 'btn.png',
            'Bank Jateng' => 'bankjateng.png',
            'BCA' => 'bca.png',
            'CIMB Niaga' => 'cimbniaga.png',
            'CIMB NIAGA' => 'cimbniaga.png',
        ];

        return $logoMapping[$bank] ?? '';
    }

    /**
     * Check if combination is unique
     */
    public static function isUnique($lspId, $bank, $norek, $atasnama, $excludeId = null)
    {
        $query = self::where('kode_lsp', $lspId)
            ->where('bank', $bank)
            ->where('norek', $norek)
            ->where('atasnama', $atasnama);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return !$query->exists();
    }
}
