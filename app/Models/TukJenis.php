<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TukJenis extends Model
{
    protected $table = 'tuk_jenis';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'jenis_tuk',
    ];

    /**
     * Relationship to TUK
     */
    public function tuks(): HasMany
    {
        return $this->hasMany(Tuk::class, 'jenis_tuk');
    }

    /**
     * Scope for search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where('jenis_tuk', 'like', '%' . $search . '%');
        }
        return $query;
    }
}
