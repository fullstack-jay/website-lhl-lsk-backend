<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Komite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Komite Teknis — implementasi modul `komite` PHP Native versi API.
 * Sesuai docs/BACKEND_KOMITETEKNIS.md (mirror modul Penguji + perbedaan):
 * - 5 tab list (Semua / Lisensi Aktif / Segera Kadaluarsa / Telah Kadaluarsa / Rekam Jejak)
 * - Kolom unik `jabatan_komite` (Ketua/Sekretaris/Anggota)
 * - Create: duplicate check 6-field profil + jabatan_komite langsung diisi
 *   (perbaikan atas native yang melewatkannya dari INSERT)
 * - Password default `komite123` (perbaikan atas native yang masih acak)
 * - Reset password + sinkron akun users
 * - Folder upload `foto_komite/`
 * - Portofolio: fitur nonaktif di native (commented) — badge selalu 0; di sini
 *   dihitung dari komite_keputusan (data nyata yang ada) sebagai informasi.
 */
class KomiteController extends Controller
{
    /** Direktori upload dokumen komite (beda dgn asesor!). */
    private const UPLOAD_DIR = 'foto_komite';

    /** Password default personil komite baru — sama persis dengan Penguji (Kbl12345). */
    public const DEFAULT_PASSWORD = 'Kbl12345';

    // ════════════════════════════════════════════════════════════════
    // DAFTAR KOMITE — 5 TAB (idem Penguji)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/komite?tab=semua|aktif|segera|kadaluarsa|rekam_jejak
     */
    public function index(Request $request): JsonResponse
    {
        $tab = $request->input('tab', 'semua');
        $today = now()->startOfDay();
        $deadline = $today->copy()->addDays(Komite::BATAS_SEGERA_KADALUARSA);

        $query = Komite::query();

        // ── Filter per tab (idem Penguji) ──
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
                break;
        }

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $query->orderBy('nama', 'asc');

        $perPage = min((int) ($request->per_page ?? 20), 100);
        $result = $query->paginate($perPage);

