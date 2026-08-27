<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            ->withPivot('status_asesmen', 'status', 'peninjau_ia11', 'tgl_asesmen');
    }

    /**
     * Relationship to SkemaKkni through asesi_asesmen
     */
    public function skema(): BelongsToMany
    {
        return $this->belongsToMany(SkemaKkni::class, 'asesi_asesmen', 'id_asesi', 'id_skemakkni')
            ->withPivot('status', 'status_asesmen', 'no_lisensi', 'no_serisertifikat', 'masa_berlaku', 'foto_sertifikat');
    }

    /**
     * Relationship to Asesor through asesi_asesmen
     */
    public function asesor(): BelongsToMany
    {
        return $this->belongsToMany(Asesor::class, 'asesi_asesmen', 'id_asesi', 'id_asesor')
            ->withPivot('id_skemakkni', 'id_jadwal');
    }

    /**
     * Get dokumen peserta
     */
    public function dokumen(): HasMany
    {
        return $this->hasMany(AsesiDoc::class, 'id_asesi', 'no_pendaftaran');
    }

    /**
     * Get pembayaran peserta
     */
    public function pembayaran(): HasMany
    {
        return $this->hasMany(AsesiPembayaran::class, 'id_asesi', 'no_pendaftaran');
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
     * Get WhatsApp number (convert 08xx to 628xx)
     */
    public function getWhatsappAttribute()
    {
        if ($this->nohp) {
            $nohp = preg_replace('/[^0-9]/', '', $this->nohp);
            if (substr($nohp, 0, 1) === '0') {
                return '62' . substr($nohp, 1);
            }
            return $nohp;
        }
        return null;
    }

    /**
     * Get dokumen pokok status
     */
    public function getDokumenPokokAttribute()
    {
        return [
            'foto' => $this->foto !== null,
            'ktp' => $this->ktp !== null,
            'kk' => $this->kk !== null,
            'ijazah' => $this->ijazah !== null,
            'transkrip' => $this->transkrip !== null,
        ];
    }

    /**
     * Check if all dokumen pokok lengkap
     */
    public function getDokumenLengkapAttribute()
    {
        $dokumen_pokok = $this->dokumen_pokok;
        return collect($dokumen_pokok)->every(fn($status) => $status === true);
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute()
    {
        if ($this->blokir) {
            return 'Diblokir';
        }

        if ($this->verifikasi === 'P') {
            return 'Belum Terverifikasi';
        }

        return 'Terverifikasi';
    }

    /**
     * Get statistik asesmen
     */
    public function getStatistikAsesmenAttribute()
    {
        $totalSkema = $this->skema()->count();
        $skemaKompeten = $this->skema()->wherePivot('status_asesmen', 'K')->count();
        $skemaBelumKompeten = $this->skema()->wherePivot('status_asesmen', 'BK')->count();

        return [
            'total_skema' => $totalSkema,
            'kompeten' => $skemaKompeten,
            'belum_kompeten' => $skemaBelumKompeten,
        ];
    }

    /**
     * Scope for active asesi (not blocked)
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
     * Scope for pending verification
     */
    public function scopePending($query)
    {
        return $query->where('verifikasi', 'P');
    }

    /**
     * Scope for blocked asesi
     */
    public function scopeBlocked($query)
    {
        return $query->where('blokir', 'Y');
    }

    /**
     * Scope by angkatan
     */
    public function scopeByAngkatan($query, $angkatan)
    {
        return $query->where('angkatan', $angkatan);
    }

    /**
     * Scope by propinsi
     */
    public function scopeByPropinsi($query, $propinsi)
    {
        return $query->where('propinsi', $propinsi);
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
                ->orWhere('email', 'like', '%' . $search . '%')
                ->orWhere('nohp', 'like', '%' . $search . '%');
        }
        return $query;
    }

    /**
     * Get file URL helper
     */
    public function getFileUrl($fileField)
    {
        if ($this->{$fileField}) {
            return asset('uploads/asesi/' . $this->{$fileField});
        }
        return null;
    }

    /**
     * Generate nomor pendaftaran otomatis
     */
    public static function generateNoPendaftaran()
    {
        $date = now();
        $prefix = $date->format('Ymd');

        // Get last registration number for today
        $last = self::where('no_pendaftaran', 'like', $prefix . '%')
            ->orderBy('no_pendaftaran', 'desc')
            ->first();

        if ($last) {
            $lastNumber = (int) substr($last->no_pendaftaran, -3);
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }

        return $prefix . $newNumber;
    }
}
