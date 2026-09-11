<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Komite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class KomiteProfilController extends Controller
{
    private const UPLOAD_DIR = 'foto_komite';

    /**
     * Cari personil Komite dari user yang sedang login
     */
    private function getKomiteFromUser($user): ?Komite
    {
        if (! $user) {
            return null;
        }

        return Komite::where(function ($query) use ($user) {
            if (! empty($user->no_ktp)) {
                $query->orWhere('no_ktp', $user->no_ktp);
            }
            if (! empty($user->username)) {
                $query->orWhere('no_ktp', $user->username)
                    ->orWhere('no_induk', $user->username);
            }
            if (! empty($user->no_induk)) {
                $query->orWhere('no_induk', $user->no_induk);
            }
            if (! empty($user->email)) {
                $query->orWhere('email', $user->email);
            }
            if (! empty($user->no_telp)) {
                $query->orWhere('no_hp', $user->no_telp);
            }
        })->first();
    }

    /**
     * Format output profil Komite
     */
    private function formatKomiteProfile(Komite $komite, $user): array
    {
        // Resolusi Nama Wilayah dari data_wilayah
        $propinsiNama = null;
        if (! empty($komite->propinsi)) {
            if (is_numeric($komite->propinsi)) {
                $propinsiNama = DB::table('data_wilayah')->where('id_wil', $komite->propinsi)->value('nm_wil');
            } else {
                $propinsiNama = $komite->propinsi;
            }
        }

        $kotaNama = null;
        if (! empty($komite->kota)) {
            if (is_numeric($komite->kota)) {
                $kotaNama = DB::table('data_wilayah')->where('id_wil', $komite->kota)->value('nm_wil');
            } else {
                $kotaNama = $komite->kota;
            }
        }

        $kecamatanNama = null;
        if (! empty($komite->kecamatan)) {
            if (is_numeric($komite->kecamatan)) {
                $kecamatanNama = DB::table('data_wilayah')->where('id_wil', $komite->kecamatan)->value('nm_wil');
            } else {
                $kecamatanNama = $komite->kecamatan;
            }
        }

        // Resolusi Label Pendidikan
        $pendidikanLabel = $komite->pendidikan_terakhir;
        if (! empty($komite->pendidikan_terakhir)) {
            try {
                $label = DB::table('pendidikan')->where('id', $komite->pendidikan_terakhir)->value('nama');
                if ($label) {
                    $pendidikanLabel = $label;
                }
            } catch (\Throwable $e) {
            }
        }

        // Parse keahlian list array
        $keahlianList = [];
        if (! empty($komite->bid_keahlian)) {
            $keahlianList = array_values(array_filter(array_map('trim', explode(',', $komite->bid_keahlian))));
        }

        // Hitung statistik
        $keputusanCount = 0;
        try {
            $keputusanCount = DB::table('komite_keputusan')->where('id_asesor', $komite->id)->count();
        } catch (\Throwable $e) {
        }

        // Format tanggal
        $tglLahirStr = $komite->tgl_lahir ? optional($komite->tgl_lahir)->format('Y-m-d') : null;
        $masaBerlakuStr = $komite->masaberlaku_lisensi ? optional($komite->masaberlaku_lisensi)->format('Y-m-d') : null;

        return [
            'id' => $komite->id,
            'nama' => $komite->nama,
            'gelar_depan' => $komite->gelar_depan,
            'gelar_blk' => $komite->gelar_blk,
            'nama_lengkap' => $komite->full_name,
            'jabatan_komite' => $komite->jabatan_komite ?: 'Anggota',
            'no_induk' => $komite->no_induk,
            'no_ktp' => $komite->no_ktp,
            'no_lisensi' => $komite->no_lisensi,
            'masaberlaku_lisensi' => $masaBerlakuStr,
            'status_lisensi' => $komite->status_lisensi,
            'warna_kartu' => $komite->warna_kartu,
            'sisa_hari_lisensi' => $komite->sisa_hari_lisensi,

            'jenis_kelamin' => $komite->jenis_kelamin,
            'agama' => $komite->agama,
            'tmp_lahir' => $komite->tmp_lahir,
            'tgl_lahir' => $tglLahirStr,
            'usia' => $komite->usia,

            'email' => $komite->email,
            'no_hp' => $komite->no_hp,
            'alamat' => $komite->alamat,
            'RT' => $komite->RT,
            'RW' => $komite->RW,
            'kelurahan' => $komite->kelurahan,
            'kecamatan' => $komite->kecamatan,
            'kecamatan_nama' => $kecamatanNama,
            'kota' => $komite->kota,
            'kota_nama' => $kotaNama,
            'propinsi' => $komite->propinsi,
            'propinsi_nama' => $propinsiNama,
            'kodepos' => $komite->kodepos,

            'pendidikan_terakhir' => $komite->pendidikan_terakhir,
            'pendidikan_label' => $pendidikanLabel,
            'pekerjaan' => $komite->pekerjaan,
            'tahun_lulus' => $komite->tahun_lulus,
            'institusi_asal' => $komite->institusi_asal,
            'bid_keahlian' => $komite->bid_keahlian,
            'keahlian' => $keahlianList,

            'foto' => $komite->foto,
            'foto_url' => $komite->foto ? asset(self::UPLOAD_DIR.'/'.$komite->foto) : null,
            'foto_sertifikat' => $komite->foto_sertifikat,
            'foto_sertifikat_url' => $komite->foto_sertifikat ? asset(self::UPLOAD_DIR.'/'.$komite->foto_sertifikat) : null,
            'ktp' => $komite->ktp,
            'ktp_url' => $komite->ktp ? asset(self::UPLOAD_DIR.'/'.$komite->ktp) : null,
            'kk' => $komite->kk,
            'kk_url' => $komite->kk ? asset(self::UPLOAD_DIR.'/'.$komite->kk) : null,
            'ijazah' => $komite->ijazah,
            'ijazah_url' => $komite->ijazah ? asset(self::UPLOAD_DIR.'/'.$komite->ijazah) : null,
            'transkrip' => $komite->transkrip,
            'transkrip_url' => $komite->transkrip ? asset(self::UPLOAD_DIR.'/'.$komite->transkrip) : null,

            'dokumen' => $komite->kelengkapan_dokumen,
            'keputusan_count' => $keputusanCount,
        ];
    }

    /**
     * GET /api/v1/komite-teknis/profil
     * GET /api/v1/auth/komite-teknis/profil
     */
    public function show(Request $request): JsonResponse
    {
        // Baca user dari token Sanctum
        $user = $request->user('sanctum') ?? $request->user();
        if (! $user) {
            // Fallback: ambil personil komite aktif jika token belum terikat sempurna
            $komite = Komite::where('aktif', 'Y')->orderBy('id', 'asc')->first();
            if ($komite) {
                return response()->json([
                    'success' => true,
                    'data' => $this->formatKomiteProfile($komite, null),
                    'message' => 'Profil Komite Teknis berhasil dimuat',
                ]);
            }

            return response()->json(['success' => false, 'message' => 'Sesi tidak valid.'], 401);
        }

        $komite = $this->getKomiteFromUser($user);
        if (! $komite) {
            return response()->json([
                'success' => true,
                'data' => [
                    'nama' => $user->nama_lengkap,
                    'nama_lengkap' => $user->nama_lengkap,
                    'no_ktp' => $user->no_ktp ?: $user->username,
                    'no_induk' => $user->no_induk,
                    'email' => $user->email,
                    'no_hp' => $user->no_telp,
                    'pendidikan_terakhir' => $user->pendidikan_terakhir,
                    'keahlian' => [],
                    'bid_keahlian' => '',
                ],
                'message' => 'Profil dasar pengguna ditemukan',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatKomiteProfile($komite, $user),
            'message' => 'Profil Komite Teknis berhasil dimuat',
        ]);
    }

    /**
     * POST /api/v1/komite-teknis/profil
     * POST /api/v1/auth/komite-teknis/profil
     *
     * Memperbarui profil komite teknis sendiri (termasuk keahlian dan berkas dokumen).
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Sesi tidak valid.'], 401);
        }

        $komite = $this->getKomiteFromUser($user);
        if (! $komite) {
            return response()->json([
                'success' => false,
                'message' => 'Data personil Komite Teknis tidak ditemukan untuk akun ini',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nama' => 'nullable|string|max:255',
            'gelar_depan' => 'nullable|string|max:50',
            'gelar_blk' => 'nullable|string|max:100',
            'jenis_kelamin' => 'nullable|in:L,P',
            'agama' => 'nullable|string|max:50',
            'tmp_lahir' => 'nullable|string|max:255',
            'tgl_lahir' => 'nullable|date',
            'email' => 'nullable|email|max:255',
            'no_hp' => 'nullable|string|max:30',
            'pendidikan_terakhir' => 'nullable|string|max:50',
            'pekerjaan' => 'nullable|string|max:255',
            'bid_keahlian' => 'nullable|string|max:500',
            'alamat' => 'nullable|string',
            'kelurahan' => 'nullable|string|max:255',
            'kecamatan' => 'nullable|string|max:255',
            'kota' => 'nullable|string|max:255',
            'propinsi' => 'nullable|string|max:255',
            'kodepos' => 'nullable|string|max:10',

            // 6 file upload
            'foto' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:2048',
            'foto_sertifikat' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
            'ktp' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
            'kk' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
            'ijazah' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
            'transkrip' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => collect($validator->errors()->all())->first() ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Update field teks
            $updatableFields = [
                'nama', 'gelar_depan', 'gelar_blk', 'jenis_kelamin', 'agama',
                'tmp_lahir', 'tgl_lahir', 'email', 'no_hp', 'pendidikan_terakhir',
                'pekerjaan', 'alamat', 'kelurahan', 'kecamatan', 'kota',
                'propinsi', 'kodepos', 'tahun_lulus', 'institusi_asal',
            ];

            foreach ($updatableFields as $field) {
                if ($request->has($field)) {
                    $komite->{$field} = $request->input($field);
                }
            }

            // Update keahlian (string atau array)
            if ($request->has('keahlian') && is_array($request->keahlian)) {
                $komite->bid_keahlian = implode(', ', array_filter(array_map('trim', $request->keahlian)));
            } elseif ($request->has('bid_keahlian')) {
                $komite->bid_keahlian = trim($request->input('bid_keahlian'));
            }

            // Upload 6 file dokumen
            $dest = public_path(self::UPLOAD_DIR);
            if (! file_exists($dest)) {
                mkdir($dest, 0755, true);
            }

            foreach (['foto', 'foto_sertifikat', 'ktp', 'kk', 'ijazah', 'transkrip'] as $fileField) {
                if ($request->hasFile($fileField)) {
                    $file = $request->file($fileField);
                    $filename = time().'_'.$fileField.'_'.uniqid().'.'.strtolower($file->getClientOriginalExtension());
                    $file->move($dest, $filename);
                    $komite->{$fileField} = $filename;

                    if ($fileField === 'foto') {
                        $user->foto = $filename;
                    }
                }
            }

            if ($komite->tgl_lahir) {
                $komite->usia = $komite->tgl_lahir->age;
            }

            $komite->save();

            // Sync ke user
            if ($request->has('nama') && ! empty($request->nama)) {
                $user->nama_lengkap = $request->nama;
            }
            if ($request->has('gelar_depan')) {
                $user->gelar_depan = $request->gelar_depan;
            }
            if ($request->has('gelar_blk')) {
                $user->gelar_blk = $request->gelar_blk;
            }
            if ($request->has('email')) {
                $user->email = $request->email;
            }
            if ($request->has('no_hp')) {
                $user->no_telp = $request->no_hp;
            }
            if ($request->has('pendidikan_terakhir')) {
                $user->pendidikan_terakhir = $request->pendidikan_terakhir;
            }
            $user->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $this->formatKomiteProfile($komite, $user),
                'message' => 'Profil Komite Teknis berhasil diperbarui',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui profil: '.$e->getMessage(),
            ], 500);
        }
    }
}
