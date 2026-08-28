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
        'agama',
        'status_perkawinan',
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
        'tanggal_lisensi',
        'no_serisertifikat',
        'masaberlaku_lisensi',
        'foto_sertifikat',
        'facebook',
        'aktif',
    ];

    protected $casts = [
        'tgl_lahir' => 'date',
        'tanggal_lisensi' => 'date',
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

    // ════════════════════════════════════════════════════════════════
    // Logika modul Penguji (sesuai docs/BACKEND_PENGUJI.md)
    // ════════════════════════════════════════════════════════════════

    /** Ambang batas "segera kadaluarsa": 180 hari (6 bulan). */
    public const BATAS_SEGERA_KADALUARSA = 180;

    /**
     * Sisa hari lisensi dari hari ini (negatif = sudah kedaluwarsa).
     * Padanan $days_between di PHP Native.
     */
    public function getSisaHariLisensiAttribute(): ?int
    {
        if (!$this->masaberlaku_lisensi) {
            return null; // guard NULL (strtotime(null) deprecated PHP 8.1+)
        }
        return now()->startOfDay()->diffInDays($this->masaberlaku_lisensi->copy()->startOfDay(), false);
    }

    /**
     * Status lisensi: kadaluarsa / segera / aktif — padanan logika warna kartu.
     * <0 → KADALUARSA (merah) · 0..179 → SEGERA (kuning) · >=180 → AKTIF (hijau)
     */
    public function getStatusLisensiAttribute(): string
    {
        $sisa = $this->sisa_hari_lisensi;
        if ($sisa === null || $sisa < 0) return 'KADALUARSA';
        if ($sisa < self::BATAS_SEGERA_KADALUARSA) return 'SEGERA';
        return 'AKTIF';
    }

    /**
     * Warna header kartu untuk frontend (padanan bg-red/bg-yellow/bg-green).
     */
    public function getWarnaKartuAttribute(): string
    {
        return match ($this->status_lisensi) {
            'AKTIF' => 'green',
            'SEGERA' => 'yellow',
            default => 'red',
        };
    }

    /**
     * Cek kelengkapan dokumen pokok: foto, ktp, kk, ijazah, transkrip.
     * Semua ada → lengkap=true; else listing yang kurang.
     */
    public function getKelengkapanDokumenAttribute(): array
    {
        $fields = ['foto', 'ktp', 'kk', 'ijazah', 'transkrip'];
        $labels = [
            'foto' => 'Foto', 'ktp' => 'KTP', 'kk' => 'KK',
            'ijazah' => 'Ijazah', 'transkrip' => 'Transkrip',
        ];
        $kurang = [];
        foreach ($fields as $f) {
            if (empty($this->{$f})) {
                $kurang[] = $labels[$f];
            }
        }
        return [
            'lengkap' => empty($kurang),
            'kurang' => $kurang,
        ];
    }
}
