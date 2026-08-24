<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataWilayah extends Model
{
    protected $table = 'data_wilayah';

    public $timestamps = false;

    protected $primaryKey = 'id_wil';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id_wil',
        'nm_wil',
        'id_induk_wilayah',
        'id_level_wil',
    ];

    /**
     * Relationship to children wilayah
     */
    public function children()
    {
        return $this->hasMany(DataWilayah::class, 'id_induk_wilayah', 'id_wil');
    }

    /**
     * Relationship to parent wilayah
     */
    public function parent()
    {
        return $this->belongsTo(DataWilayah::class, 'id_induk_wilayah', 'id_wil');
    }

    /**
     * Scope for Provinsi (Level 1)
     */
    public function scopeProvinsi($query)
    {
        return $query->where('id_level_wil', 1);
    }

    /**
     * Scope for Kota/Kabupaten (Level 2)
     */
    public function scopeKota($query)
    {
        return $query->where('id_level_wil', 2);
    }

    /**
     * Scope for Kecamatan (Level 3)
     */
    public function scopeKecamatan($query)
    {
        return $query->where('id_level_wil', 3);
    }

    /**
     * Get wilayah by parent ID
     */
    public function scopeByParent($query, $parentId)
    {
        return $query->where('id_induk_wilayah', $parentId);
    }
}
