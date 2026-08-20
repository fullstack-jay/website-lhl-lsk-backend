<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Pendaftaran extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'no_pendaftaran',
        // Informasi Pribadi
        'nama',
        'email',
        'no_hp',
        'no_ktp',
        'kebangsaan',
        'kualifikasi_pendidikan',
        'bidang_keahlian',
        // Alamat
        'alamat',
        'propinsi',
        'kota',
        'kecamatan',
        'kelurahan',
        // Lokasi Uji Kompetensi
        'wil_ujikom',
        // Data Pekerjaan
        'nama_institusi',
        'jabatan',
        'alamat_kantor',
        'kode_pos',
        'no_telp_kantor',
        'no_fax_kantor',
        'email_kantor',
        // Password untuk akses sistem
        'password',
        // Status
        'status',
        'catatan',
        'tanggal_verifikasi',
        'verified_by',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'tanggal_verifikasi' => 'datetime',
    ];

    /**
     * Generate nomor pendaftaran otomatis
     * Format: REG-YYYYMMDD-XXXX
     */
    public static function generateNoPendaftaran(): string
    {
        $date = now()->format('Ymd');
        $prefix = "REG-{$date}-";

        $lastPendaftaran = self::withTrashed()
            ->where('no_pendaftaran', 'like', $prefix . '%')
            ->orderBy('no_pendaftaran', 'desc')
            ->first();

        if ($lastPendaftaran) {
            $lastNumber = (int) substr($lastPendaftaran->no_pendaftaran, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generate random password 6 digit angka
     * Format: 6 digit angka acak (contoh: 123456)
     */
    public static function generateRandomPassword(): string
    {
        $password = '';

        for ($i = 0; $i < 6; $i++) {
            $password .= random_int(0, 9);
        }

        return $password;
    }

    /**
     * Relationship dengan User (verifikator)
     * Note: Username is stored in verified_by field (VARCHAR)
     */
    public function verifikator()
    {
        return $this->belongsTo(User::class, 'verified_by', 'username');
    }

    /**
     * Scope untuk status tertentu
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function scopeDisetujui($query)
    {
        return $query->where('status', 'DISETUJUI');
    }

    public function scopeDitolak($query)
    {
        return $query->where('status', 'DITOLAK');
    }
}
