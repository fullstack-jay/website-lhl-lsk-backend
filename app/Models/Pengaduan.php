<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pengaduan extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'pengaduan';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable
     */
    protected $fillable = [
        'no_pengaduan',
        'tanggal',
        'waktu',
        'nama',
        'email',
        'nohp',
        'jenis_responden',
        'aduan',
        'lampiran',
        'status',
        'respon_admin',
        'tgl_respon',
        'catatan_internal',
        'dibaca',
        'dibaca_oleh',
        'dibaca_tanggal',
        'ip_address',
    ];

    /**
     * The attributes that should be cast
     */
    protected $casts = [
        'dibaca' => 'boolean',
        'dibaca_tanggal' => 'datetime',
        'tgl_respon' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = [
        'tanggal_waktu', // Combined tanggal + waktu (YYYY-MM-DD HH:MM)
        'tanggal_waktu_formatted', // Formatted in Indonesian
    ];

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * Relationship dengan riwayat respon
     */
    public function riwayatRespon(): HasMany
    {
        return $this->hasMany(RiwayatRespon::class, 'pengaduan_id')->orderBy('created_at', 'asc');
    }

    /**
     * Get combined tanggal and waktu
     */
    public function getTanggalWaktuAttribute(): string
    {
        if (!$this->tanggal) {
            return $this->tgl_aduan ?? '';
        }

        $date = \Carbon\Carbon::parse($this->tanggal);
        $time = $this->waktu ? substr($this->waktu, 0, 5) : '00:00'; // HH:MM format

        return $date->format('Y-m-d') . ' ' . $time;
    }

    /**
     * Get formatted tanggal and waktu in Indonesian
     * Format: "24 Agustus 2026 pukul 15.32"
     */
    public function getTanggalWaktuFormattedAttribute(): string
    {
        if (!$this->tanggal) {
            return '-';
        }

        $date = \Carbon\Carbon::parse($this->tanggal);
        $time = $this->waktu ? substr($this->waktu, 0, 5) : '00:00'; // HH:MM format

        $bulanIndonesia = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        $day = $date->day;
        $month = $bulanIndonesia[$date->month] ?? '';
        $year = $date->year;

        // Convert time HH:MM to HH.MM
        $timeFormatted = str_replace(':', '.', $time);

        return "{$day} {$month} {$year} pukul {$timeFormatted}";
    }

    /**
     * Generate nomor pengaduan otomatis
     * Format: ADU-YYYYMM-XXX
     */
    public static function generateNomorPengaduan(): string
    {
        $yearMonth = now()->format('Ym');
        $count = self::withTrashed()
                     ->whereYear('tanggal', now()->year)
                     ->whereMonth('tanggal', now()->month)
                     ->count() + 1;

        return 'ADU-' . $yearMonth . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Scope untuk filter status
     */
    public function scopeStatus($query, $status)
    {
        if ($status && $status !== 'all') {
            return $query->where('status', $status);
        }
        return $query;
    }

    /**
     * Scope untuk search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where(function($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('no_hp', 'like', "%{$search}%")
                  ->orWhere('aduan', 'like', "%{$search}%")
                  ->orWhere('no_pengaduan', 'like', "%{$search}%");
            });
        }
        return $query;
    }

    /**
     * Scope untuk filter jenis responden
     */
    public function scopeJenisResponden($query, $jenis)
    {
        if ($jenis) {
            return $query->where('jenis_responden', $jenis);
        }
        return $query;
    }

    /**
     * Scope untuk filter tanggal
     */
    public function scopeTanggalRange($query, $startDate, $endDate)
    {
        if ($startDate && $endDate) {
            return $query->whereBetween('tanggal', [$startDate, $endDate]);
        }
        return $query;
    }

    /**
     * Get counts by status
     */
    public static function getCountsByStatus(): array
    {
        return [
            'all' => self::withTrashed()->count(),
            'waiting' => self::where('status', 'waiting')->count(),
            'processing' => self::where('status', 'processing')->count(),
            'completed' => self::where('status', 'completed')->count(),
            'archived' => self::where('status', 'archived')->count(),
        ];
    }
}
