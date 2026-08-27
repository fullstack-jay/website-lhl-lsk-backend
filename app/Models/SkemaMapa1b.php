<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MAPA-01 Bagian 2: Mempersiapkan Rencana Asesmen
 *
 * Satu baris = satu KUK untuk satu kombinasi (skema × profil kandidat).
 * Unique key komposit: (id_skemakkni, profil_kandidat, id_unitkompetensi,
 * id_elemenkompetensi, id_kriteria) — sesuai uk_mapa1b_row di dokumentasi.
 */
class SkemaMapa1b extends Model
{
    protected $table = 'skema_mapa1b';

    public $timestamps = false;

    protected $fillable = [
        // ═══ RELASI / KUNCI COMPOSITE ═══
        'id_skemakkni',
        'profil_kandidat',
        'id_unitkompetensi',
        'id_elemenkompetensi',
        'id_kriteria',

        // ═══ BARIS 1 FORM: KANDIDAT TIPE A ═══
        'ket_bukti',      // textarea: Bukti-Bukti
        'bukti_L',        // checkbox 'L' = Langsung
        'bukti_TL',       // checkbox 'TL' = Tidak Langsung
        'bukti_T',        // checkbox 'T' = Tambahan
        'metode1',        // enum: CL/DIT/DPL/DPT/VP/CUP/PW
        'metode2',
        'metode3',
        'metode4',
        'metode5',
        'metode6',

        // ═══ BARIS 2 FORM: KANDIDAT TIPE B ═══
        'ket_bukti2',
        'bukti_L2',
        'bukti_TL2',
        'bukti_T2',
        'metode1t',
        'metode2t',
        'metode3t',       // satu-satunya dropdown aktif di form lama (baris 2)
        'metode4t',
        'metode5t',
        'metode6t',
    ];

    protected $casts = [
        'id_skemakkni' => 'integer',
        'profil_kandidat' => 'integer',
        'id_unitkompetensi' => 'integer',
        'id_elemenkompetensi' => 'integer',
        'id_kriteria' => 'integer',
    ];

    /**
     * Relationship to Skema KKNI
     */
    public function skemaKkni(): BelongsTo
    {
        return $this->belongsTo(SkemaKkni::class, 'id_skemakkni');
    }

    /**
     * Relationship to Unit Kompetensi
     */
    public function unitKompetensi(): BelongsTo
    {
        return $this->belongsTo(UnitKompetensi::class, 'id_unitkompetensi');
    }

    /**
     * Relationship to Elemen Kompetensi
     */
    public function elemenKompetensi(): BelongsTo
    {
        return $this->belongsTo(ElemenKompetensi::class, 'id_elemenkompetensi');
    }

    /**
     * Relationship to Kriteria Unjuk Kerja
     */
    public function kriteriaUnjukkerja(): BelongsTo
    {
        return $this->belongsTo(KriteriaUnjukkerja::class, 'id_kriteria');
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

    /**
     * Scope for filtering by unit kompetensi
     */
    public function scopeByUnit($query, $unitId)
    {
        return $query->where('id_unitkompetensi', $unitId);
    }

    /**
     * Daftar nilai enum metode & perangkat asesmen (referensi global).
     * Kolom metodeX/melopeXt hanya boleh berisi salah satu nilai ini.
     */
    public const METODE_OPTIONS = [
        'CL'  => 'Ceklis Observasi / Lembar Periksa',
        'DIT' => 'Daftar Instruksi Terstruktur',
        'DPL' => 'Daftar Pertanyaan Lisan',
        'DPT' => 'Daftar Pertanyaan Tertulis',
        'VP'  => 'Verifikasi Portofolio',
        'CUP' => 'Ceklis Ulasan Produk',
        'PW'  => 'Pertanyaan Wawancara',
    ];

    /**
     * Label human-readable untuk suatu kode metode.
     */
    public static function metodeLabel(?string $kode): ?string
    {
        if (!$kode || !isset(self::METODE_OPTIONS[$kode])) {
            return null;
        }
        return self::METODE_OPTIONS[$kode];
    }

    /**
     * Label jenis bukti dari flag gabungan (misal row toArray dengan bukti_*).
     */
    public static function buktiLabel(?string $flag): ?string
    {
        return match ($flag) {
            'L'  => 'Langsung',
            'TL' => 'Tidak Langsung',
            'T'  => 'Tambahan',
            default => null,
        };
    }
}
