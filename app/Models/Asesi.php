<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Asesi extends Model
{
    protected $table = 'asesi';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'no_pendaftaran',
        'password',
        'nama',
        'tmp_lahir',
        'tgl_lahir',
        'usia',
        'email',
        'nohp',
        'no_ktp',
        'alamat',
        'RT',
        'RW',
        'kelurahan',
        'kecamatan',
        'kota',
        'propinsi',
        'kodepos',
        'pendidikan',
        'lembaga_pendidikan',
        'agama',
        'prodi',
        'tahun_lulus',
        'kebangsaan',
        'jenis_kelamin',
        'foto',
        'ktp',
        'kk',
        'ijazah',
        'tgl_ijazah',
        'transkrip',
        'suket',
        'cv',
        'sertifikat',
        'pekerjaan',
        'jabatan',
        'nama_kantor',
        'alamat_kantor',
        'telp_kantor',
        'fax_kantor',
        'email_kantor',
        'tgl_daftar',
        'angkatan',
        'blokir',
        'verifikasi',
        'id_pengusul',
        'wil_ujikom',
    ];

    protected $casts = [
        'tgl_lahir' => 'date',
        'tgl_ijazah' => 'date',
        'tgl_daftar' => 'date',
        'tahun_lulus' => 'integer',
        'usia' => 'integer',
        'angkatan' => 'integer',
        'blokir' => 'boolean',
        'waktu' => 'datetime',
    ];

    /**
     * Relationship to JadwalAsesmen through asesi_asesmen
     */
    public function jadwalAsesmen(): BelongsToMany
    {
        return $this->belongsToMany(JadwalAsesmen::class, 'asesi_asesmen', 'id_asesi', 'id_jadwal')
            ->withPivot('status_asesmen', 'keputusan_asesor', 'no_sertifikat', 'peninjau_ia11', 'biaya', 'tgl_asesmen', 'no_surattugas');
    }

    /**
     * Get full name
     */
    public function getFullNameAttribute()
    {
        return $this->nama;
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
     * Scope for active asesi
     */
    public function scopeActive($query)
    {
        return $query->where('blokir', 'N');
    }

    /**
     * Scope for verified asesi
     */
    public function scopeVerified($query)
    {
        return $query->where('verifikasi', 'V');
    }

    /**
     * Scope for search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where('nama', 'like', '%' . $search . '%')
                ->orWhere('no_pendaftaran', 'like', '%' . $search . '%')
                ->orWhere('no_ktp', 'like', '%' . $search . '%')
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
            return asset('uploads/asesi/' . $this->{$fileField});
        }
        return null;
    }
}
