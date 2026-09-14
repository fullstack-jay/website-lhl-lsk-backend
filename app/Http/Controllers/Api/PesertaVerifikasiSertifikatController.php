<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PesertaVerifikasiSertifikatController extends Controller
{
    /**
     * GET /api/v1/admin/peserta-verifikasi-sertifikat
     * Mengambil daftar peserta yang mengajukan sertifikat aktif ATPA/KTPA untuk diverifikasi
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $search = $request->query('search');
        $jenis = $request->query('jenis');

        $query = Asesi::query();

        // Hanya tampilkan peserta yang memang mengajukan sertifikat aktif atau status bukan BELUM_UPLOAD
        if (empty($status) || $status === 'ALL') {
            $query->where(function ($q) {
                $q->whereNotNull('file_sertifikat_aktif')->where('file_sertifikat_aktif', '!=', '')
                  ->orWhere('status_sertifikat', '!=', 'BELUM_UPLOAD');
            });
        } else {
            $query->where('status_sertifikat', $status);
        }

        // Filter jenis
        if (!empty($jenis) && $jenis !== 'ALL') {
            $query->where('jenis_sertifikat', $jenis);
        }

        // Search
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('no_pendaftaran', 'like', "%{$search}%")
                  ->orWhere('no_ktp', 'like', "%{$search}%")
                  ->orWhere('no_sertifikat', 'like', "%{$search}%");
            });
        }

        // Hitung statistik
        $totalPengajuan = Asesi::where(function ($q) {
            $q->whereNotNull('file_sertifikat_aktif')->where('file_sertifikat_aktif', '!=', '')
              ->orWhere('status_sertifikat', '!=', 'BELUM_UPLOAD');
        })->count();

        $menungguCount = Asesi::where('status_sertifikat', 'MENUNGGU_VERIFIKASI')->count();
        $validCount = Asesi::where('status_sertifikat', 'VALID')->count();
        $tidakValidCount = Asesi::where('status_sertifikat', 'TIDAK_VALID')->count();

        $items = $query->orderByRaw("
            CASE 
                WHEN status_sertifikat = 'MENUNGGU_VERIFIKASI' THEN 1 
                WHEN status_sertifikat = 'TIDAK_VALID' THEN 2 
                WHEN status_sertifikat = 'VALID' THEN 3 
                ELSE 4 
            END ASC
        ")->orderBy('id', 'desc')
          ->paginate($request->query('per_page', 15));

        $baseUrl = url('/');

        $transformed = $items->getCollection()->map(function ($asesi) use ($baseUrl) {
            $fileAktif = $asesi->file_sertifikat_aktif;
            return [
                'id' => $asesi->id,
                'no_pendaftaran' => $asesi->no_pendaftaran,
                'nama' => $asesi->nama,
                'no_ktp' => $asesi->no_ktp,
                'nohp' => $asesi->nohp,
                'email' => $asesi->email,
                'jenis_sertifikat' => $asesi->jenis_sertifikat ?: 'ATPA',
                'no_sertifikat' => $asesi->no_sertifikat,
                'tgl_sertifikat' => $asesi->tgl_sertifikat ? $asesi->tgl_sertifikat->format('Y-m-d') : null,
                'masa_berlaku_sertifikat' => $asesi->masa_berlaku_sertifikat ? (\Carbon\Carbon::parse($asesi->masa_berlaku_sertifikat)->format('Y-m-d')) : null,
                'file_sertifikat_aktif' => $fileAktif,
                'sertifikat_url' => $fileAktif ? "{$baseUrl}/storage/foto_asesi/" . $fileAktif : null,
                'status_sertifikat' => $asesi->status_sertifikat ?: 'BELUM_UPLOAD',
                'catatan_sertifikat' => $asesi->catatan_sertifikat,
                'tgl_verifikasi_sertifikat' => $asesi->tgl_verifikasi_sertifikat ? \Carbon\Carbon::parse($asesi->tgl_verifikasi_sertifikat)->toIso8601String() : null,
                'verified_by_sertifikat' => $asesi->verified_by_sertifikat,
                'waktu' => $asesi->waktu ? \Carbon\Carbon::parse($asesi->waktu)->toIso8601String() : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $transformed,
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
            'statistics' => [
                'total' => $totalPengajuan,
                'menunggu_verifikasi' => $menungguCount,
                'valid' => $validCount,
                'tidak_valid' => $tidakValidCount,
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/peserta-verifikasi-sertifikat/{id}/verifikasi
     * Admin melakukan verifikasi sertifikat ATPA/KTPA peserta (Valid / Tidak Valid)
     */
    public function verifikasi(Request $request, $id): JsonResponse
    {
        $asesi = Asesi::find($id);
        if (!$asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Data peserta tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:VALID,TIDAK_VALID',
            'catatan' => $request->status === 'TIDAK_VALID' ? 'required|string|max:500' : 'nullable|string|max:500',
        ], [
            'status.required' => 'Pilih status verifikasi (Valid atau Tidak Valid)',
            'catatan.required' => 'Catatan/alasan wajib diisi jika status sertifikat Tidak Valid',
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
            $user = auth()->user();
            $adminName = $user ? ($user->nama_lengkap ?: $user->username) : 'Administrator LSK';

            $asesi->status_sertifikat = $request->status;
            $asesi->catatan_sertifikat = $request->catatan;
            $asesi->tgl_verifikasi_sertifikat = now();
            $asesi->verified_by_sertifikat = $adminName;
            $asesi->save();

            // Jika status VALID: otomatis inisialisasi tabel pemeliharaan & evaluasi jika belum ada
            if ($request->status === 'VALID') {
                $currentYear = (int) now()->year;
                $sertifikatNo = $asesi->no_sertifikat ?: ('SERT/' . $currentYear . '/' . ($asesi->jenis_sertifikat ?: 'ATPA') . '/' . substr($asesi->no_pendaftaran, -4));

                // 1. Inisialisasi asesi_pemeliharaan untuk tahun berjalan
                if (DB::getSchemaBuilder()->hasTable('asesi_pemeliharaan')) {
                    $existsPemeliharaan = DB::table('asesi_pemeliharaan')
                        ->where('id_asesi', $asesi->no_pendaftaran)
                        ->where('tahun', $currentYear)
                        ->exists();

                    if (!$existsPemeliharaan) {
                        DB::table('asesi_pemeliharaan')->insert([
                            'id_asesi' => $asesi->no_pendaftaran,
                            'sertifikat_no' => $sertifikatNo,
                            'tahun' => $currentYear,
                            'status' => 'BELUM',
                            'waktu' => now(),
                        ]);
                    }
                }

                // 2. Inisialisasi asesi_evaluasi untuk tahun ke-3
                if (DB::getSchemaBuilder()->hasTable('asesi_evaluasi')) {
                    $existsEvaluasi = DB::table('asesi_evaluasi')
                        ->where('id_asesi', $asesi->no_pendaftaran)
                        ->where('tahun_ke', 3)
                        ->exists();

                    if (!$existsEvaluasi) {
                        DB::table('asesi_evaluasi')->insert([
                            'id_asesi' => $asesi->no_pendaftaran,
                            'sertifikat_no' => $sertifikatNo,
                            'tahun_ke' => 3,
                            'tahun_jatuh_tempo' => $currentYear + 3,
                            'status' => 'BELUM_WAKTUNYA',
                            'waktu' => now(),
                        ]);
                    }
                }

                // 3. Tambahkan notifikasi ucapan selamat & pengingat kewajiban
                if (DB::getSchemaBuilder()->hasTable('asesi_notifikasi')) {
                    DB::table('asesi_notifikasi')->insert([
                        'id_asesi' => $asesi->no_pendaftaran,
                        'tipe' => 'success',
                        'judul' => 'Sertifikat ATPA/KTPA Anda Terverifikasi VALID',
                        'pesan' => "Selamat! Sertifikat aktif {$asesi->jenis_sertifikat} Anda (No: {$sertifikatNo}) telah dinyatakan VALID oleh Admin. Menu Kewajiban Saya kini telah terbuka dan dapat Anda isi.",
                        'kategori' => 'sertifikat',
                        'dibaca' => 0,
                        'waktu' => now(),
                    ]);
                }
            } else {
                // Status TIDAK_VALID: kirim notifikasi penolakan
                if (DB::getSchemaBuilder()->hasTable('asesi_notifikasi')) {
                    $alasan = $request->catatan ? ": \"{$request->catatan}\"" : "";
                    DB::table('asesi_notifikasi')->insert([
                        'id_asesi' => $asesi->no_pendaftaran,
                        'tipe' => 'error',
                        'judul' => 'Verifikasi Sertifikat ATPA/KTPA Ditolak',
                        'pesan' => "Dokumen sertifikat ATPA/KTPA yang Anda unggah dinyatakan Tidak Valid oleh Admin{$alasan}. Silakan unggah dokumen sertifikat yang valid melalui Menu Profil Anda.",
                        'kategori' => 'sertifikat',
                        'dibaca' => 0,
                        'waktu' => now(),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $request->status === 'VALID' 
                    ? 'Sertifikat peserta berhasil diverifikasi VALID. Menu Kewajiban Saya untuk peserta ini telah otomatis aktif.' 
                    : 'Sertifikat peserta berhasil dinyatakan TIDAK VALID. Catatan perbaikan telah dikirimkan ke peserta.',
                'data' => [
                    'id' => $asesi->id,
                    'no_pendaftaran' => $asesi->no_pendaftaran,
                    'nama' => $asesi->nama,
                    'status_sertifikat' => $asesi->status_sertifikat,
                    'catatan_sertifikat' => $asesi->catatan_sertifikat,
                    'tgl_verifikasi_sertifikat' => $asesi->tgl_verifikasi_sertifikat,
                    'verified_by_sertifikat' => $asesi->verified_by_sertifikat,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses verifikasi: ' . $e->getMessage(),
            ], 500);
        }
    }
}
