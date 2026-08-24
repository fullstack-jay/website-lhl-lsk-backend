<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkemaPersyaratantuk extends Model
{
    protected $table = 'skema_persyaratantuk';

    public $timestamps = false;

    protected $fillable = [
        'perlengkapan',
        'spesifikasi',
        'id_skemakkni',
    ];

    protected $casts = [
        'id_skemakkni' => 'integer',
    ];

    /**
     * Relationship to Skema KKNI
     */
    public function skemaKkni(): BelongsTo
    {
        return $this->belongsTo(SkemaKkni::class, 'id_skemakkni');
    }

    /**
     * Scope for filtering by skema
     */
    public function scopeBySkema($query, $skemaId)
    {
        return $query->where('id_skemakkni', $skemaId);
    }
}
