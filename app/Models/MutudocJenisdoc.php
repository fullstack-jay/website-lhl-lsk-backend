<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MutudocJenisdoc extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'mutudoc_jenisdoc';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable
     */
    protected $fillable = [
        'jenis',
    ];

    /**
     * Relationship dengan dokumen
     */
    public function documents(): HasMany
    {
        return $this->hasMany(MutudocDoc::class, 'jenis');
    }

    /**
     * Scope untuk get active types
     */
    public function scopeActive($query)
    {
        return $query->whereBetween('id', [1, 5]);
    }
}
