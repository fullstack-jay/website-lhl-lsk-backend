<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan Dokumen Pokok Peserta (devsyarat) — sesuai docs/BACKEND_DEVSYARAT.md.
 *
 * Tabel KONFIGURASI (bukan repositori file!): menentukan dokumen persyaratan
 * apa saja yang diminta dari calon peserta saat pendaftaran.
 *
 * ⭐ POLA SHORTCODE: kolom `shortcode` = NAMA KOLOM AKTUAL di tabel `asesi`
 * (foto/ktp/kk/ijazah/transkrip/suket/cv). Relasi by-name (string mapping),
 * bukan FK — integritas dijaga konvensi.
 *
 * TWO-FLAG DESIGN (independen):
 * - wajib='Y' → dicek admin (badge Ada/Belum Ada) + validasi kelengkapan
 * - aktif='Y' → tampil di dropdown upload peserta
 *
 * Fixture 7 dokumen — TIDAK ada create/delete (konfigurasi-only module);
 * hanya toggle 2 flag via 4 handler.
 */
class AsesiPersyaratanpokok extends Model
{
    protected $table = 'asesi_persyaratanpokok';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'persyaratan',   // label tampil: "Pas Foto", "KTP", ...
        'shortcode',     // ⭐ nama kolom aktual di tabel asesi
        'aktif',         // Y = dokumen berlaku (tampil di form peserta)
        'wajib',         // Y = wajib / N = tambahan (opsional)
    ];

    /**
     * Scope: dokumen WAJIB (untuk cek kelengkapan admin + validasi skor)
     * idem konsumen native: SELECT ... WHERE wajib='Y'
     */
    public function scopeWajib($query)
    {
        return $query->where('wajib', 'Y');
    }

    /**
     * Scope: dokumen AKTIF (untuk dropdown upload peserta)
     * idem konsumen native: SELECT ... WHERE aktif='Y' ORDER BY id
     */
    public function scopeAktif($query)
    {
        return $query->where('aktif', 'Y');
    }

    /**
     * Scope: dokumen wajib DAN aktif (teks daftar persyaratan)
     */
    public function scopeWajibAktif($query)
    {
        return $query->where('wajib', 'Y')->where('aktif', 'Y');
    }

    /**
     * Sifat dokumen label
     */
    public function getSifatLabelAttribute(): string
    {
        return $this->wajib === 'Y' ? 'Wajib' : 'Tambahan (Opsional)';
    }

    /**
     * Status aktif label
     */
    public function getAktifLabelAttribute(): string
    {
        return $this->aktif === 'Y' ? 'Aktif' : 'Tidak Aktif';
    }

    /**
     * Audit shortcode: apakah nama kolom ini benar-benar ada di tabel asesi?
     * (relasi by-name rawan typo — Common Query #6)
     */
    public function shortcodeValid(): bool
    {
        try {
            return \DB::selectOne(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['asesi', $this->shortcode]
            ) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
