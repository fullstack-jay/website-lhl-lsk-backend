<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Personil Komite Teknis  mirror modul Penguji (Asesor) dengan 2 perbedaan:
 * (a) kolom unik `jabatan_komite` (Ketua/Sekretaris/Anggota),
 * (b) folder upload `foto_komite/` (vs foto_asesor/).
 * Sesuai docs/BACKEND_KOMITETEKNIS.md.
 */
class Komite extends Model
{
    protected $table = 'komite';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        //  AKUN LOGIN 
        'password',
        'aktif',            // 'Y'/'N'  hanya Y yang bisa login

        //  IDENTITAS PRIBADI 
        'nama',
        'gelar_depan',
        'gelar_blk',
        'jabatan_komite',   //  UNIK: Ketua/Sekretaris/Anggota
        'inisial',
        'jenis_kelamin',
        'agama',
        'status_perkawinan',
        'tmp_lahir',
        'tgl_lahir',
        'usia',             // auto-kalkulasi sistem
        'no_ktp',
        'foto',             // file di foto_komite/

        //  KONTAK 
        'email',
        'no_hp',

        //  REGISTRASI / KEANGGOTAAN 
        'no_induk',
        'no_lisensi',
        'no_serisertifikat',
        'masaberlaku_lisensi',
        'pendidikan_terakhir',
        'tahun_lulus',
        'bid_keahlian',
        'pekerjaan',
        'kebangsaan',
        'institusi_asal',
        'telp_kantor',
        'fax_kantor',
        'email_kantor',

        //  ALAMAT 
        'alamat', 'RT', 'RW', 'kelurahan', 'kecamatan', 'kota', 'propinsi', 'kodepos',

        //  DOKUMEN 
        'foto_sertifikat',
        'ktp', 'kk', 'ijazah', 'transkrip',
        'facebook',
    ];

    protected $casts = [
        'tgl_lahir' => 'date',
        'masaberlaku_lisensi' => 'date',
        'usia' => 'integer',
        'tahun_lulus' => 'integer',
    ];

    /**
     * Relationship keputusan komite terhadap jadwal asesmen
     * (tabel komite_keputusan: keputusan akhir hasil uji).
     */
    public function jadwalAsesmen(): BelongsToMany
    {
        return $this->belongsToMany(JadwalAsesmen::class, 'komite_keputusan', 'id_asesor', 'id_jadwal')
            ->withPivot('keputusan', 'waktu');
    }

    // 
    // Logika modul Komite (mirror Penguji, sesuai docs/BACKEND_KOMITETEKNIS.md)
    // 

    /** Ambang batas "segera kadaluarsa": 180 hari (6 bulan)  idem Penguji. */
    public const BATAS_SEGERA_KADALUARSA = 180;

    /**
     * Nama lengkap dengan gelar + sanitasi double-gelar
     * (improvement khusus komite: nama DB "Ir. Budi" + gelar_depan "Ir."
     *   "Ir. Budi, M.T." bukan "Ir. Ir. Budi, M.T.").
     */
    public function getFullNameAttribute(): string
    {
        $nama = (string) $this->nama;

        // Sanitasi: jika nama sudah diawali gelar_depan, potong dulu
        if (!empty($this->gelar_depan) && str_starts_with(trim($nama), trim($this->gelar_depan))) {
            $nama = trim(substr(trim($nama), strlen(trim($this->gelar_depan))));
        }

        $depan = !empty($this->gelar_depan) ? trim($this->gelar_depan) . ' ' : '';
        $blk = !empty($this->gelar_blk) ? ', ' . trim($this->gelar_blk) : '';

        return $depan . $nama . $blk;
    }

    /**
     * Sisa hari lisensi dari hari ini (negatif = kedaluwarsa).
     * Guard NULL (strtotime(null) deprecated PHP 8.1+).
     */
    public function getSisaHariLisensiAttribute(): ?int
    {
        if (!$this->masaberlaku_lisensi) {
            return null;
        }
        return now()->startOfDay()->diffInDays($this->masaberlaku_lisensi->copy()->startOfDay(), false);
    }

    /**
     * Status lisensi  idem Penguji: <0 KADALUARSA  0..179 SEGERA  >=180 AKTIF.
     */
    public function getStatusLisensiAttribute(): string
    {
        $sisa = $this->sisa_hari_lisensi;
        if ($sisa === null || $sisa < 0) return 'KADALUARSA';
        if ($sisa < self::BATAS_SEGERA_KADALUARSA) return 'SEGERA';
        return 'AKTIF';
    }

    /**
     * Warna header kartu (padanan hex native #dd4b39/#f39c12/#00a65a).
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
     * Kelengkapan dokumen pokok: foto, ktp, kk, ijazah, transkrip.
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
        return ['lengkap' => empty($kurang), 'kurang' => $kurang];
    }

    /**
     * Scope for active komite (bisa login)
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
                ->orWhere('no_induk', 'like', '%' . $search . '%')
                ->orWhere('no_lisensi', 'like', '%' . $search . '%')
                ->orWhere('email', 'like', '%' . $search . '%');
        }
        return $query;
    }
    /**
     * Auto-generate Nomor Induk Komite
     * Format: KOMITE001, KOMITE002, ...
     */
    public static function generateNoInduk(): string
    {
        $prefix = 'KOMITE';
        $last = self::where('no_induk', 'like', $prefix . '%')
            ->orderByRaw('LENGTH(no_induk) DESC')
            ->orderBy('no_induk', 'desc')
            ->first();

        if ($last && preg_match('/KOMITE(\d+)/i', $last->no_induk, $matches)) {
            $seq = (int) $matches[1] + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }

}
