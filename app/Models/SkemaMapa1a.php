<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Rencana Asesmen MAPA-01 Bagian 1 (Pendekatan Asesmen)
 *
 * Satu baris per kombinasi (skema × profil kandidat), maksimal 5 baris per skema
 * (Tipe Kandidat 1 s/d 5). Sesuai dokumentasi input-ubah-renc-asesmen.md.
 *
 * CATATAN KOLOM KHUSUS:
 * Kolom rating di database memakai tanda hubung: 'konteks_c1-1', 'konteks_c2-1',
 * 'konteks_c3-1'. Karena sintaks PHP ($obj->properti) tidak mendukung '-',
 * kolom tersebut dipetakan lewat mutator/accessor menjadi:
 *   konteks_c11 -> kolom DB "konteks_c1-1"
 *   konteks_c21 -> kolom DB "konteks_c2-1"
 *   konteks_c31 -> kolom DB "konteks_c3-1"
 */
class SkemaMapa1a extends Model
{
    protected $table = 'skema_mapa1a';

    public $timestamps = false;

    protected $fillable = [
        // Relasi
        'id_skemakkni',
        'profil_kandidat',

        // 1.1 Pendekatan Asesmen
        'pendekatan',       // enum-like: 1=Pelatihan/Pendidikan, 2=Pekerja berpengalaman, 3=Belajar mandiri
        'pendekatan_ket',   // nama lembaga (jika pendekatan=1)
        'tujuan',           // enum-like: 1=Sertifikasi .. 5=Lainnya
        'tujuanket',        // keterangan (jika tujuan=5)

        // Konteks Asesmen
        'konteks_a',        // Lingkungan: 1=Nyata, 2=Simulasi
        'konteks_b',        // Peluang bukti: 1=Tersedia, 2=Terbatas
        'konteks_c1',       // checkbox: Bukti mendukung asesmen/RPL
        'konteks_c11',      // rating 1=Like, 2=Neutral, 3=Dislike -> kolom "konteks_c1-1"
        'konteks_c2',       // checkbox: Aktivitas kerja di tempat kerja peserta
        'konteks_c21',      // rating -> kolom "konteks_c2-1"
        'konteks_c3',       // checkbox: Kegiatan Pembelajaran
        'konteks_c31',      // rating -> kolom "konteks_c3-1"
        'konteks_d',        // Siapa mengases: 1=Lembaga Sertifikasi, 2=Organisasi Pelatihan, 3=Penguji Perusahaan

        // Konfirmasi dengan pihak relevan (checkbox multiple)
        'konfirmasi1',      // Manajer sertifikasi LSK
        'konfirmasi2',      // Master Penguji / Master Trainer / Penguji Utama
        'konfirmasi3',      // Manajer Pelatihan Lembaga Training
        'konfirmasi4',      // Lainnya
        'konfirmasi4_ket',  // keterangan "Lainnya"

        // 1.2 Tolok Ukur Asesmen (checkbox multiple + keterangan)
        'toluk1',           // Standar Kompetensi SKKNI
        'toluk1_ket',       // nomor SKKNI/SKK/SI
        'toluk2',           // Kriteria asesmen dari kurikulum pelatihan
        'toluk2_ket',
        'toluk3',           // Spesifikasi kinerja perusahaan/industri
        'toluk3_ket',
        'toluk4',           // Spesifikasi Produk
        'toluk4_ket',
        'toluk5',           // Pedoman khusus
        'toluk5_ket',

        // Kolom tambahan tabel lama (dipertahankan agar tidak hilang saat update)
        'modkon1a', 'modkon1a_ket',
        'modkon1b', 'modkon1b_ket',
        'modkon2', 'modkon2_ket',
        'modkon3', 'modkon3_ket',
        'modkon4', 'modkon4_ket',
        'konfirm1', 'konfirm1_ket',
        'konfirm2', 'konfirm2_ket',
        'konfirm3', 'konfirm3_ket',
        'konfirm4', 'konfirm4_ket',
    ];

    protected $casts = [
        'id_skemakkni' => 'integer',
        'profil_kandidat' => 'integer',
    ];

    /**
     * Agar kolom bertanda hubung ("konteks_c1-1" dst) ikut ter-serialize di JSON
     * dengan nama tanpa hubung.
     */
    protected $appends = ['konteks_c11', 'konteks_c21', 'konteks_c31'];

    /**
     * Aliases agar serializer JSON juga mengeluarkan format tanpa tanda hubung.
     * (Kolom mentah "konteks_c1-1" tetap ikut keluar karena berasal dari row DB.)
     */
    public function getKonteksC11Attribute()
    {
        return $this->attributes['konteks_c1-1'] ?? null;
    }

    public function setKonteksC11Attribute($value)
    {
        $this->attributes['konteks_c1-1'] = $value !== '' && $value !== null ? (string) $value : null;
    }

    public function getKonteksC21Attribute()
    {
        return $this->attributes['konteks_c2-1'] ?? null;
    }

    public function setKonteksC21Attribute($value)
    {
        $this->attributes['konteks_c2-1'] = $value !== '' && $value !== null ? (string) $value : null;
    }

    public function getKonteksC31Attribute()
    {
        return $this->attributes['konteks_c3-1'] ?? null;
    }

    public function setKonteksC31Attribute($value)
    {
        $this->attributes['konteks_c3-1'] = $value !== '' && $value !== null ? (string) $value : null;
    }

    /**
     * Label human-readable untuk tiap nilai enum (mengikuti mapping dokumentasi).
     */
    public function getPendekatanLabelAttribute(): ?string
    {
        return match ((string) $this->pendekatan) {
            '1' => 'Hasil pelatihan dan/atau pendidikan',
            '2' => 'Pekerja berpengalaman',
            '3' => 'Pelatihan / belajar mandiri',
            default => null,
        };
    }

    public function getTujuanLabelAttribute(): ?string
    {
        return match ((string) $this->tujuan) {
            '1' => 'Sertifikasi',
            '2' => 'Sertifikasi Ulang',
            '3' => 'Pengakuan Kompetensi Terkini (PKT)',
            '4' => 'Rekognisi Pembelajaran Lampau',
            '5' => 'Lainnya',
            default => null,
        };
    }

    public function getKonteksDLabelAttribute(): ?string
    {
        return match ((string) $this->konteks_d) {
            '1' => 'Lembaga Sertifikasi',
            '2' => 'Organisasi Pelatihan',
            '3' => 'Penguji Perusahaan',
            default => null,
        };
    }

    /**
     * Relationship to Skema KKNI
     */
    public function skemaKkni(): BelongsTo
    {
        return $this->belongsTo(SkemaKkni::class, 'id_skemakkni');
    }

    /**
     * Scope for filtering by skema
     */
    public function scopeBySkema($query, $skemaId)
    {
        return $query->where('id_skemakkni', $skemaId);
    }

    /**
     * Scope for filtering by profil kandidat
     */
    public function scopeByProfil($query, $profil)
    {
        return $query->where('profil_kandidat', $profil);
    }
}
