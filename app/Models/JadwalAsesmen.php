<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JadwalAsesmen extends Model
{
    protected $table = 'jadwal_asesmen';

    public $timestamps = false;

    protected $fillable = [
        'id_event',
        'nama_kegiatan',
        'tahun',
        'periode',
        'gelombang',
        'tgl_asesmen',
        'tgl_asesmen_akhir',
        'jam_asesmen',
        'tempat_asesmen',
        'id_asesor',
        'no_surattugas',
        'file_surattugas',
        'no_surattugaskomtek',
        'tgl_surattugaskomtek',
        'file_surattugaskomtek',
        'no_surattugasia11',
        'tgl_surattugasia11',
        'file_surattugasia11',
        'no_bakomite',
        'file_bakomite',
        'no_skkeputusan',
        'file_skkeputusan',
        'no_permohonanblangko',
        'file_permohonanblangko',
        'kapasitas',
        'id_skemakkni',
        'dok_standarkompetensi',
        'sumber_anggaran',
        'pemberi_anggaran',
        'pelaksanaan_uji',
        'kodejadwal_bnsp',
        'id_jadwalbnsp',
        'asesor_mkva1',
        'asesor_mkva2',
        'voting_komite',
        'status',
    ];

    protected $casts = [
        'tgl_asesmen' => 'date',
        'tgl_asesmen_akhir' => 'date',
        'tgl_surattugaskomtek' => 'date',
        'tgl_surattugasia11' => 'date',
        'tahun' => 'integer',
        'gelombang' => 'integer',
        'kapasitas' => 'integer',
        'id_jadwalbnsp' => 'integer',
    ];

    /**
     * Relationship to SkemaKkni
     */
    public function skema(): BelongsTo
    {
        return $this->belongsTo(SkemaKkni::class, 'id_skemakkni');
    }

    /**
     * Relationship to Tuk
     */
    public function tuk(): BelongsTo
    {
        return $this->belongsTo(Tuk::class, 'tempat_asesmen');
    }

    /**
     * Relationship to SumberAnggaran
     */
    public function sumberAnggaran(): BelongsTo
    {
        return $this->belongsTo(SumberAnggaran::class, 'sumber_anggaran');
    }

    /**
     * Relationship to PemberiAnggaran
     */
    public function pemberiAnggaran(): BelongsTo
    {
        return $this->belongsTo(PemberiAnggaran::class, 'pemberi_anggaran');
    }

    /**
     * Relationship to Asesor through jadwal_asesor
     */
    public function asesor(): BelongsToMany
    {
        return $this->belongsToMany(Asesor::class, 'jadwal_asesor', 'id_jadwal', 'id_asesor');
    }

    /**
     * Relationship to Asesi through asesi_asesmen
     */
    public function asesi(): BelongsToMany
    {
        return $this->belongsToMany(Asesi::class, 'asesi_asesmen', 'id_jadwal', 'id_asesi')
            ->withPivot('status_asesmen', 'keputusan_asesor', 'no_sertifikat', 'peninjau_ia11');
    }

    /**
     * Relationship to Komite through komite_keputusan
     */
    public function komite(): BelongsToMany
    {
        return $this->belongsToMany(Komite::class, 'komite_keputusan', 'id_jadwal', 'id_komite')
            ->withPivot('keputusan', 'waktu');
    }

    /**
     * Relationship to SkemaCeklisvertuk
     */
    public function verifikasiTuk(): HasMany
    {
        return $this->hasMany(SkemaCeklisvertuk::class, 'id_jadwal');
    }

    /**
     * Get jumlah peserta terdaftar
     */
    public function getJumlahPesertaAttribute()
    {
        // Use raw query to avoid relationship issues with varchar foreign keys
        return \DB::table('asesi_asesmen')
            ->where('id_jadwal', $this->id)
            ->count();
    }

    /**
     * Get sisa kapasitas
     */
    public function getSisaKapasitasAttribute()
    {
        return max(0, $this->kapasitas - $this->jumlah_peserta);
    }

    /**
     * Get status verifikasi TUK
     */
    public function getStatusVerifikasiTukAttribute()
    {
        $verifikasi = $this->verifikasiTuk()->first();
        return $verifikasi ? $verifikasi->status_verifikasi : null;
    }

    /**
     * Check if all dokumen lengkap
     */
    public function getDokumenLengkapAttribute()
    {
        return [
            'surat_tugas' => $this->file_surattugas !== null,
            'ba_komite' => $this->file_bakomite !== null,
            'sk_keputusan' => $this->file_skkeputusan !== null,
            'permohonan_blangko' => $this->file_permohonanblangko !== null,
            'surat_tugas_komtek' => $this->file_surattugaskomtek !== null,
            'surat_tugas_ia11' => $this->file_surattugasia11 !== null,
            'dok_standar_kompetensi' => $this->dok_standarkompetensi !== null,
        ];
    }

    /**
     * Get status pelaksanaan uji label
     */
    public function getPelaksanaanUjiLabelAttribute()
    {
        return match($this->pelaksanaan_uji) {
            '1' => 'Luring',
            '2' => 'Daring',
            '3' => 'Hybrid',
            '4' => 'OnSite',
            default => null,
        };
    }

    /**
     * Scope for active jadwal
     */
    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'Selesai');
    }

    /**
     * Scope for draft status
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'Draft');
    }

    /**
     * Scope for confirmed status
     */
    public function scopeTerkonfirmasi($query)
    {
        return $query->where('status', 'Terkonfirmasi');
    }

    /**
     * Scope for ongoing status
     */
    public function scopeBerlangsung($query)
    {
        return $query->where('status', 'Berlangsung');
    }

    /**
     * Scope for finished status
     */
    public function scopeSelesai($query)
    {
        return $query->where('status', 'Selesai');
    }

    /**
     * Scope for search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where('nama_kegiatan', 'like', '%' . $search . '%')
                ->orWhere('no_surattugas', 'like', '%' . $search . '%');
        }
        return $query;
    }

    /**
     * Scope by event
     */
    public function scopeByEvent($query, $eventId)
    {
        return $query->where('id_event', $eventId);
    }

    /**
     * Scope by date range
     */
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('tgl_asesmen', [$startDate, $endDate]);
    }
}
