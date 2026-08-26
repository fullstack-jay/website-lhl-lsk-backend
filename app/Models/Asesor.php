<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Asesor extends Model
{
    protected $table = 'asesor';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'password',
        'nama',
        'gelar_depan',
        'gelar_blk',
        'inisial',
        'jenis_kelamin',
        'tmp_lahir',
        'tgl_lahir',
        'usia',
        'foto',
        'email',
        'no_hp',
        'no_induk',
        'no_ktp',
        'pendidikan_terakhir',
        'tahun_lulus',
        'bid_keahlian',
        'pekerjaan',
        'kebangsaan',
        'alamat',
        'RT',
        'RW',
        'kelurahan',
        'kecamatan',
        'kota',
        'propinsi',
        'kodepos',
        'institusi_asal',
        'telp_kantor',
        'fax_kantor',
        'email_kantor',
        'no_lisensi',
        'no_serisertifikat',
        'masaberlaku_lisensi',
        'foto_sertifikat',
        'facebook',
        'aktif',
    ];

    protected $casts = [
        'tgl_lahir' => 'date',
        'masaberlaku_lisensi' => 'date',
        'usia' => 'integer',
        'tahun_lulus' => 'integer',
        'aktif' => 'boolean',
    ];

    /**
     * Relationship to JadwalAsesmen through jadwal_asesor
     */
    public function jadwalAsesmen(): BelongsToMany
    {
        return $this->belongsToMany(JadwalAsesmen::class, 'jadwal_asesor', 'id_asesor', 'id_jadwal');
    }

    /**
     * Get full name with titles
     */
    public function getFullNameAttribute()
    {
        $gelarDepan = $this->gelar_depan ? $this->gelar_depan . ' ' : '';
        $gelarBlk = $this->gelar_blk ? ', ' . $this->gelar_blk : '';
        return $gelarDepan . $this->nama . $gelarBlk;
    }

    /**
     * Get age from tgl_lahir
     */
    public function getAgeFromDobAttribute()
    {
        if ($this->tgl_lahir) {
            return $this->tgl_lahir->age;
        }
        return null;
    }

    /**
     * Scope for active asesor
     */
    public function scopeActive($query)
    {
        return $query->where('aktif', 'Y');
    }

    /**
     * Scope for search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where('nama', 'like', '%' . $search . '%')
                ->orWhere('no_lisensi', 'like', '%' . $search . '%')
                ->orWhere('no_induk', 'like', '%' . $search . '%')
                ->orWhere('email', 'like', '%' . $search . '%');
        }
        return $query;
    }

    /**
     * Get file URL helper
     */
    public function getFileUrlAttribute($fileField)
    {
        if ($this->{$fileField}) {
            return asset('uploads/asesor/' . $this->{$fileField});
        }
        return null;
    }

    /**
     * Get lisensi status
     */
    public function getLisensiValidAttribute()
    {
        if ($this->masaberlaku_lisensi) {
            return $this->masaberlaku_lisensi->isFuture() || $this->masaberlaku_lisensi->isToday();
        }
        return false;
    }
}
