<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jurnal Transaksi Keuangan LSK (inlapkeu) — sesuai docs/BACKEND_KEUANGAN.md.
 *
 * Buku kas sederhana (bukan akuntansi penuh): pemasukan (IN) / pengeluaran (OUT)
 * dengan bukti scan kwitansi/invoice + audit clerk.
 *
 * Pemisahan pemasukan/pengeluaran terjadi SAAT RENDER (1 kolom nominal +
 * flag jenis_transaksi), bukan dua kolom debit/kredit.
 */
class KeuTransaksi extends Model
{
    protected $table = 'keu_transaksi';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'no_trx',            // No. Bukti/Transaksi/Invoice (dup-check by app)
        'nama',              // judul transaksi
        'jenis_transaksi',   // 'IN' (pemasukan) | 'OUT' (pengeluaran)
        'kode_akun',         // FK → keu_kodeakun.kode_akun (101-501)
        'nominal',           // rupiah integer
        'tgl_transaksi',     // tanggal transaksi (urutan list)
        'file',              // bukti scan di foto_lapkeuangan/ (boleh kosong)
        'clerk',             // username yang input/ubah (audit last-writer)
    ];

    protected $casts = [
        'nominal' => 'integer',
        'tgl_input' => 'datetime',
    ];

    protected $appends = [
        'file_url',
        'pemasukan',
        'pengeluaran',
        'nominal_formatted',
    ];

    /**
     * Relationship ke master kode akun (by kode_akun, bukan id)
     */
    public function kodeAkun(): BelongsTo
    {
        return $this->belongsTo(KeuKodeakun::class, 'kode_akun', 'kode_akun');
    }

    /**
     * URL bukti scan
     */
    public function getFileUrlAttribute(): ?string
    {
        if ($this->file) {
            return asset('foto_lapkeuangan/' . $this->file);
        }
        return null;
    }

    /**
     * Pemasukan (render kolom: nominal jika IN, else 0) — idem native
     */
    public function getPemasukanAttribute(): int
    {
        return $this->jenis_transaksi === 'IN' ? $this->nominal : 0;
    }

    /**
     * Pengeluaran (render kolom: nominal jika OUT, else 0)
     */
    public function getPengeluaranAttribute(): int
    {
        return $this->jenis_transaksi === 'OUT' ? $this->nominal : 0;
    }

    /**
     * Format ribuan Indonesia: 1.500.000
     */
    public function getNominalFormattedAttribute(): string
    {
        return number_format((float) ($this->nominal ?? 0), 0, ',', '.');
    }

    /**
     * Scope filter jenis transaksi
     */
    public function scopeJenis($query, $jenis)
    {
        if ($jenis) {
            return $query->where('jenis_transaksi', $jenis);
        }
        return $query;
    }

    /**
     * Scope filter rentang tanggal (laporan periode untuk BNSP — CQ #4)
     */
    public function scopePeriode($query, $dari, $sampai)
    {
        if ($dari && $sampai) {
            return $query->whereBetween('tgl_transaksi', [$dari, $sampai]);
        }
        return $query;
    }

    /**
     * Scope transaksi tanpa bukti scan (audit kelengkapan — CQ #5)
     */
    public function scopeTanpaBukti($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('file')->orWhere('file', '');
        });
    }

    /**
     * Scope search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where(function ($q) use ($search) {
                $q->where('no_trx', 'like', "%{$search}%")
                    ->orWhere('nama', 'like', "%{$search}%");
            });
        }
        return $query;
    }
}
