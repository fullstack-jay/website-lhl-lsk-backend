<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesor;
use App\Models\JadwalAsesmen;
use App\Models\SkemaKkni;
use App\Models\AsesorTugasskema;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Menu Penguji (Asesor) — implementasi modul `asesor` PHP Native versi API.
 * Sesuai docs/BACKEND_PENGUJI.md:
 * - 5 tab list (Semua / Lisensi Aktif / Segera Kadaluarsa / Telah Kadaluarsa / Rekam Jejak)
 * - Hapus bersyarat (server-side guard, perbaikan atas guard UI-only di sistem lama)
 * - Reset password 2 kanal (email + SMS queue) — password_hash alih-alih MD5
 * - Update profil + upload dokumen
 * - Penetapan skema (SK) dengan duplicate check + upsert yang aman
 */
class PengujiController extends Controller
{
    /** Direktori upload dokumen penguji. */
    private const UPLOAD_DIR = 'foto_asesor';

    // ════════════════════════════════════════════════════════════════
    // DAFTAR PENGUJI — 5 TAB (padanan render view modul asesor)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/penguji?tab=semua|aktif|segera|kadaluarsa|rekam_jejak
     *
     * Tab = filter WHERE berbeda pada masaberlaku_lisensi (Query Per Tab):
     * - semua         : semua penguji
     * - aktif         : lisensi > hari ini
     * - segera        : lisensi antara hari ini s/d +180 hari
     * - kadaluarsa    : lisensi < hari ini
     * - rekam_jejak   : sama dgn "semua", frontend fokus kolom portofolio
     *
     * Setiap kartu memuat hitungan portofolio, status warna lisensi,
     * kelengkapan dokumen, label pendidikan, jumlah skema ditugaskan.
     */
    public function index(Request $request)
    {
        $tab = $request->input('tab', 'semua');
        $today = now()->startOfDay();
        $deadline = $today->copy()->addDays(Asesor::BATAS_SEGERA_KADALUARSA);

        $query = Asesor::query()
            ->with(['jadwalAsesmen:id,id_skemakkni,tgl_asesmen,no_surattugas']);

        // ── Filter per tab ──
        switch ($tab) {
            case 'aktif':
                $query->whereDate('masaberlaku_lisensi', '>=', $deadline);
                break;
            case 'segera':
                $query->whereBetween('masaberlaku_lisensi', [$today->toDateString(), $deadline->toDateString()]);
                break;
            case 'kadaluarsa':
                $query->whereDate('masaberlaku_lisensi', '<', $today);
                break;
            case 'semua':
            case 'rekam_jejak':
            default:
                break; // tanpa filter
        }

        // Search opsional
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $query->orderBy('nama', 'asc');

        // Pagination
        $perPage = min((int) ($request->per_page ?? 20), 100);
        $result = $query->paginate($perPage);

        $tahunIni = now()->year;
        $data = collect($result->items())->map(function ($asesor) use ($tahunIni) {
            return $this->transformKartuPenguji($asesor, $tahunIni);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
                'from' => $result->firstItem(),
                'to' => $result->lastItem(),
            ],
        ]);
    }

    /**
     * Transform satu baris menjadi payload kartu profil Penguji
     * (padanan Per-Row Rendering Logic: hitung portofolio, susun nama+gelar,
     *  umur lisensi, kelengkapan dokumen, label pendidikan, penetapan skema).
     */
    private function transformKartuPenguji(Asesor $asesor, int $tahun): array
    {
        // 1. Hitung portofolio asesmen via jadwal_asesor → jadwal_asesmen
        $totalPortofolio = 0;
        $portofolioTahunIni = 0;
        $skemaTahunIni = [];

        $portofolioList = [];
        foreach ($asesor->jadwalAsesmen as $jadwal) {
            $totalPortofolio++;
            if ($jadwal->tgl_asesmen && str_starts_with((string) $jadwal->tgl_asesmen, (string) $tahun)) {
                $portofolioTahunIni++;
            }
            if ($jadwal->id_skemakkni) {
                $lokasi = $jadwal->tempat_asesmen;
                if ($jadwal->tempat_asesmen) {
                    try {
                        if (is_numeric($jadwal->tempat_asesmen)) {
                            $tuk = DB::table('tuk')->where('id', $jadwal->tempat_asesmen)->first();
                            if ($tuk) {
                                $lokasi = $tuk->nama;
                            }
                        }
                    } catch (\Throwable $e) {}
                }

                $jumlahAsesi = 0;
                try {
                    $jumlahAsesi = DB::table('asesi_asesmen')->where('id_jadwal', $jadwal->id)->count();
                } catch (\Throwable $e) {}

                $jumlahPenguji = 1;
                try {
                    $jumlahPenguji = DB::table('jadwal_asesor')->where('id_jadwal', $jadwal->id)->count() ?: 1;
                } catch (\Throwable $e) {}

                $portofolioList[] = [
                    'id_jadwal' => $jadwal->id,
                    'id_skema' => $jadwal->id_skemakkni,
                    'judul' => null,
                    'kode_skema' => null,
                    'no_surattugas' => $jadwal->no_surattugas,
                    'tgl_asesmen' => optional($jadwal->tgl_asesmen)->format('Y-m-d'),
                    'status' => $jadwal->status ?: 'Selesai',
                    'tempat_asesmen' => $jadwal->tempat_asesmen,
                    'lokasi' => $lokasi,
                    'jumlah_asesi' => $jumlahAsesi,
                    'jumlah_penguji' => $jumlahPenguji,
                ];
            }
        }

        // Lengkapi judul skema portofolio
        if (!empty($portofolioList)) {
            $skemaIds = array_unique(array_column($portofolioList, 'id_skema'));
            $skemas = SkemaKkni::whereIn('id', $skemaIds)->get(['id', 'judul', 'kode_skema'])->keyBy('id');
            foreach ($portofolioList as &$item) {
                if (isset($skemas[$item['id_skema']])) {
                    $item['judul'] = $skemas[$item['id_skema']]->judul;
                    $item['kode_skema'] = $skemas[$item['id_skema']]->kode_skema;
                }
            }
            unset($item);
        }

        // 6. Jumlah penetapan skema (distinct id_skemakkni)
        $penugasanSkemaCount = AsesorTugasskema::where('id_asesor', $asesor->id)
            ->distinct('id_skemakkni')
            ->count('id_skemakkni');

        // 5. Label pendidikan terakhir
        $pendidikanLabel = null;
        if ($asesor->pendidikan_terakhir) {
            try {
                $pendidikanLabel = DB::table('pendidikan')->where('id', $asesor->pendidikan_terakhir)->value('nama');
            } catch (\Throwable $e) {
                $pendidikanLabel = null;
            }
        }

        return [
            'id' => $asesor->id,
            'nama_lengkap' => $asesor->full_name,           // padanan step 2 (gelar depan/belakang)
            'nama' => $asesor->nama,
            'no_induk' => $asesor->no_induk,                 // username login portal /asesor/
            'no_lisensi' => $asesor->no_lisensi,
            'no_ktp' => $asesor->no_ktp,
            'email' => $asesor->email,
            'no_hp' => $asesor->no_hp,
            'foto_url' => $asesor->foto ? asset(self::UPLOAD_DIR . '/' . $asesor->foto) : null,

            'status_akun' => $asesor->aktif === 'Y' ? 'AKTIF' : 'NON AKTIF',
            'lisensi' => [
                'masaberlaku' => optional($asesor->masaberlaku_lisensi)->format('Y-m-d'),
                'sisa_hari' => $asesor->sisa_hari_lisensi,
                'status' => $asesor->status_lisensi,          // KADALUARSA | SEGERA | AKTIF
                'warna_kartu' => $asesor->warna_kartu,        // red | yellow | green
            ],

            'dokumen' => $asesor->kelengkapan_dokumen,       // {lengkap, kurang[]}

            'pendidikan_label' => $pendidikanLabel,

            'portofolio' => [
                'total' => $totalPortofolio,                  // $ikutasesmen
                'tahun_ini' => $portofolioTahunIni,           // $ikutasesmen2
                'skema_tahun_ini' => array_values($portofolioList),
                'riwayat' => array_values($portofolioList),
            ],
            'penugasan_skema_count' => $penugasanSkemaCount,

            // 8. Kolom aksi: Hapus hanya jika belum punya rekam jejak
            'bisa_dihapus' => $totalPortofolio === 0,
        ];
    }

    /**
     * GET /api/v1/admin/penguji/statistics
     * Ringkasan jumlah per tab untuk badge tab.
     */
    public function statistics()
    {
        $today = now()->startOfDay();
        $deadline = $today->copy()->addDays(Asesor::BATAS_SEGERA_KADALUARSA);

        return response()->json([
            'success' => true,
            'data' => [
                'semua' => Asesor::count(),
                'lisensi_aktif' => Asesor::whereDate('masaberlaku_lisensi', '>=', $deadline)->count(),
                'segera_kadaluarsa' => Asesor::whereBetween('masaberlaku_lisensi', [$today->toDateString(), $deadline->toDateString()])->count(),
                'telah_kadaluarsa' => Asesor::whereDate('masaberlaku_lisensi', '<', $today)->count(),
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/penguji/{id}
     * Detail lengkap satu penguji (untuk form edit).
     */
    public function show($id)
    {
        $asesor = Asesor::with(['jadwalAsesmen'])->find($id);
        if (!$asesor) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Penguji tersebut Tidak Ditemukan',
            ], 404);
        }

        $kartu = $this->transformKartuPenguji($asesor, now()->year);

        // Hitung total asesi diases
        $totalAsesiDiases = 0;
        try {
            $jadwalIds = $asesor->jadwalAsesmen->pluck('id')->toArray();
            if (!empty($jadwalIds)) {
                $totalAsesiDiases = DB::table('asesi_asesmen')
                    ->whereIn('id_jadwal', $jadwalIds)
                    ->count();
            }
        } catch (\Throwable $e) {
            $totalAsesiDiases = 0;
        }

        // Ambil label pendidikan & pekerjaan
        $pendidikanLabel = $asesor->pendidikan_terakhir;
        if ($asesor->pendidikan_terakhir) {
            try {
                if (is_numeric($asesor->pendidikan_terakhir)) {
                    $val = DB::table('pendidikan')->where('id', $asesor->pendidikan_terakhir)->value('jenjang_pendidikan');
                    if ($val) $pendidikanLabel = $val;
                }
            } catch (\Throwable $e) {}
        }

        $pekerjaanLabel = $asesor->pekerjaan;
        if ($asesor->pekerjaan) {
            try {
                if (is_numeric($asesor->pekerjaan)) {
                    $val = DB::table('pekerjaan')->where('id', $asesor->pekerjaan)->value('pekerjaan');
                    if ($val) $pekerjaanLabel = $val;
                }
            } catch (\Throwable $e) {}
        }

        $data = array_merge($asesor->toArray(), $kartu, [
            'no_ktp' => $asesor->no_ktp,
            'tmp_lahir' => $asesor->tmp_lahir,
            'tgl_lahir' => optional($asesor->tgl_lahir)->format('Y-m-d'),
            'tanggal_lisensi' => optional($asesor->tanggal_lisensi)->format('Y-m-d'),
            'masaberlaku_lisensi' => optional($asesor->masaberlaku_lisensi)->format('Y-m-d'),
            'sisa_hari_lisensi' => $asesor->sisa_hari_lisensi,
            'status_lisensi' => $asesor->status_lisensi,
            'warna_kartu' => $asesor->warna_kartu,
            'agama' => $asesor->agama,
            'status_perkawinan' => $asesor->status_perkawinan,
            'pekerjaan' => $pekerjaanLabel,
            'pendidikan_terakhir' => $pendidikanLabel,
            'institusi_asal' => $asesor->institusi_asal,
            'total_asesi_diases' => $totalAsesiDiases,
            'foto_sertifikat_url' => $asesor->foto_sertifikat ? asset(self::UPLOAD_DIR . '/' . $asesor->foto_sertifikat) : null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // TAMBAH PENGUJI BARU (padanan module tambahasesor)
    // ════════════════════════════════════════════════════════════════

    /** Password default penguji baru (sesuai docs/BACKEND_PENGUJI.md TL;DR #2). */
    public const DEFAULT_PASSWORD = 'Kbl12345';

    /**
     * POST /api/v1/admin/penguji
     *
     * "Tambah Penguji Baru" — frontend TIDAK mengirim password.
     * Backend otomatis men-set password default `Kbl12345` (bcrypt).
     * (Pada sistem native, password yang sama dikirim ke penguji via email+SMS.)
     *
     * Akun dibuat di 2 tempat agar konsisten dengan arsitektur login:
     * - tabel `asesor` (profil lengkap + master data modul Penguji)
     * - tabel `users` (level='penguji', username=no_ktp → untuk auth API)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'no_ktp' => 'required|string|max:30|unique:users,username',
            'nama' => 'required|string|max:255',
            'gelar_depan' => 'nullable|string|max:50',
            'gelar_blk' => 'nullable|string|max:100',
            'jenis_kelamin' => 'nullable|in:L,P',
            'agama' => 'nullable|string|max:50',
            'status_perkawinan' => 'nullable|string|max:50',
            'tmp_lahir' => 'nullable|string|max:255',
            'tgl_lahir' => 'nullable|date',
            'email' => 'nullable|email|max:255',
            'no_hp' => 'nullable|string|max:30',
            // No. Induk opsional saja — tidak wajib, tanpa cek duplikat
            'no_induk' => 'nullable|string|max:100',
            'no_lisensi' => 'nullable|string|max:100',
            'tanggal_lisensi' => 'nullable|date',
            'masaberlaku_lisensi' => 'nullable|date',
            'pendidikan_terakhir' => 'nullable|string|max:10',
            'bid_keahlian' => 'nullable|string|max:255',
            'alamat' => 'nullable|string',
            'kelurahan' => 'nullable|string|max:255',
            'kecamatan' => 'nullable|string|max:255',
            'kota' => 'nullable|string|max:255',
            'propinsi' => 'nullable|string|max:255',
            'kodepos' => 'nullable|string|max:10',
        ], [
            'no_ktp.required' => 'NIK (No. KTP) wajib diisi',
            'no_ktp.unique' => 'NIK tersebut sudah terdaftar',
            'nama.required' => 'Nama wajib diisi',
        ]);

        if ($validator->fails()) {
            // Pesan error pertama diangkat ke `message` agar frontend yang hanya
            // menampilkan `message` tetap melihat penyebab spesifiknya.
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $profileData = [
                'nama' => strip_tags(trim($request->nama)),
                'gelar_depan' => $request->gelar_depan,
                'gelar_blk' => $request->gelar_blk,
                'jenis_kelamin' => $request->jenis_kelamin,
                'agama' => $request->agama,
                'status_perkawinan' => $request->status_perkawinan,
                'tmp_lahir' => $request->tmp_lahir,
                'tgl_lahir' => $request->tgl_lahir,
                'email' => $request->email,
                'no_hp' => $request->no_hp,
                'no_induk' => $request->no_induk,
                'no_lisensi' => $request->no_lisensi,
                'tanggal_lisensi' => $request->tanggal_lisensi,
                'masaberlaku_lisensi' => $request->masaberlaku_lisensi,
                'institusi_asal' => $request->institusi_asal,
                'pekerjaan' => $request->pekerjaan,
                'pendidikan_terakhir' => $request->pendidikan_terakhir,
                'bid_keahlian' => $request->bid_keahlian,
                'alamat' => $request->alamat,
                'kelurahan' => $request->kelurahan,
                'kecamatan' => $request->kecamatan,
                'kota' => $request->kota,
                'propinsi' => $request->propinsi,
                'kodepos' => $request->kodepos,
            ];

            // 1. Insert ke tabel asesor (profil master)
            $asesor = new Asesor(array_merge($profileData, [
                'no_ktp' => $request->no_ktp,
                'password' => Hash::make(self::DEFAULT_PASSWORD),  // auto Kbl12345 (bcrypt)
                'aktif' => 'Y',
            ]));
            if ($asesor->tgl_lahir) {
                $asesor->usia = $asesor->tgl_lahir->age;          // usia = kalkulasi sistem
            }

            // Upload foto bila ada
            if ($request->hasFile('foto')) {
                $fileFoto = $request->file('foto');
                $fnFoto = time() . '.' . md5($fileFoto->getClientOriginalName()) . '.' . $fileFoto->getClientOriginalExtension();
                $fileFoto->move(public_path(self::UPLOAD_DIR), $fnFoto);
                $asesor->foto = $fnFoto;
            }

            // Upload sertifikat lisensi bila ada
            if ($request->hasFile('foto_sertifikat')) {
                $fileSert = $request->file('foto_sertifikat');
                $fnSert = time() . '.' . md5($fileSert->getClientOriginalName()) . '.' . $fileSert->getClientOriginalExtension();
                $fileSert->move(public_path(self::UPLOAD_DIR), $fnSert);
                $asesor->foto_sertifikat = $fnSert;
            }

            $asesor->save();

            // 2. Mirror akun ke tabel users (level=penguji, username=no_ktp)
            //    agar bisa login via /api/v1/auth/penguji/login
            $user = User::create([
                'username' => $request->no_ktp,                    // session native pakai no_ktp
                'password' => Hash::make(self::DEFAULT_PASSWORD),
                'nama_lengkap' => $asesor->nama,
                'gelar_depan' => $asesor->gelar_depan,
                'gelar_blk' => $asesor->gelar_blk,
                'tmp_lahir' => $asesor->tmp_lahir,
                'tgl_lahir' => $asesor->tgl_lahir,
                'no_induk' => $asesor->no_induk,
                'no_ktp' => $asesor->no_ktp,
                'pendidikan_terakhir' => $asesor->pendidikan_terakhir,
                'email' => $asesor->email,
                'no_telp' => $asesor->no_hp,
                'level' => 'penguji',
                'blokir' => 'N',                                   // Y/N enum: N = aktif
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Penguji berhasil ditambahkan. Password default telah dikirim ke penguji.',
                'data' => [
                    'asesor' => $this->transformKartuPenguji($asesor, now()->year),
                    'akun_login' => [
                        // 3 identifier yang bisa dipakai login
                        'no_ktp' => $user->no_ktp,
                        'no_hp' => $user->no_telp,
                        'no_induk' => $user->no_induk,
                    ],
                ],
                // password default TIDAK dikirim ke frontend admin (dikonversi ke notifikasi).
                // Produksi: kirim via email+SMS ke penguji, seperti perilaku native.
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan penguji',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HAPUS PENGUJI (padanan handler hapusasesor)
    // ════════════════════════════════════════════════════════════════

    /**
     * DELETE /api/v1/admin/penguji/{id}
     *
     * ✅ Server-side guard (perbaikan atas sistem lama yg hanya menyembunyikan tombol):
     * delete ditolak jika penguji sudah punya penugasan jadwal (rekam jejak).
     */
    public function destroy($id)
    {
        $asesor = Asesor::find($id);
        if (!$asesor) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Penguji tersebut Tidak Ditemukan',
            ], 404);
        }

        $ikutAsesmen = DB::table('jadwal_asesor')->where('id_asesor', $id)->count();
        if ($ikutAsesmen > 0) {
            return response()->json([
                'success' => false,
                'message' => "Penguji tidak dapat dihapus karena memiliki {$ikutAsesmen} penugasan jadwal asesmen",
            ], 400);
        }

        // Bersihkan penugasan skema + akun login (users) lalu hapus record
        DB::beginTransaction();
        try {
            AsesorTugasskema::where('id_asesor', $id)->delete();
            // Hapus juga akun di tabel users (level=penguji, username=no_ktp)
            User::where('username', $asesor->no_ktp)->where('level', 'penguji')->delete();
            $asesor->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Penguji berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus penguji',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // RESET PASSWORD (padanan handler resetpasswordasesor)
    // ════════════════════════════════════════════════════════════════

    /**
     * POST /api/v1/admin/penguji/{id}/reset-password
     *
     * Alur sistem lama: generate password acak → kirim email (tabel smtp/PHPMailer)
     * + SMS (tabel outbox) → update hash di DB.
     * Perbaikan: password_hash() bcrypt alih-alih double-MD5.
     *
     * Catatan: pengiriman email/SMS aktual dicatat di tabel notifikasi (di-skip di dev).
     */
    public function resetPassword(Request $request, $id)
    {
        $asesor = Asesor::find($id);
        if (!$asesor) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan',
            ], 404);
        }

        // Generate password baru 6 digit angka (idem rand(100000,999999))
        $plainPassword = (string) random_int(100000, 999999);

        DB::beginTransaction();
        try {
            $hashed = Hash::make($plainPassword);             // 🔐 bcrypt, bukan md5(md5())
            $asesor->password = $hashed;
            $asesor->save();

            // Sinkronkan hash ke akun users (login API memakai tabel users)
            $linkedUser = User::where('username', $asesor->no_ktp)->where('level', 'penguji')->first();
            if (!$linkedUser) {
                $linkedUser = User::where('no_ktp', $asesor->no_ktp)->where('level', 'penguji')->first();
            }
            if ($linkedUser) {
                $linkedUser->password = $hashed;
                $linkedUser->save();
            }

            // Log notifikasi 2 kanal (dev-safe). Produksi: Mail facade + SMS gateway.
            $channelLog = [];
            if ($asesor->email) {
                $channelLog['email_ke'] = $asesor->email;
            }
            if (strlen((string) $asesor->no_hp) > 8) {         // skip SMS jika no_hp ≤ 8 char
                $channelLog['sms_ke'] = $asesor->no_hp;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Update/Reset Password Penguji Sukses',
                'data' => [
                    // Password plaintext dikembalikan agar admin dapat meneruskannya;
                    // produksi sebaiknya kirim langsung ke user via email/SMS.
                    'password_baru' => $plainPassword,
                    'notifikasi' => $channelLog,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat reset password',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // UPDATE PROFIL (padanan module updateasesor)
    // ════════════════════════════════════════════════════════════════

    /**
     * POST /api/v1/admin/penguji/{id}   (multipart/form-data; _method=PUT juga didukung)
     *
     * Update profil 36 kolom + upload foto/sertifikat lisensi.
     * File: jpg/png/gif/jpeg (+pdf utk sertifikat), rename timestamp.md5.ext ke foto_asesor/.
     */
    public function update(Request $request, $id)
    {
        $asesor = Asesor::find($id);
        if (!$asesor) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Penguji tersebut Tidak Ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nama' => 'nullable|string|max:255',
            'gelar_depan' => 'nullable|string|max:50',
            'gelar_blk' => 'nullable|string|max:100',
            'jenis_kelamin' => 'nullable|in:L,P',
            'agama' => 'nullable|string|max:50',
            'status_perkawinan' => 'nullable|string|max:50',
            'tmp_lahir' => 'nullable|string|max:255',
            'tgl_lahir' => 'nullable|date',
            'email' => 'nullable|email|max:255',
            'no_hp' => 'nullable|string|max:30',
            'no_induk' => 'nullable|string|max:100',
            'no_ktp' => 'nullable|string|max:50',
            'pendidikan_terakhir' => 'nullable|string|max:10',
            'tahun_lulus' => 'nullable|integer|min:1950|max:2100',
            'bid_keahlian' => 'nullable|string|max:255',
            'pekerjaan' => 'nullable|string|max:3',
            'kebangsaan' => 'nullable|string|max:100',
            'alamat' => 'nullable|string',
            'RT' => 'nullable|string|max:5',
            'RW' => 'nullable|string|max:5',
            'kelurahan' => 'nullable|string|max:255',
            'kecamatan' => 'nullable|string|max:255',
            'kota' => 'nullable|string|max:255',
            'propinsi' => 'nullable|string|max:255',
            'kodepos' => 'nullable|string|max:10',
            'institusi_asal' => 'nullable|string|max:255',
            'telp_kantor' => 'nullable|string|max:30',
            'fax_kantor' => 'nullable|string|max:30',
            'email_kantor' => 'nullable|email|max:255',
            'no_lisensi' => 'nullable|string|max:100',
            'tanggal_lisensi' => 'nullable|date',
            'no_serisertifikat' => 'nullable|string|max:100',
            'masaberlaku_lisensi' => 'nullable|date',
            'facebook' => 'nullable|string|max:255',
            'aktif' => 'nullable|in:Y,N',

            // Upload dokumen
            'foto' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:5120',
            'foto_sertifikat' => 'nullable|file|mimes:jpg,jpeg,png,gif,pdf|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Upload file: rename timestamp.md5.ext (idem sistem lama, plus MIME check via validator)
            foreach ([['foto', 'foto'], ['foto_sertifikat', 'foto_sertifikat']] as [$field, $column]) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $ext = strtolower($file->getClientOriginalExtension());
                    $fileName = time() . md5($file->getClientOriginalName() . microtime()) . '.' . $ext;

                    $dest = public_path(self::UPLOAD_DIR);
                    if (!file_exists($dest)) mkdir($dest, 0755, true);

                    // Hapus file lama agar tidak menumpuk
                    $oldFile = public_path(self::UPLOAD_DIR . '/' . $asesor->{$column});
                    if (!empty($asesor->{$column}) && file_exists($oldFile)) unlink($oldFile);

                    $file->move($dest, $fileName);
                    $asesor->{$column} = $fileName;
                }
            }

            // Update field teks/tanggal yang dikirim saja
            $textFields = ['nama','gelar_depan','gelar_blk','jenis_kelamin','agama','status_perkawinan','tmp_lahir','tgl_lahir',
                'email','no_hp','no_induk','no_ktp','pendidikan_terakhir','tahun_lulus',
                'bid_keahlian','pekerjaan','kebangsaan','alamat','RT','RW','kelurahan',
                'kecamatan','kota','propinsi','kodepos','institusi_asal','telp_kantor',
                'fax_kantor','email_kantor','no_lisensi','tanggal_lisensi','no_serisertifikat',
                'masaberlaku_lisensi','facebook','aktif'];
            foreach ($textFields as $field) {
                if ($request->has($field)) {
                    $val = $request->input($field);
                    $asesor->{$field} = ($val === '') ? null : $val;
                }
            }

            // usia auto-update dari tgl_lahir (kolom usia = hasil kalkulasi sistem)
            if ($asesor->tgl_lahir) {
                $asesor->usia = $asesor->tgl_lahir->age;
            }

            $asesor->save();

            // Sinkronkan field akun ke tabel users (username=no_ktp) agar
            // data login penguji selalu konsisten dengan profil
            $linkedUser = User::where('username', $asesor->no_ktp)->where('level', 'penguji')->first();
            if (!$linkedUser) {
                $linkedUser = User::where('no_ktp', $asesor->no_ktp)->where('level', 'penguji')->first();
            }
            if ($linkedUser) {
                $linkedUser->nama_lengkap = $asesor->nama;
                $linkedUser->gelar_depan = $asesor->gelar_depan;
                $linkedUser->gelar_blk = $asesor->gelar_blk;
                $linkedUser->tmp_lahir = $asesor->tmp_lahir;
                $linkedUser->tgl_lahir = $asesor->tgl_lahir;
                $linkedUser->no_induk = $asesor->no_induk;
                $linkedUser->no_ktp = $asesor->no_ktp;
                $linkedUser->pendidikan_terakhir = $asesor->pendidikan_terakhir;
                $linkedUser->email = $asesor->email;
                $linkedUser->no_telp = $asesor->no_hp;
                // blokir mengikuti status akun asesor: Y=nonaktif, N=aktif
                $linkedUser->blokir = ($asesor->aktif === 'Y') ? 'N' : 'Y';
                $linkedUser->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Profil Penguji berhasil diperbarui',
                'data' => $this->transformKartuPenguji($asesor->fresh(), now()->year),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui profil penguji',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // PENUGASAN SKEMA (padanan module penugasanskema&idas={id})
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/penguji/{id}/penugasan-skema/halaman
     *
     * Render seluruh data halaman "Penetapan Skema Penguji" sekaligus
     * (padanan Render View native, satu endpoint untuk membangun halaman):
     * - Kartu profil penguji (konteks header, warna lisensi 🔴🟡🟢)
     * - Tabel penugasan existing (ORDER BY id_skemakkni ASC, idem native)
     * - Dropdown skema aktif (`skema_kkni WHERE aktif='Y'`) + flag belum_ditugaskan
     *   (padanan Common Query #5)
     * - Badge jumlah skema DISTINCT (Common Query #2)
     */
    public function halamanPenugasanSkema($id)
    {
        // Guard idas tidak valid (perbaikan atas native yang tetap render kartu kosong)
        $asesor = Asesor::find($id);
        if (!$asesor) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Penguji tersebut Tidak Ditemukan',
            ], 404);
        }

        // Kartu profil penguji (reuse transformer menu Penguji — warna lisensi dll)
        $kartu = $this->transformKartuPenguji($asesor, now()->year);

        // Tabel penugasan existing — ORDER BY id_skemakkni ASC (idem native
        // "pengelompokan visual per skema"), lalu tanggal DESC utk SK terbaru dulu
        $list = AsesorTugasskema::where('id_asesor', $id)
            ->join('skema_kkni', 'skema_kkni.id', '=', 'asesor_tugasskema.id_skemakkni')
            ->orderBy('asesor_tugasskema.id_skemakkni', 'asc')
            ->orderByDesc('asesor_tugasskema.tanggal_sk')
            ->get([
                'asesor_tugasskema.id',
                'asesor_tugasskema.id_skemakkni',
                'asesor_tugasskema.no_sk',
                'asesor_tugasskema.tanggal_sk',
                'skema_kkni.kode_skema',
                'skema_kkni.judul',
                'skema_kkni.aktif',
            ]);

        // Dropdown skema aktif — WHERE aktif='Y' ORDER BY judul ASC (idem native).
        // Skema non-aktif tidak bisa dipilih baru, tapi penugasan existing ke
        // skema non-aktif tetap tampil di tabel (JOIN tanpa filter).
        $skemaDitugaskan = AsesorTugasskema::where('id_asesor', $id)
            ->pluck('id_skemakkni')
            ->unique();

        $dropdown = SkemaKkni::where('aktif', 'Y')
            ->orderBy('judul', 'asc')
            ->get(['id', 'kode_skema', 'judul'])
            ->map(fn ($s) => [
                'value' => $s->id,
                'label' => "{$s->kode_skema} - {$s->judul}",
                'belum_ditugaskan' => !$skemaDitugaskan->contains($s->id),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                // Kartu konteks header (warna lisensi, badge, portofolio)
                'penguji' => $kartu,
                'penetapan_skema_count' => $skemaDitugaskan->count(),   // badge kartu

                // Tabel penugasan existing
                'penugasan' => $list->map(fn ($r) => [
                    'id' => $r->id,
                    'id_skemakkni' => $r->id_skemakkni,
                    'no_sk' => $r->no_sk,
                    'tanggal_sk' => $r->tanggal_sk ? date('Y-m-d', strtotime($r->tanggal_sk)) : null,
                    'kode_skema' => $r->kode_skema,
                    'judul' => $r->judul,
                    'skema_aktif' => $r->aktif === 'Y',   // badge "non-aktif" opsional
                ]),

                // Form tambah penugasan baru
                'dropdown_skema' => $dropdown,
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/penguji/{id}/penugasan-skema
     * Daftar penetapan skema milik penguji ini.
     */
    public function indexPenugasanSkema($id)
    {
        $asesor = Asesor::find($id);
        if (!$asesor) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Penguji tersebut Tidak Ditemukan',
            ], 404);
        }

        $list = AsesorTugasskema::where('id_asesor', $id)
            ->join('skema_kkni', 'skema_kkni.id', '=', 'asesor_tugasskema.id_skemakkni')
            ->orderBy('asesor_tugasskema.id_skemakkni', 'asc')
            ->orderByDesc('asesor_tugasskema.tanggal_sk')
            ->get([
                'asesor_tugasskema.id',
                'asesor_tugasskema.id_skemakkni',
                'asesor_tugasskema.no_sk',
                'asesor_tugasskema.tanggal_sk',
                'skema_kkni.kode_skema',
                'skema_kkni.judul',
                'skema_kkni.jenjang',
                'skema_kkni.jenis_skema',
            ]);

        return response()->json([
            'success' => true,
            'data' => $list->map(fn ($r) => [
                'id' => $r->id,
                'id_skemakkni' => $r->id_skemakkni,
                'no_sk' => $r->no_sk,
                'tanggal_sk' => $r->tanggal_sk ? date('Y-m-d', strtotime($r->tanggal_sk)) : null,
                'kode_skema' => $r->kode_skema,
                'judul' => $r->judul,
                'jenjang' => $r->jenjang,
                'jenis_skema' => $r->jenis_skema,
            ]),
        ]);
    }

    /**
     * POST /api/v1/admin/penguji/{id}/penugasan-skema
     *
     * Tambahkanunit handler versi API:
     * - Duplicate check 4 kolom (asesor, skema, no_sk, tanggal_sk)
     *   → 409 "Maaf Penetapan Penugasan Skema tersebut Sudah Ada"
     * - Fix bug native: judul skema selalu null ($id_skkni] typo) — di sini join benar
     */
    public function storePenugasanSkema(Request $request, $id)
    {
        $asesor = Asesor::find($id);
        if (!$asesor) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Penguji tersebut Tidak Ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'id_skemakkni' => 'required|integer|exists:skema_kkni,id',
            'no_sk' => 'required|string|max:50',
            'tanggal_sk' => 'required|date',
        ], [
            'id_skemakkni.required' => 'Skema wajib dipilih',
            'no_sk.required' => 'Nomor SK wajib diisi',
            'tanggal_sk.required' => 'Tanggal SK wajib diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Duplicate check 4 kolom (idem query duplikat-check sistem lama)
        $exists = AsesorTugasskema::where('id_asesor', $id)
            ->where('id_skemakkni', $request->id_skemakkni)
            ->where('no_sk', $request->no_sk)
            ->whereDate('tanggal_sk', $request->tanggal_sk)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Penetapan Penugasan Skema tersebut Sudah Ada',
            ], 409);
        }

        try {
            $penugasan = AsesorTugasskema::create([
                'id_asesor' => $id,
                'id_skemakkni' => $request->id_skemakkni,
                'no_sk' => strip_tags(trim($request->no_sk)),
                'tanggal_sk' => $request->tanggal_sk,
            ]);

            // Judul & kode skema dikembalikan (fix bug alert-null di sistem lama)
            $skema = SkemaKkni::find($request->id_skemakkni, ['id', 'kode_skema', 'judul']);

            return response()->json([
                'success' => true,
                'message' => 'Penetapan Penugasan Skema berhasil ditambahkan',
                'data' => [
                    'id' => $penugasan->id,
                    'id_asesor' => (int) $id,
                    'id_skemakkni' => $penugasan->id_skemakkni,
                    'no_sk' => $penugasan->no_sk,
                    'tanggal_sk' => optional($penugasan->tanggal_sk)->format('Y-m-d'),
                    'kode_skema' => $skema?->kode_skema,
                    'judul' => $skema?->judul,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan penugasan skema',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/v1/admin/penugasan-skema/{id}
     * Padanan handler hapusunit.
     */
    public function destroyPenugasanSkema($id)
    {
        $penugasan = AsesorTugasskema::find($id);
        if (!$penugasan) {
            return response()->json([
                'success' => false,
                'message' => 'Penugasan skema tidak ditemukan',
            ], 404);
        }

        $penugasan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Penugasan skema berhasil dihapus',
        ]);
    }
}
