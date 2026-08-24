<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkemaPersyaratan extends Model
{
    protected $table = 'skema_persyaratan';

    public $timestamps = false;

    protected $fillable = [
        'persyaratan',
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
