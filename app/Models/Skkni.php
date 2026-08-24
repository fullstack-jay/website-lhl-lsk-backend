<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Skkni extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'skkni';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable
     */
    protected $fillable = [
        'no_skkni',
        'nama',
        'jenis_standar',
        'sektor',
        'subsektor',
        'legalitas',
        'penyusun',
        'file',
    ];

    /**
     * The attributes that should be cast
     */
    protected $casts = [
        // No date casts needed
    ];

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = [
        'file_url',
    ];

    /**
     * Get file URL
     */
    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file) {
            return null;
        }

        return url('foto_skkni/' . $this->file);
    }

    /**
     * Scope untuk search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where(function($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('no_skkni', 'like', "%{$search}%")
                  ->orWhere('sektor', 'like', "%{$search}%");
            });
        }
        return $query;
    }

    /**
     * Scope untuk filter jenis standar
     */
    public function scopeJenisStandar($query, $jenis)
    {
        if ($jenis && in_array($jenis, ['SKKNI', 'SKK', 'SI'])) {
            return $query->where('jenis_standar', $jenis);
        }
        return $query;
    }
}
