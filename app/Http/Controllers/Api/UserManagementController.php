<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Manajemen Pengguna (Users internal admin/staff LSK) — implementasi modul
 * `users` / `ubahusers` / `moduluser` PHP Native versi API.
 * Sesuai docs/BACKEND_MANAJEMEN_PENGGUNA.md:
 *
 * - List + lazy-init id_session = md5(username) (auto-fill saat list dibuka)
 * - Create: validasi konfirmasi password + dup-check username
 *   + INSERT blokir (perbaikan atas native yang melewatkannya)
 * - Update 20 kolom: password opsional (kosong = tetap), username locked,
 *   upload foto ke foto_pengguna/
 * - Delete: guard server-side (punya hak akses → tolak) + cascade users_modul
 * - Hak akses: pivot users_modul via id_session (md5 username), toggle per modul
 *
 * Konsep akses: level='admin' bypass semua; level lain butuh baris users_modul.
 */
class UserManagementController extends Controller
{
    /** Direktori upload foto pengguna. */
    private const UPLOAD_DIR = 'foto_pengguna';

    // ════════════════════════════════════════════════════════════════
    // LIST (padanan module users + auto-fill id_session)
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/pengguna?search=&page=&per_page=
     * Daftar pengguna + jumlah hak akses; lazy-init id_session = md5(username).
     */
    public function index(Request $request): JsonResponse
    {
        // ⭐ Lazy-init: user lama tanpa id_session di-migrate ke md5 (idem native)
        DB::table('users')
            ->whereNull('id_session')
            ->update(['id_session' => DB::raw('MD5(username)')]);

        $query = User::query()->orderBy('username', 'asc');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('username', 'like', "%{$s}%")
                    ->orWhere('nama_lengkap', 'like', "%{$s}%");
            });
        }

        $perPage = min((int) ($request->per_page ?? 20), 100);
        $result = $query->paginate($perPage);

        // Jumlah hak akses per user (padanan Common Query #1)
        $aksesCount = DB::table('users_modul')
            ->selectRaw('id_session, COUNT(*) as jumlah')
            ->groupBy('id_session')
            ->pluck('jumlah', 'id_session');

        $data = collect($result->items())->map(function ($user) use ($aksesCount) {
            return $this->transformUser($user, (int) ($aksesCount[$user->id_session] ?? 0));
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
     * GET /api/v1/admin/pengguna/{username}
     * Detail lengkap untuk pre-fill form edit (padanan LOAD ubahusers).
     */
    public function show($username): JsonResponse
    {
        $user = User::find($username);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Pengguna tersebut Tidak Ditemukan',
            ], 404);
        }

        // Pastikan id_session terisi
        if (empty($user->id_session)) {
            $user->id_session = md5($user->username);
            $user->save();
        }

        $data = $this->transformUser($user, null, detail: true);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // CREATE (padanan handler tambahusers)
    // ════════════════════════════════════════════════════════════════

    /**
     * POST /api/v1/admin/pengguna
     *
     * Validasi konfirmasi password + dup-check username → INSERT.
     * Perbaikan atas native: blokir ikut di-INSERT (form mengirim tapi
     * native melewatkannya), id_session langsung diisi md5(username).
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:50',
            'nama_lengkap' => 'required|string|max:100',
            'no_induk' => 'nullable|string|max:100',
            'password' => 'required|string|min:5',
            'passwordkonfirmasi' => 'required|string|same:password',
            'level' => 'required|in:admin,user',
            'blokir' => 'nullable|in:Y,N',
        ], [
            'username.required' => 'Username wajib diisi',
            'nama_lengkap.required' => 'Nama lengkap wajib diisi',
            'password.required' => 'Kata Sandi wajib diisi',
            'passwordkonfirmasi.same' => 'Kata Sandi dan Kata Sandi (Ulangi) harus sama',
            'level.required' => 'Level wajib dipilih',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Dup-check username (idem native)
        if (User::where('username', $request->username)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Pengguna dengan Username tersebut Sudah Ada',
            ], 409);
        }

        try {
            $user = new User([
                'username' => strip_tags(trim($request->username)),
                'password' => Hash::make($request->password),   // bcrypt (bukan MD5)
                'nama_lengkap' => strip_tags(trim($request->nama_lengkap)),
                'no_induk' => $request->no_induk,
                'level' => $request->level,
                'blokir' => $request->input('blokir', 'N'),     // ✅ fix: ikut di-INSERT
            ]);
            $user->id_session = md5($user->username);           // ⭐ kunci pivot hak akses
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Pengguna berhasil ditambahkan',
                'data' => $this->transformUser($user, 0),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan pengguna',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // UPDATE (padanan module ubahusers&u={username})
    // ════════════════════════════════════════════════════════════════

    /**
     * PUT/POST /api/v1/admin/pengguna/{username}  (multipart/form-data didukung)
     *
     * - Username locked (tidak bisa diganti — melindungi pivot id_session)
     * - Password opsional: kosong = pertahankan lama
     * - Upload foto → foto_pengguna/ (unlink lama dulu)
     * - Update profil 20 kolom + level + blokir
     */
    public function update(Request $request, $username): JsonResponse
    {
        $user = User::find($username);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Pengguna tersebut Tidak Ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nama_lengkap' => 'nullable|string|max:100',
            'gelar_depan' => 'nullable|string|max:50',
            'gelar_blk' => 'nullable|string|max:100',
            'tmp_lahir' => 'nullable|string|max:255',
            'tgl_lahir' => 'nullable|date',
            'no_induk' => 'nullable|string|max:100',
            'no_ktp' => 'nullable|string|max:50',
            'pendidikan_terakhir' => 'nullable|string|max:10',
            'email' => 'nullable|email|max:100',
            'alamat' => 'nullable|string',
            'RT' => 'nullable|string|max:5',
            'RW' => 'nullable|string|max:5',
            'kelurahan' => 'nullable|string|max:255',
            'kecamatan' => 'nullable|string|max:255',
            'kota' => 'nullable|string|max:255',
            'propinsi' => 'nullable|string|max:255',
            'no_telp' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:5',
            'passwordkonfirmasi' => 'nullable|string|same:password',
            'level' => 'nullable|in:admin,user',
            'blokir' => 'nullable|in:Y,N',

            // Upload foto profil
            'foto' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:5120',
        ], [
            'passwordkonfirmasi.same' => 'Kata Sandi dan Kata Sandi (Ulangi) harus sama',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'success' => false,
                'message' => $firstError ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Upload foto (rename timestamp.md5.ext, unlink lama)
            if ($request->hasFile('foto')) {
                $file = $request->file('foto');
                $ext = strtolower($file->getClientOriginalExtension());
                $fileName = time() . md5($file->getClientOriginalName() . microtime()) . '.' . $ext;

                $dest = public_path(self::UPLOAD_DIR);
                if (!file_exists($dest)) mkdir($dest, 0755, true);

                $oldFile = public_path(self::UPLOAD_DIR . '/' . $user->foto);
                if (!empty($user->foto) && file_exists($oldFile)) @unlink($oldFile);

                $file->move($dest, $fileName);
                $user->foto = $fileName;
            }

            // Password opsional: kosong = pertahankan lama (idem native)
            if ($request->filled('password')) {
                $user->password = Hash::make($request->password);
            }

            // Update field profil yang dikirim (username TIDAK — locked)
            $textFields = ['nama_lengkap','gelar_depan','gelar_blk','tmp_lahir','tgl_lahir',
                'no_induk','no_ktp','pendidikan_terakhir','email','alamat','RT','RW',
                'kelurahan','kecamatan','kota','propinsi','no_telp','level','blokir'];
            foreach ($textFields as $field) {
                if ($request->has($field)) {
                    $val = $request->input($field);
                    $user->{$field} = ($val === '') ? null : $val;
                }
            }

            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Profil pengguna berhasil diperbarui',
                'data' => $this->transformUser($user->fresh(), null, detail: true),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui pengguna',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // DELETE (padanan handler hapususers)
    // ════════════════════════════════════════════════════════════════

    /**
     * DELETE /api/v1/admin/pengguna/{username}
     *
     * ✅ Server-side guard (perbaikan atas UI-only native): pengguna yang
     * sudah punya hak akses ditolak; cascade bersihkan users_modul jika lolos.
     */
    public function destroy($username): JsonResponse
    {
        $user = User::find($username);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Pengguna tersebut Tidak Ditemukan',
            ], 404);
        }

        // Admin tidak boleh dihapus (min. 1 admin harus tersisa)
        if ($user->level === 'admin') {
            $adminCount = User::where('level', 'admin')->count();
            if ($adminCount <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat menghapus admin terakhir',
                ], 400);
            }
        }

        // ✅ Server-side guard (perbaikan atas UI-only native): pengguna yang
        // sudah punya hak akses dianggap aktif digunakan → tolak penghapusan
        $idSession = $user->id_session ?? md5($username);
        $jumlahAkses = DB::table('users_modul')->where('id_session', $idSession)->count();
        if ($jumlahAkses > 0) {
            return response()->json([
                'success' => false,
                'message' => "Pengguna tidak dapat dihapus karena memiliki {$jumlahAkses} hak akses modul. Hapus hak aksesnya terlebih dahulu.",
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Cascade: bersihkan pivot hak akses (fix orphan native)
            DB::table('users_modul')->where('id_session', $user->id_session ?? md5($username))->delete();

            // Hapus file foto
            if (!empty($user->foto)) {
                $abs = public_path(self::UPLOAD_DIR . '/' . $user->foto);
                if (file_exists($abs)) @unlink($abs);
            }

            $user->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Hapus Data Sukses',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus pengguna',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HAK AKSES (padanan module moduluser&uid={md5(username)})
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/pengguna/{username}/hak-akses
     * Padanan render moduluser: hak akses existing + daftar modul aktif
     * dengan flag sudah/belum (toggle UI).
     */
    public function hakAkses($username): JsonResponse
    {
        $user = User::find($username);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Pengguna tersebut Tidak Ditemukan',
            ], 404);
        }

        // Pastikan id_session terisi (lazy-init)
        if (empty($user->id_session)) {
            $user->id_session = md5($username);
            $user->save();
        }

        // Hak akses existing (padanan Bagian 1)
        $existing = DB::table('users_modul as um')
            ->join('modul as m', 'm.id_modul', '=', 'um.id_modul')
            ->where('um.id_session', $user->id_session)
            ->orderBy('m.urutan', 'asc')
            ->get(['m.id_modul', 'm.nama_modul', 'm.link']);

        // Daftar modul aktif + flag sudah/belum (padanan Bagian 2, Common Query #2/#3)
        $modulAktif = DB::table('modul')
            ->where('aktif', 'Y')
            ->orderBy('urutan', 'asc')
            ->get(['id_modul', 'nama_modul', 'link', 'status']);

        $existingIds = $existing->pluck('id_modul')->all();

        return response()->json([
            'success' => true,
            'data' => [
                'username' => $user->username,
                'id_session' => $user->id_session,
                'level' => $user->level,
                // admin bypass semua — info untuk frontend
                'bypass_semua' => $user->level === 'admin',
                'hak_akses' => $existing->map(fn ($m) => [
                    'id_modul' => $m->id_modul,
                    'nama_modul' => $m->nama_modul,
                    'link' => $m->link,
                ]),
                'daftar_modul' => $modulAktif->map(fn ($m) => [
                    'id_modul' => $m->id_modul,
                    'nama_modul' => $m->nama_modul,
                    'link' => $m->link,
                    'status' => $m->status,
                    // true = SUDAH punya akses (tombol Hapus); false = BELUM (tombol Tambah)
                    'punya_akses' => in_array($m->id_modul, $existingIds),
                ]),
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/pengguna/{username}/hak-akses  { id_modul }
     * Padanan tambahhakakses.php: INSERT pivot jika kombinasi belum ada.
     */
    public function tambahHakAkses(Request $request, $username): JsonResponse
    {
        $user = User::find($username);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Pengguna tersebut Tidak Ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'id_modul' => 'required|integer|exists:modul,id_modul',
        ], [
            'id_modul.required' => 'Modul wajib dipilih',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $idSession = $user->id_session ?? md5($username);

        // Dup-check kombinasi (idem native)
        $exists = DB::table('users_modul')
            ->where('id_session', $idSession)
            ->where('id_modul', $request->id_modul)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Hak akses modul tersebut sudah ada',
            ], 409);
        }

        DB::table('users_modul')->insert([
            'id_session' => $idSession,
            'id_modul' => $request->id_modul,
            'updated' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Hak akses modul berhasil ditambahkan',
        ], 201);
    }

    /**
     * DELETE /api/v1/admin/pengguna/{username}/hak-akses/{idModul}
     * Padanan hapushakakses.php: DELETE pivot jika kombinasi ada.
     */
    public function hapusHakAkses($username, $idModul): JsonResponse
    {
        $user = User::find($username);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf Pengguna tersebut Tidak Ditemukan',
            ], 404);
        }

        $idSession = $user->id_session ?? md5($username);

        $deleted = DB::table('users_modul')
            ->where('id_session', $idSession)
            ->where('id_modul', $idModul)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Hak akses modul tersebut tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Hak akses modul berhasil dihapus',
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    /**
     * Transform user menjadi payload (list ringkas / detail lengkap).
     */
    private function transformUser(User $user, ?int $jumlahAkses = null, bool $detail = false): array
    {
        if ($jumlahAkses === null) {
            $jumlahAkses = DB::table('users_modul')
                ->where('id_session', $user->id_session ?? md5($user->username))
                ->count();
        }

        $data = [
            'username' => $user->username,
            'nama_lengkap' => $user->nama_lengkap,
            'level' => $user->level,
            'blokir' => $user->blokir,
            'status_login' => $user->blokir === 'N' ? 'AKTIF' : 'DIBLOKIR',
            'id_session' => $user->id_session ?? md5($user->username),
            'jumlah_hak_akses' => $jumlahAkses,
            // Hapus hanya jika tanpa hak akses + bukan admin (idem tombol kondisional native)
            'bisa_dihapus' => $jumlahAkses === 0 && $user->level !== 'admin',
            'bypass_semua' => $user->level === 'admin',
        ];

        if ($detail) {
            $data = array_merge($data, [
                'gelar_depan' => $user->gelar_depan,
                'gelar_blk' => $user->gelar_blk,
                'tmp_lahir' => $user->tmp_lahir,
                'tgl_lahir' => $user->tgl_lahir ? date('Y-m-d', strtotime($user->tgl_lahir)) : null,
                'no_induk' => $user->no_induk,
                'no_ktp' => $user->no_ktp,
                'pendidikan_terakhir' => $user->pendidikan_terakhir,
                'email' => $user->email,
                'alamat' => $user->alamat,
                'RT' => $user->RT,
                'RW' => $user->RW,
                'kelurahan' => $user->kelurahan,
                'kecamatan' => $user->kecamatan,
                'kota' => $user->kota,
                'propinsi' => $user->propinsi,
                'no_telp' => $user->no_telp,
                'foto' => $user->foto,
                'foto_url' => $user->foto ? asset(self::UPLOAD_DIR . '/' . $user->foto) : null,
            ]);
        }

        return $data;
    }
}
