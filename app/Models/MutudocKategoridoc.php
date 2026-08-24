<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MutudocKategoridoc extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'mutudoc_kategoridoc';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable
     */
    protected $fillable = [
        'kategori',
    ];

    /**
     * Relationship dengan dokumen
     */
    public function documents(): HasMany
    {
        return $this->hasMany(MutudocDoc::class, 'kategori');
    }
}
