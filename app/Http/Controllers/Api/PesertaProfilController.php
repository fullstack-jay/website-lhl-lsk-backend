<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesi;
use App\Models\MasterKeahlian;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PesertaProfilController extends Controller
{
    /**
     * GET /api/v1/peserta/profil
     */
    /**
     * GET /api/v1/peserta/sertifikat
     * Daftar seluruh sertifikat milik peserta ini (dari tabel relasi
     * asesi_sertifikat — mendukung pemegang lebih dari satu sertifikat),
     * digabung dengan no_sertifikat utama di tabel asesi (bila berbeda).
     */
    public function sertifikatSaya(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Sesi tidak valid.'], 401);
        }

        $asesi = Asesi::where('no_ktp', $user->no_ktp)
            ->orWhere('no_pendaftaran', $user->username)
            ->orWhere('no_pendaftaran', $user->no_induk)
            ->orWhere('nohp', $user->no_telp)
            ->first();

        if (!$asesi) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Belum ada data sertifikat',
            ]);
        }

        $items = collect();

        // 1. Dari tabel relasi asesi_sertifikat (mendukung multi-sertifikat)
        if (\Illuminate\Support\Facades\Schema::hasTable('asesi_sertifikat')) {
            foreach (DB::table('asesi_sertifikat')
                ->where('no_pendaftaran', $asesi->no_pendaftaran)
                ->orderBy('tgl_sertifikat')
                ->get() as $r) {
                $items->push([
                    'no_sertifikat' => $r->no_sertifikat,
                    'jenis_sertifikat' => $r->jenis_sertifikat,
                    'tgl_sertifikat' => $r->tgl_sertifikat,
                    'tahun' => $r->tahun,
                    'file_sertifikat' => $r->file_sertifikat,
                    'file_url' => $r->file_sertifikat
                        ? asset('storage/foto_asesi/' . $r->file_sertifikat) : null,
                    'sumber' => 'relasi',
                ]);
            }
        }

        // 2. Fallback: sertifikat utama di tabel asesi (bila belum tercatat di relasi)
        if (!empty($asesi->no_sertifikat) && !$items->contains('no_sertifikat', $asesi->no_sertifikat)) {
            $items->push([
                'no_sertifikat' => $asesi->no_sertifikat,
                'jenis_sertifikat' => $asesi->jenis_sertifikat,
                'tgl_sertifikat' => $asesi->tgl_sertifikat,
                'tahun' => $asesi->tgl_sertifikat ? (int) date('Y', strtotime($asesi->tgl_sertifikat)) : null,
                'file_sertifikat' => $asesi->file_sertifikat_aktif,
                'file_url' => $asesi->file_sertifikat_aktif
                    ? asset('storage/foto_asesi/' . $asesi->file_sertifikat_aktif) : null,
                'sumber' => 'asesi',
            ]);
        }

        return response()->json([
            'success' => true,
            'jumlah' => $items->count(),
            'data' => $items->values(),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Sesi tidak valid.'], 401);
        }

        $asesi = Asesi::where('no_ktp', $user->no_ktp)
            ->orWhere('no_pendaftaran', $user->username)
            ->orWhere('no_pendaftaran', $user->no_induk)
            ->orWhere('nohp', $user->no_telp)
            ->first();

        // Cari data pendaftaran jika ada field yang masih belum tersimpan di asesi/user
        $pendaftaran = \App\Models\Pendaftaran::where('no_ktp', $user->no_ktp)
            ->orWhere('email', $user->email)
            ->orWhere('no_pendaftaran', $user->username)
            ->orWhere('no_hp', $user->no_telp)
            ->latest()
            ->first();

        if (!$asesi) {
            return response()->json([
                'success' => true,
                'data' => [
                    'no_pendaftaran' => $user->no_induk ?: ($pendaftaran?->no_pendaftaran ?: $user->username),
                    'no_ktp' => $user->no_ktp,
                    'nama' => $user->nama_lengkap,
                    'email' => $user->email,
                    'nohp' => $user->no_telp,
                    'pendidikan' => $user->pendidikan_terakhir ?: ($pendaftaran?->kualifikasi_pendidikan),
                    'keahlian_penyusun' => $user->keahlian_penyusun ?: ($pendaftaran?->bidang_keahlian),
                    'alamat' => $user->alamat ?: ($pendaftaran?->alamat),
                    'RT' => $user->RT,
                    'RW' => $user->RW,
                    'propinsi' => $user->propinsi ?: ($pendaftaran?->propinsi),
                    'kota' => $user->kota ?: ($pendaftaran?->kota),
                    'kecamatan' => $user->kecamatan ?: ($pendaftaran?->kecamatan),
                    'kelurahan' => $user->kelurahan ?: ($pendaftaran?->kelurahan),
                    'kodepos' => $pendaftaran?->kode_pos,
                    'foto_url' => $user->foto ? url('storage/foto_asesi/' . $user->foto) : null,
                    'is_empty' => true,
                ],
            ]);
        }

        // Resolusi Nama Wilayah
        $propinsiNama = null;
        if (!empty($asesi->propinsi)) {
            if (is_numeric($asesi->propinsi)) {
                $propinsiNama = DB::table('data_wilayah')->where('id_wil', $asesi->propinsi)->value('nm_wil');
            } else {
                $propinsiNama = $asesi->propinsi;
            }
        }

        $kotaNama = null;
        if (!empty($asesi->kota)) {
            if (is_numeric($asesi->kota)) {
                $kotaNama = DB::table('data_wilayah')->where('id_wil', $asesi->kota)->value('nm_wil');
            } else {
                $kotaNama = $asesi->kota;
            }
        }

        $kecamatanNama = null;
        if (!empty($asesi->kecamatan)) {
            if (is_numeric($asesi->kecamatan)) {
                $kecamatanNama = DB::table('data_wilayah')->where('id_wil', $asesi->kecamatan)->value('nm_wil');
            } else {
                $kecamatanNama = $asesi->kecamatan;
            }
        }

        $baseUrl = url('/');

        // Map Fallback Documents
        $sertifikatAmdal = $asesi->sertifikat_amdal ?: $asesi->sertifikat;
        $buktiKeterlibatan = $asesi->bukti_keterlibatan ?: $asesi->suket;
        $sertifikatKompetensiLain = $asesi->sertifikat_kompetensi_lain ?: $asesi->transkrip;

                // Cek apakah 4 syarat pokok terverifikasi
        $verifDok = is_array($asesi->verifikasi_dokumen)
            ? $asesi->verifikasi_dokumen
            : (is_string($asesi->verifikasi_dokumen) ? (json_decode($asesi->verifikasi_dokumen, true) ?: []) : []);

        $wajibShortcodes = ['ijazah', 'sertifikat_amdal', 'bukti_keterlibatan', 'dokumen_amdal'];
        $allWajibVerified = true;
        foreach ($wajibShortcodes as $sc) {
            $alias = match ($sc) {
                'sertifikat_amdal' => 'sertifikat',
                'bukti_keterlibatan' => 'suket',
                'dokumen_amdal' => 'salinan_dokumen',
                default => null,
            };
            $scVerif = ($verifDok[$sc] ?? '') === 'terverifikasi' || ($alias && ($verifDok[$alias] ?? '') === 'terverifikasi');
            if (!$scVerif) {
                $allWajibVerified = false;
                break;
            }
        }

        // Profil terverifikasi jika 4 syarat pokok terverifikasi, tetapi asesi->verifikasi tetap P sampai admin tanda tangan & ACC

        $isVerified = ($asesi->verifikasi === 'V') || $allWajibVerified;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $asesi->id,
                'no_pendaftaran' => $asesi->no_pendaftaran,
                'no_ktp' => $asesi->no_ktp,
                'nama' => $asesi->nama,
                'tmp_lahir' => $asesi->tmp_lahir,
                'tgl_lahir' => $asesi->tgl_lahir ? $asesi->tgl_lahir->format('Y-m-d') : null,
                'jenis_kelamin' => $asesi->jenis_kelamin ?: 'L',
                'pendidikan' => $asesi->pendidikan ?: ($user->pendidikan_terakhir ?: ($pendaftaran?->kualifikasi_pendidikan)),
                'keahlian_penyusun' => $asesi->keahlian_penyusun ?: ($user->keahlian_penyusun ?: ($pendaftaran?->bidang_keahlian)),
                'email' => $asesi->email,
                'nohp' => $asesi->nohp,
                'alamat' => $asesi->alamat ?: ($user->alamat ?: ($pendaftaran?->alamat)),
                'RT' => $asesi->RT ?: ($user->RT ?: null),
                'RW' => $asesi->RW ?: ($user->RW ?: null),
                'kelurahan' => $asesi->kelurahan ?: ($user->kelurahan ?: $pendaftaran?->kelurahan),
                'kecamatan' => $asesi->kecamatan ?: ($user->kecamatan ?: $pendaftaran?->kecamatan),
                'kecamatan_nama' => $kecamatanNama,
                'kota' => $asesi->kota ?: ($user->kota ?: $pendaftaran?->kota),
                'kota_nama' => $kotaNama,
                'propinsi' => $asesi->propinsi ?: ($user->propinsi ?: $pendaftaran?->propinsi),
                'propinsi_nama' => $propinsiNama,
                'kodepos' => $asesi->kodepos,
                'no_sertifikat' => $asesi->no_sertifikat,
                'tgl_sertifikat' => $asesi->tgl_sertifikat ? $asesi->tgl_sertifikat->format('Y-m-d') : null,
                'angkatan' => $asesi->angkatan,
                'verifikasi' => $isVerified ? 'V' : $asesi->verifikasi,
                'verifikasi_dokumen' => $verifDok,
                'profil_terverifikasi' => $isVerified,
                'syarat_pokok_terverifikasi' => $allWajibVerified,
                'blokir' => $asesi->blokir,
                
                // Syarat Dasar / Pokok
                'ijazah' => $asesi->ijazah,
                'ijazah_url' => $asesi->ijazah ? "{$baseUrl}/storage/foto_asesi/" . $asesi->ijazah : null,
                
                'sertifikat_amdal' => $sertifikatAmdal,
                'sertifikat_amdal_url' => $sertifikatAmdal ? "{$baseUrl}/storage/foto_asesi/" . $sertifikatAmdal : null,
                
                'bukti_keterlibatan' => $buktiKeterlibatan,
                'bukti_keterlibatan_url' => $buktiKeterlibatan ? "{$baseUrl}/storage/foto_asesi/" . $buktiKeterlibatan : null,
                
                'dokumen_amdal' => $asesi->dokumen_amdal,
                'dokumen_amdal_url' => $asesi->dokumen_amdal ? "{$baseUrl}/storage/foto_asesi/" . $asesi->dokumen_amdal : null,
                
                // Syarat Tambahan (Opsional)
                'cv' => $asesi->cv,
                'cv_url' => $asesi->cv ? "{$baseUrl}/storage/foto_asesi/" . $asesi->cv : null,
                
                'foto' => $asesi->foto,
                'foto_url' => $asesi->foto ? "{$baseUrl}/storage/foto_asesi/" . $asesi->foto : null,
                
                'ktp' => $asesi->ktp,
                'ktp_url' => $asesi->ktp ? "{$baseUrl}/storage/foto_asesi/" . $asesi->ktp : null,
                
                'sertifikat_kompetensi_lain' => $sertifikatKompetensiLain,
                'sertifikat_kompetensi_lain_url' => $sertifikatKompetensiLain ? "{$baseUrl}/storage/foto_asesi/" . $sertifikatKompetensiLain : null,
                
                'form_pendaftaran' => $asesi->form_pendaftaran,
                'form_pendaftaran_url' => $asesi->form_pendaftaran ? "{$baseUrl}/storage/foto_asesi/" . $asesi->form_pendaftaran : null,
                
                'sertifikat_atpa_ktpa' => $asesi->sertifikat_atpa_ktpa,
                'sertifikat_atpa_ktpa_url' => $asesi->sertifikat_atpa_ktpa ? "{$baseUrl}/storage/foto_asesi/" . $asesi->sertifikat_atpa_ktpa : null,
                'jenis_sertifikat' => $asesi->jenis_sertifikat,
                'no_sertifikat' => $asesi->no_sertifikat,
                'tgl_sertifikat' => $asesi->tgl_sertifikat ? $asesi->tgl_sertifikat->format('Y-m-d') : null,
                'masa_berlaku_sertifikat' => $asesi->masa_berlaku_sertifikat ? (\Carbon\Carbon::parse($asesi->masa_berlaku_sertifikat)->format('Y-m-d')) : null,
                'status_sertifikat' => $asesi->status_sertifikat ?: 'BELUM_UPLOAD',
                'file_sertifikat_aktif' => $asesi->file_sertifikat_aktif,
                'file_sertifikat_aktif_url' => $asesi->file_sertifikat_aktif ? "{$baseUrl}/storage/foto_asesi/" . $asesi->file_sertifikat_aktif : null,
                'catatan_sertifikat' => $asesi->catatan_sertifikat,
                'tgl_verifikasi_sertifikat' => $asesi->tgl_verifikasi_sertifikat,

                // Legacy keys
                'transkrip' => $asesi->transkrip,
                'transkrip_url' => $asesi->transkrip ? "{$baseUrl}/storage/foto_asesi/" . $asesi->transkrip : null,
                'suket' => $asesi->suket,
                'suket_url' => $asesi->suket ? "{$baseUrl}/storage/foto_asesi/" . $asesi->suket : null,
                'sertifikat' => $asesi->sertifikat,
                'sertifikat_url' => $asesi->sertifikat ? "{$baseUrl}/storage/foto_asesi/" . $asesi->sertifikat : null,
            ],
        ]);
    }

    /**
     * POST /api/v1/peserta/profil
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Sesi tidak valid.'], 401);
        }

        $asesi = Asesi::where('no_ktp', $user->no_ktp)
            ->orWhere('no_pendaftaran', $user->username)
            ->orWhere('no_pendaftaran', $user->no_induk)
            ->orWhere('nohp', $user->no_telp)
            ->first();

        $docKeys = [
            'foto',
            'ktp',
            'ijazah',
            'sertifikat_amdal',
            'bukti_keterlibatan',
            'dokumen_amdal',
            'cv',
            'sertifikat_kompetensi_lain',
            'form_pendaftaran',
            'sertifikat_atpa_ktpa',
            'transkrip',
            'suket',
            'sertifikat',
        ];

        $rules = [
            'nama' => 'required|string|max:100',
            'no_ktp' => 'required|string|max:30',
            'tmp_lahir' => 'nullable|string|max:100',
            'tgl_lahir' => 'nullable|date',
            'jenis_kelamin' => 'nullable|in:L,P',
            'pendidikan' => 'nullable|string|max:50',
            'keahlian_penyusun' => 'nullable',
            'email' => 'nullable|email|max:100',
            'nohp' => 'required|string|max:25',
            'alamat' => 'nullable|string',
            'RT' => 'nullable|string|max:10',
            'RW' => 'nullable|string|max:10',
            'kelurahan' => 'nullable|string|max:100',
            'kecamatan' => 'nullable|string|max:100',
            'kota' => 'nullable|string|max:100',
            'propinsi' => 'nullable|string|max:100',
            'kodepos' => 'nullable|string|max:10',
            'no_sertifikat' => 'nullable|string|max:100',
            'tgl_sertifikat' => 'nullable|date',
            'jenis_sertifikat' => 'nullable|string|in:ATPA,KTPA',
            'masa_berlaku_sertifikat' => 'nullable|date',
        ];

        foreach ($docKeys as $docKey) {
            $rules[$docKey] = 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx,zip,rar|max:10240';
        }

        $validator = Validator::make($request->all(), $rules);

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
            $noPendaftaran = $asesi ? $asesi->no_pendaftaran : ($user->no_induk ?: Asesi::generateNoPendaftaran());

            $data = $request->except($docKeys);
            $data['no_pendaftaran'] = $noPendaftaran;

            if ($request->filled('keahlian_penyusun')) {
                $formattedKeahlian = MasterKeahlian::recordMultipleIfNew($request->keahlian_penyusun);
                $data['keahlian_penyusun'] = $formattedKeahlian;
            }

            if (!$asesi) {
                $data['tgl_daftar'] = now()->toDateString();
                $data['angkatan'] = now()->year;
                $data['verifikasi'] = 'P';
                $data['blokir'] = 'N';
            }

            // Handle file uploads
            $verif = is_array($asesi?->verifikasi_dokumen)
                ? $asesi->verifikasi_dokumen
                : (is_string($asesi?->verifikasi_dokumen) ? (json_decode($asesi->verifikasi_dokumen, true) ?: []) : []);

            foreach ($docKeys as $fileKey) {
                if ($request->hasFile($fileKey)) {
                    $uploaded = $request->file($fileKey);
                    $savedPath = $uploaded->storeAs(
                        'foto_asesi',
                        $noPendaftaran . '_' . $fileKey . '.' . $uploaded->getClientOriginalExtension(),
                        'public'
                    );
                    $data[$fileKey] = basename($savedPath);

                    // Sync legacy fields
                    if ($fileKey === 'sertifikat_amdal') $data['sertifikat'] = $data[$fileKey];
                    if ($fileKey === 'bukti_keterlibatan') $data['suket'] = $data[$fileKey];
                    if ($fileKey === 'sertifikat_kompetensi_lain') $data['transkrip'] = $data[$fileKey];

                    // Reset verifikasi status ke terupload
                    $verif[$fileKey] = 'terupload';
                }
            }
            $data['verifikasi_dokumen'] = $verif;

            if ($asesi) {
                $asesi->update($data);
            } else {
                $asesi = Asesi::create($data);
            }

            // Mirror update to users table
            $user->update([
                'nama_lengkap'      => $asesi->nama,
                'no_ktp'            => $asesi->no_ktp,
                'no_telp'           => $asesi->nohp,
                'email'             => $asesi->email,
                'keahlian_penyusun' => $asesi->keahlian_penyusun,
                'tmp_lahir'         => $asesi->tmp_lahir,
                'tgl_lahir'         => $asesi->tgl_lahir,
                'alamat'            => $asesi->alamat,
                'foto'              => $asesi->foto ?: $user->foto,
            ]);

            DB::commit();

            return $this->show($request);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui profil: ' . $e->getMessage(),
            ], 500);
        }
    }
    /**
     * POST /api/v1/peserta/sertifikat-atpa-ktpa
     * Upload / update sertifikat aktif ATPA/KTPA dari profil peserta
     */
    public function uploadSertifikatAtpaKtpa(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $asesi = Asesi::where('no_ktp', $user->no_ktp)
            ->orWhere('no_pendaftaran', $user->username)
            ->orWhere('no_pendaftaran', $user->no_induk)
            ->orWhere('nohp', $user->no_telp)
            ->first();

        $fileUpload = $request->file('file_sertifikat_aktif') ?: ($request->file('sertifikat_atpa_ktpa') ?: $request->file('file'));
        $hasExistingFile = !empty($asesi?->file_sertifikat_aktif);

        if (!$fileUpload && !$hasExistingFile) {
            return response()->json([
                'success' => false,
                'message' => 'Berkas sertifikat aktif (PDF/Gambar) wajib diunggah.',
                'errors' => ['file_sertifikat_aktif' => ['Berkas sertifikat aktif (PDF/Gambar) wajib diunggah']],
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'jenis_sertifikat' => 'required|in:ATPA,KTPA',
            'no_sertifikat' => 'required|string|max:100',
            'tgl_sertifikat' => 'nullable|date',
            'masa_berlaku_sertifikat' => 'nullable|date',
        ], [
            'jenis_sertifikat.required' => 'Pilih jenis sertifikat (ATPA atau KTPA)',
            'no_sertifikat.required' => 'Nomor sertifikat wajib diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => collect($validator->errors()->all())->first() ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $noPendaftaran = $asesi ? $asesi->no_pendaftaran : ($user->no_induk ?: Asesi::generateNoPendaftaran());

        DB::beginTransaction();
        try {
            $data = [
                'jenis_sertifikat' => $request->jenis_sertifikat,
                'no_sertifikat' => $request->no_sertifikat,
                'tgl_sertifikat' => $request->tgl_sertifikat ?: now()->toDateString(),
                'masa_berlaku_sertifikat' => $request->masa_berlaku_sertifikat ?: now()->addYears(5)->toDateString(),
                'status_sertifikat' => 'MENUNGGU_VERIFIKASI',
                'catatan_sertifikat' => null,
            ];

            if ($fileUpload) {
                $ext = $fileUpload->getClientOriginalExtension();
                $filename = $noPendaftaran . '_sertifikat_aktif.' . $ext;
                $fileUpload->storeAs('foto_asesi', $filename, 'public');
                $data['file_sertifikat_aktif'] = $filename;
            }

            if ($asesi) {
                $asesi->update($data);
                $asesi->refresh();
            } else {
                $data['no_pendaftaran'] = $noPendaftaran;
                $data['nama'] = $user->nama_lengkap;
                $data['no_ktp'] = $user->no_ktp;
                $data['email'] = $user->email;
                $data['nohp'] = $user->no_telp;
                $data['tgl_daftar'] = now()->toDateString();
                $data['angkatan'] = now()->year;
                $data['verifikasi'] = 'P';
                $data['blokir'] = 'N';
                $asesi = Asesi::create($data);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sertifikat aktif ATPA/KTPA berhasil diunggah dan sedang menunggu verifikasi admin.',
                'data' => [
                    'file_sertifikat_aktif' => $asesi->file_sertifikat_aktif,
                    'file_sertifikat_aktif_url' => $asesi->file_sertifikat_aktif ? url('storage/foto_asesi/' . $asesi->file_sertifikat_aktif) : null,
                    'jenis_sertifikat' => $asesi->jenis_sertifikat,
                    'no_sertifikat' => $asesi->no_sertifikat,
                    'tgl_sertifikat' => $asesi->tgl_sertifikat ? $asesi->tgl_sertifikat->format('Y-m-d') : null,
                    'masa_berlaku_sertifikat' => $asesi->masa_berlaku_sertifikat ? (\Carbon\Carbon::parse($asesi->masa_berlaku_sertifikat)->format('Y-m-d')) : null,
                    'status_sertifikat' => $asesi->status_sertifikat,
                    'catatan_sertifikat' => $asesi->catatan_sertifikat,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengunggah sertifikat: ' . $e->getMessage(),
            ], 500);
        }
    }
}