        $data = collect($result->items())
            ->map(fn ($komite) => $this->transformKartuKomite($komite));

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
     * GET /api/v1/admin/komite/statistics — jumlah per tab (badge).
     */
    public function statistics(): JsonResponse
    {
        $today = now()->startOfDay();
        $deadline = $today->copy()->addDays(Komite::BATAS_SEGERA_KADALUARSA);

        // Rekap per jabatan (Common Query #2)
        $perJabatan = Komite::selectRaw('jabatan_komite, COUNT(*) as jumlah')
            ->groupBy('jabatan_komite')
            ->pluck('jumlah', 'jabatan_komite');

        return response()->json([
            'success' => true,
            'data' => [
                'semua' => Komite::count(),
                'lisensi_aktif' => Komite::whereDate('masaberlaku_lisensi', '>=', $deadline)->count(),
                'segera_kadaluarsa' => Komite::whereBetween('masaberlaku_lisensi', [$today->toDateString(), $deadline->toDateString()])->count(),
                'telah_kadaluarsa' => Komite::whereDate('masaberlaku_lisensi', '<', $today)->count(),
                'per_jabatan' => $perJabatan,
                'bisa_login' => Komite::where('aktif', 'Y')->count(),
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/komite/{id}
     *
     * Mengembalikan RECORD LENGKAP (semua kolom kecuali password) untuk
     * mengisi form edit — termasuk gelar_depan, gelar_blk, tmp_lahir,
     * tgl_lahir, alamat, propinsi, dll yang sebelumnya tidak dikirim.
     * Field ringkasan kartu (lisensi status/warna, dokumen, dll) tetap
     * disertakan agar kompatibel dengan tampilan kartu.
     */
    public function show($id): JsonResponse
    {
        $komite = Komite::find($id);
        if (!$komite) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Komite tersebut Tidak Ditemukan',
            ], 404);
        }

        // Record lengkap (tanpa password) — nilai API selalu data terbaru.
        // Field tanggal diformat Y-m-d agar langsung cocok untuk input date form edit.
        $record = $komite->makeHidden(['password'])->toArray();
        foreach (['tgl_lahir', 'masaberlaku_lisensi'] as $dateField) {
            if (!empty($record[$dateField])) {
                $record[$dateField] = $komite->{$dateField}->format('Y-m-d');
            }
        }

        // Field ringkasan kartu + URL publik dokumen (kompatibilitas tampilan kartu)
        return response()->json([
            'success' => true,
            'data' => array_merge($record, [
                'nama_lengkap' => $komite->full_name,        // dgn sanitasi double-gelar
                'status_akun' => $komite->aktif === 'Y' ? 'AKTIF' : 'NON AKTIF',
                'lisensi' => [
                    'masaberlaku' => optional($komite->masaberlaku_lisensi)->format('Y-m-d'),
                    'sisa_hari' => $komite->sisa_hari_lisensi,
                    'status' => $komite->status_lisensi,     // KADALUARSA | SEGERA | AKTIF
                    'warna_kartu' => $komite->warna_kartu,   // red | yellow | green
                ],
                'dokumen' => $komite->kelengkapan_dokumen,
                'pendidikan_label' => $this->pendidikanLabel($komite->pendidikan_terakhir),

                // URL publik dokumen (asset foto_komite/...)
                'foto_url' => $komite->foto ? asset('foto_komite/' . $komite->foto) : null,
                'foto_sertifikat_url' => $komite->foto_sertifikat ? asset('foto_komite/' . $komite->foto_sertifikat) : null,
                'ktp_url' => $komite->ktp ? asset('foto_komite/' . $komite->ktp) : null,
                'kk_url' => $komite->kk ? asset('foto_komite/' . $komite->kk) : null,
                'ijazah_url' => $komite->ijazah ? asset('foto_komite/' . $komite->ijazah) : null,
                'transkrip_url' => $komite->transkrip ? asset('foto_komite/' . $komite->transkrip) : null,
            ]),
        ]);
    }

    /**
     * Transform satu baris menjadi payload kartu Komite.
     * Mirror Penguji: warna lisensi, kelengkapan dokumen, label pendidikan,
     * + jabatan_komite + sanitasi double-gelar (di model).
     */
    private function transformKartuKomite(Komite $komite): array
    {
        // Portofolio: fitur nonaktif di native (badge 0). Di sini dihitung dari
        // komite_keputusan sebagai informasi riwayat keputusan.
        $keputusanCount = 0;
        try {
            $keputusanCount = DB::table('komite_keputusan')->where('id_asesor', $komite->id)->count();
        } catch (\Throwable $e) {
            $keputusanCount = 0;
        }

        $pendidikanLabel = $this->pendidikanLabel($komite->pendidikan_terakhir);

        return [
            'id' => $komite->id,
            'nama_lengkap' => $komite->full_name,       // dgn sanitasi double-gelar
            'nama' => $komite->nama,
            'jabatan_komite' => $komite->jabatan_komite, // ⭐ Ketua/Sekretaris/Anggota
            'no_induk' => $komite->no_induk,
            'no_ktp' => $komite->no_ktp,
            'no_lisensi' => $komite->no_lisensi,
            'email' => $komite->email,
            'no_hp' => $komite->no_hp,
            'foto_url' => $komite->foto ? asset(self::UPLOAD_DIR . '/' . $komite->foto) : null,

            'status_akun' => $komite->aktif === 'Y' ? 'AKTIF' : 'NON AKTIF',
            'lisensi' => [
                'masaberlaku' => optional($komite->masaberlaku_lisensi)->format('Y-m-d'),
                'sisa_hari' => $komite->sisa_hari_lisensi,
                'status' => $komite->status_lisensi,     // KADALUARSA | SEGERA | AKTIF
                'warna_kartu' => $komite->warna_kartu,   // red | yellow | green
            ],

            'dokumen' => $komite->kelengkapan_dokumen,
            'pendidikan_label' => $pendidikanLabel,

            // Informasi riwayat keputusan (fitur portofolio native nonaktif)
            'keputusan_count' => $keputusanCount,
        ];
    }

    /**
     * Label pendidikan terakhir — aman terhadap data yang berisi nama
     * langsung (mis. "S1") alih-alih ID pendidikan (guard SQLSTATE).
     */
    private function pendidikanLabel($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        try {
            $label = DB::table('pendidikan')->where('id', $value)->value('nama');
            if ($label) {
                return $label;
            }
        } catch (\Throwable $e) {
            // id bukan numerik / kolom tidak cocok — fallback di bawah
        }
        // Nilai sudah berupa teks (mis. "S1") — kembalikan apa adanya
        return (string) $value;
    }

    // ════════════════════════════════════════════════════════════════
    // TAMBAH KOMITE (padanan module tambahkomite)
    // ════════════════════════════════════════════════════════════════

    /**
     * POST /api/v1/admin/komite
     *
     * Perbaikan atas native:
     * - Password default `komite123` (bukan acak) — bcrypt
     * - `jabatan_komite` langsung diisi saat create (native melewatkannya)
     * - Duplicate check 6-field profil → 409
     * - Akun mirror di tabel users (level=komite-teknis, username=no_ktp)
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'no_ktp' => 'required|digits:16',
            'nama' => 'required|string|max:255',
            'gelar_depan' => 'nullable|string|max:50',
            'gelar_blk' => 'nullable|string|max:100',
            'jabatan_komite' => 'nullable|string|max:100',
            'jenis_kelamin' => 'nullable|in:L,P',
            'agama' => 'nullable|string|max:50',
            'status_perkawinan' => 'nullable|string|max:50',
            'tmp_lahir' => 'nullable|string|max:255',
            'tgl_lahir' => 'nullable|date',
            'email' => 'nullable|email|max:255',
            'no_hp' => 'nullable|string|max:30',
            'no_induk' => 'nullable|string|max:100',
            'no_lisensi' => 'nullable|string|max:100',
            'masaberlaku_lisensi' => 'nullable|date',
            'pendidikan_terakhir' => 'nullable|string|max:10',
            'bid_keahlian' => 'nullable|string|max:255',
            'alamat' => 'nullable|string',
            'kelurahan' => 'nullable|string|max:255',
            'kecamatan' => 'nullable|string|max:255',
            'kota' => 'nullable|string|max:255',
            'propinsi' => 'nullable|string|max:255',
            'kodepos' => 'nullable|string|max:10',

            // Upload dokumen (6 field) → foto_komite/
            'foto' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:2048',
            'foto_sertifikat' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
            'ktp' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
            'kk' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
            'ijazah' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
            'transkrip' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
        ], [
            'no_ktp.required' => 'Nomor KTP (NIK) wajib diisi.',
            'no_ktp.digits' => 'Nomor KTP harus terdiri dari 16 digit angka.',
            'nama.required' => 'Nama wajib diisi',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Duplicate check 6-field profil (idem native tambahkomite)
        $duplikat = Komite::where('nama', $request->nama)
            ->where('gelar_depan', $request->gelar_depan)
            ->where('gelar_blk', $request->gelar_blk)
            ->where('jenis_kelamin', $request->jenis_kelamin)
            ->where('tmp_lahir', $request->tmp_lahir)
            ->whereDate('tgl_lahir', $request->tgl_lahir)
            ->exists();

        if ($duplikat) {
            return response()->json([
                'success' => false,
                'message' => 'Data telah ditambahkan sebelumnya',
            ], 409);
        }

        // NIK tidak boleh sudah terdaftar sebagai komite
        $nikTerdaftar = Komite::where('no_ktp', $request->no_ktp)->exists();
        if ($nikTerdaftar) {
            return response()->json([
                'success' => false,
                'message' => 'NIK tersebut sudah terdaftar sebagai Komite',
            ], 409);
        }

        DB::beginTransaction();
        try {
            // 1. Insert tabel komite (profil master)
            $komite = new Komite([
                'password' => Hash::make(self::DEFAULT_PASSWORD),   // auto komite123
                'aktif' => 'Y',
                'no_ktp' => $request->no_ktp,
                'nama' => strip_tags(trim($request->nama)),
                'gelar_depan' => $request->gelar_depan,
                'gelar_blk' => $request->gelar_blk,
                'jabatan_komite' => $request->jabatan_komite,       // ⭐ langsung diisi
                'jenis_kelamin' => $request->jenis_kelamin,
                'agama' => $request->agama,
                'status_perkawinan' => $request->status_perkawinan,
                'tmp_lahir' => $request->tmp_lahir,
                'tgl_lahir' => $request->tgl_lahir,
                'email' => $request->email,
                'no_hp' => $request->no_hp,
                'no_induk' => $request->filled('no_induk') ? $request->no_induk : Komite::generateNoInduk(),
                'no_lisensi' => $request->no_lisensi,
                'masaberlaku_lisensi' => $request->masaberlaku_lisensi,
                'pendidikan_terakhir' => $request->pendidikan_terakhir,
                'bid_keahlian' => $request->bid_keahlian,
                'alamat' => $request->alamat,
                'kelurahan' => $request->kelurahan,
                'kecamatan' => $request->kecamatan,
                'kota' => $request->kota,
                'propinsi' => $request->propinsi,
                'kodepos' => $request->kodepos,
            ]);
            if ($komite->tgl_lahir) {
                $komite->usia = $komite->tgl_lahir->age;            // usia auto-kalkulasi
            }
            $komite->save();

            // 1b. Upload 6 dokumen → foto_komite/
            foreach (['foto', 'foto_sertifikat', 'ktp', 'kk', 'ijazah', 'transkrip'] as $field) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $filename = time() . '_' . $field . '_' . uniqid() . '.' . strtolower($file->getClientOriginalExtension());

                    $dest = public_path(self::UPLOAD_DIR);
                    if (!file_exists($dest)) mkdir($dest, 0755, true);

                    $file->move($dest, $filename);
                    $komite->{$field} = $filename;
                }
            }
            $komite->save();

            // 2. Mirror akun users (level=komite-teknis, username=no_ktp)
            $user = User::where('username', $request->no_ktp)
                ->orWhere('no_ktp', $request->no_ktp)
                ->first();

            if ($user) {
                // Upgrade akun existing (user/peserta) menjadi komite
                if (!in_array($user->level, ['admin', 'superadmin'], true)) {
                    $user->level = 'komite-teknis';
                }
                $user->password = Hash::make(self::DEFAULT_PASSWORD);
                $user->blokir = 'N';
            } else {
                $user = new User();
                $user->username = $request->no_ktp;
                $user->level = 'komite-teknis';
                $user->password = Hash::make(self::DEFAULT_PASSWORD);
                $user->blokir = 'N';
            }

            $user->nama_lengkap = $komite->nama;
            $user->gelar_depan = $komite->gelar_depan;
            $user->gelar_blk = $komite->gelar_blk;
            $user->tmp_lahir = $komite->tmp_lahir;
            $user->tgl_lahir = $komite->tgl_lahir;
            $user->no_induk = $komite->no_induk;
            $user->no_ktp = $komite->no_ktp;
            $user->pendidikan_terakhir = $komite->pendidikan_terakhir;
            $user->email = $komite->email;
            $user->no_telp = $komite->no_hp;
            $user->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Komite berhasil ditambahkan. Password default telah dikirim ke personil.',
                'data' => $this->transformKartuKomite($komite),
                // Informasi akun login untuk dialog frontend saat tombol
                // "Tambah Anggota" ditekan (idem response create Penguji)
                'akun_login' => [
                    'password_default' => self::DEFAULT_PASSWORD,   // Kbl12345
                    // identifier yang bisa dipakai personil untuk login
                    'no_ktp' => $user->no_ktp,
                    'no_hp' => $user->no_telp,
                ],
                // Produksi: password dikirim via email+SMS ke personil, idem native.
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan komite',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // RESET PASSWORD (padanan handler resetpasswordkomite)
    // ════════════════════════════════════════════════════════════════

    /**
     * POST /api/v1/admin/komite/{id}/reset-password
     * Generate 6 digit → bcrypt (bukan MD5 ganda) → sinkron akun users.
     */
    public function resetPassword(Request $request, $id): JsonResponse
    {
        $komite = Komite::find($id);
        if (!$komite) {
            return response()->json([
                'success' => false,
                'message' => 'Update/Reset Password Komite Gagal - Data tidak ditemukan',
            ], 404);
        }

        $plainPassword = (string) random_int(100000, 999999);

        DB::beginTransaction();
        try {
            $hashed = Hash::make($plainPassword);
            $komite->password = $hashed;
            $komite->save();

            $linkedUser = User::where('username', $komite->no_ktp)->where('level', 'komite-teknis')->first();
            if (!$linkedUser) {
                $linkedUser = User::where('no_ktp', $komite->no_ktp)->where('level', 'komite-teknis')->first();
            }
            if ($linkedUser) {
                $linkedUser->password = $hashed;
                $linkedUser->save();
            }

            $channelLog = [];
            if ($komite->email) {
                $channelLog['email_ke'] = $komite->email;
            }
            if (strlen((string) $komite->no_hp) > 8) {
                $channelLog['sms_ke'] = $komite->no_hp;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Update/Reset Password Komite Sukses',
                'data' => [
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
    // UPDATE PROFIL (padanan module updatekomite — 33 kolom)
    // ════════════════════════════════════════════════════════════════

    /**
     * PUT/POST /api/v1/admin/komite/{id}  (multipart/form-data didukung)
     * Update profil + upload foto/sertifikat ke foto_komite/.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $komite = Komite::find($id);
        if (!$komite) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Komite tersebut Tidak Ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nama' => 'nullable|string|max:255',
            'gelar_depan' => 'nullable|string|max:50',
            'gelar_blk' => 'nullable|string|max:100',
            'jabatan_komite' => 'nullable|string|max:100',
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
            'no_serisertifikat' => 'nullable|string|max:100',
            'masaberlaku_lisensi' => 'nullable|date',
            'facebook' => 'nullable|string|max:255',
            'aktif' => 'nullable|in:Y,N',

            // Upload dokumen (6 field) → foto_komite/
            'foto' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:2048',
            'foto_sertifikat' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
            'ktp' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
            'kk' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
            'ijazah' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
            'transkrip' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Upload 6 dokumen: rename time_{field}_{uniqid}.ext → foto_komite/
            foreach (['foto', 'foto_sertifikat', 'ktp', 'kk', 'ijazah', 'transkrip'] as $field) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $filename = time() . '_' . $field . '_' . uniqid() . '.' . strtolower($file->getClientOriginalExtension());

                    $dest = public_path(self::UPLOAD_DIR);
                    if (!file_exists($dest)) mkdir($dest, 0755, true);

                    // Hapus file lama agar tidak menumpuk
                    $oldFile = public_path(self::UPLOAD_DIR . '/' . $komite->{$field});
                    if (!empty($komite->{$field}) && file_exists($oldFile)) @unlink($oldFile);

                    $file->move($dest, $filename);
                    $komite->{$field} = $filename;
                }
            }

            // Update field yang dikirim saja
            $textFields = ['nama','gelar_depan','gelar_blk','jabatan_komite','jenis_kelamin','agama','status_perkawinan',
                'tmp_lahir','tgl_lahir','email','no_hp','no_induk','no_ktp',
                'pendidikan_terakhir','tahun_lulus','bid_keahlian','pekerjaan','kebangsaan',
                'alamat','RT','RW','kelurahan','kecamatan','kota','propinsi','kodepos',
                'institusi_asal','telp_kantor','fax_kantor','email_kantor','no_lisensi',
                'no_serisertifikat','masaberlaku_lisensi','facebook','aktif'];
            foreach ($textFields as $field) {
                if ($request->has($field)) {
                    $val = $request->input($field);
                    $komite->{$field} = ($val === '') ? null : $val;
                }
            }

            // usia auto-update
            if ($komite->tgl_lahir) {
                $komite->usia = $komite->tgl_lahir->age;
            }

            $komite->save();

            // Sinkron akun users
            $linkedUser = User::where('username', $komite->no_ktp)->where('level', 'komite-teknis')->first();
            if (!$linkedUser) {
                $linkedUser = User::where('no_ktp', $komite->no_ktp)->where('level', 'komite-teknis')->first();
            }
            if ($linkedUser) {
                $linkedUser->nama_lengkap = $komite->nama;
                $linkedUser->gelar_depan = $komite->gelar_depan;
                $linkedUser->gelar_blk = $komite->gelar_blk;
                $linkedUser->no_induk = $komite->no_induk;
                $linkedUser->no_ktp = $komite->no_ktp;
                $linkedUser->email = $komite->email;
                $linkedUser->no_telp = $komite->no_hp;
                $linkedUser->blokir = ($komite->aktif === 'Y') ? 'N' : 'Y';
                $linkedUser->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Profil Komite berhasil diperbarui',
                'data' => $this->transformKartuKomite($komite->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui profil komite',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HAPUS KOMITE (padanan handler hapuskomite)
    // ════════════════════════════════════════════════════════════════

    /**
     * DELETE /api/v1/admin/komite/{id}
     * Native: handler aktif tapi tombol UI di-comment. Di API endpoint tetap
     * tersedia (keputusan tampil/menyembunyikan tombol ada di frontend).
     */
    public function destroy($id): JsonResponse
    {
        $komite = Komite::find($id);
        if (!$komite) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Komite tersebut Tidak Ditemukan',
            ], 404);
        }

        DB::beginTransaction();
        try {
            // Hapus akun users terkait
            User::where('username', $komite->no_ktp)->where('level', 'komite-teknis')->delete();
            $komite->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Hapus Data Sukses',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus komite',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
