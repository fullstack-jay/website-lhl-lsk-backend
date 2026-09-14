<?php

namespace App\Services;

use App\Models\Pendaftaran;
use App\Models\User;
use App\Repositories\PendaftaranRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PendaftaranService
{
    protected PendaftaranRepository $repository;

    public function __construct(PendaftaranRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Create new pendaftaran with auto-generated credentials and user account
     */
    public function register(array $data): array
    {
        try {
            DB::beginTransaction();

            // Generate nomor pendaftaran dan password
            $noPendaftaran = Pendaftaran::generateNoPendaftaran();
            $randomPassword = Pendaftaran::generateRandomPassword();

            // Prepare data pendaftaran
            $data['no_pendaftaran'] = $noPendaftaran;

            // Handle file upload sertifikat ATPA/KTPA jika disertakan
            $hasCert = !empty($data['has_active_certificate']) && in_array($data['has_active_certificate'], [1, '1', true, 'true'], true);
            $certFile = null;
            if (request()->hasFile('file_sertifikat')) {
                $fileSert = request()->file('file_sertifikat');
                $ext = $fileSert->getClientOriginalExtension();
                $filename = $noPendaftaran . '_sertifikat_aktif.' . $ext;
                $fileSert->storeAs('foto_asesi', $filename, 'public');
                $certFile = $filename;
                $data['file_sertifikat'] = $filename;
                $hasCert = true;
            }

            if ($hasCert || $certFile) {
                $data['has_active_certificate'] = true;
                $data['status_sertifikat'] = 'MENUNGGU_VERIFIKASI';
            }
            $data['password'] = Hash::make($randomPassword);
            // Set status DIVERIFIKASI karena data langsung diterima tanpa verifikasi email
            $data['status'] = 'DIVERIFIKASI';

            // Create pendaftaran
            $pendaftaran = $this->repository->create($data);

            // Format dan catat master keahlian jika ada
            $formattedKeahlian = null;
            if (!empty($data['bidang_keahlian'])) {
                $formattedKeahlian = \App\Models\MasterKeahlian::recordMultipleIfNew($data['bidang_keahlian']);
                if ($formattedKeahlian) {
                    $data['bidang_keahlian'] = $formattedKeahlian;
                }
            }

            // Ekstrak RT & RW dari field terpisah atau string alamat
            $rt = $data['rt'] ?? ($data['RT'] ?? null);
            $rw = $data['rw'] ?? ($data['RW'] ?? null);
            if ((empty($rt) || empty($rw)) && !empty($data['alamat'])) {
                if (preg_match('/RT\s*[:\.]?\s*(\d{1,3})\s*(?:[\/,\-&]|dan|\s+)\s*RW\s*[:\.]?\s*(\d{1,3})/i', $data['alamat'], $m)) {
                    if (empty($rt)) $rt = str_pad($m[1], 3, '0', STR_PAD_LEFT);
                    if (empty($rw)) $rw = str_pad($m[2], 3, '0', STR_PAD_LEFT);
                } elseif (preg_match('/RT\s*[:\.]?\s*(\d{1,3})\s*[\/]\s*(\d{1,3})/i', $data['alamat'], $m)) {
                    if (empty($rt)) $rt = str_pad($m[1], 3, '0', STR_PAD_LEFT);
                    if (empty($rw)) $rw = str_pad($m[2], 3, '0', STR_PAD_LEFT);
                } else {
                    if (empty($rt) && preg_match('/RT\s*[:\.]?\s*(\d{1,3})/i', $data['alamat'], $m)) {
                        $rt = str_pad($m[1], 3, '0', STR_PAD_LEFT);
                    }
                    if (empty($rw) && preg_match('/RW\s*[:\.]?\s*(\d{1,3})/i', $data['alamat'], $m)) {
                        $rw = str_pad($m[2], 3, '0', STR_PAD_LEFT);
                    }
                }
            }

            // Create user account untuk peserta (gunakan no_ktp sebagai username)
            $userData = [
                'username' => $data['no_ktp'], // Gunakan no_ktp sebagai username
                'password' => $data['password'], // Password yang sama
                'nama_lengkap' => $data['nama'],
                'email' => $data['email'],
                'no_telp' => $data['no_hp'],
                'no_ktp' => $data['no_ktp'],
                'level' => 'user', // Role PESERTA
                'blokir' => 'N', // Active
                'keahlian_penyusun' => $formattedKeahlian ?: ($data['bidang_keahlian'] ?? null),
                'pendidikan_terakhir' => $data['kualifikasi_pendidikan'] ?? null,
                'alamat' => $data['alamat'] ?? null,
                'RT' => $rt,
                'RW' => $rw,
                'propinsi' => $data['propinsi'] ?? null,
                'kota' => $data['kota'] ?? null,
                'kecamatan' => $data['kecamatan'] ?? null,
                'kelurahan' => $data['kelurahan'] ?? null,
            ];

            $user = User::create($userData);

            // Create or sync asesi record
            \App\Models\Asesi::updateOrCreate(
                ['no_pendaftaran' => $noPendaftaran],
                [
                    'no_ktp' => $data['no_ktp'],
                    'nama' => $data['nama'],
                    'email' => $data['email'],
                    'nohp' => $data['no_hp'],
                    'kebangsaan' => $data['kebangsaan'] ?? 'Indonesia',
                    'pendidikan' => $data['kualifikasi_pendidikan'] ?? null,
                    'keahlian_penyusun' => $formattedKeahlian ?: ($data['bidang_keahlian'] ?? null),
                    'alamat' => $data['alamat'] ?? null,
                    'RT' => $rt,
                    'RW' => $rw,
                    'propinsi' => $data['propinsi'] ?? null,
                    'kota' => $data['kota'] ?? null,
                    'kecamatan' => $data['kecamatan'] ?? null,
                    'kelurahan' => $data['kelurahan'] ?? null,
                    'kodepos' => $data['kode_pos'] ?? null,
                    'wil_ujikom' => $data['wil_ujikom'] ?? null,
                    'nama_kantor' => $data['nama_institusi'] ?? null,
                    'jabatan' => $data['jabatan'] ?? null,
                    'alamat_kantor' => $data['alamat_kantor'] ?? null,
                    'telp_kantor' => $data['no_telp_kantor'] ?? null,
                    'fax_kantor' => $data['no_fax_kantor'] ?? null,
                    'email_kantor' => $data['email_kantor'] ?? null,
                    'tgl_daftar' => now()->toDateString(),
                    'angkatan' => now()->year,
                    'blokir' => 'N',
                    'verifikasi' => 'P',
                    'file_sertifikat_aktif' => $certFile,
                    'jenis_sertifikat' => $data['jenis_sertifikat'] ?? ($hasCert ? 'ATPA' : null),
                    'no_sertifikat' => $data['no_sertifikat'] ?? null,
                    'tgl_sertifikat' => $data['tgl_sertifikat'] ?? null,
                    'masa_berlaku_sertifikat' => $data['masa_berlaku_sertifikat'] ?? null,
                    'status_sertifikat' => ($hasCert || $certFile) ? 'MENUNGGU_VERIFIKASI' : 'BELUM_UPLOAD',
                ]
            );

            DB::commit();

            // =========================================================================
            // KIRIM EMAIL KREDENSIAL (NOMOR PENDAFTARAN & KATA SANDI) KE PESERTA
            // =========================================================================
            try {
                if (!empty($pendaftaran->email)) {
                    $loginUrl = env('FRONTEND_URL', 'http://localhost:3000') . '/login/peserta';

                    Mail::html("
                        <div style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px; background-color: #ffffff;'>
                            <div style='background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%); color: white; padding: 25px; border-radius: 8px 8px 0 0; text-align: center;'>
                                <h2 style='margin: 0; font-size: 22px; font-weight: 700;'>Pendaftaran Akun Peserta Berhasil</h2>
                                <p style='margin: 8px 0 0 0; opacity: 0.9; font-size: 13px;'>LSK Lingkungan Hidup Lestari</p>
                            </div>
                            <div style='padding: 25px;'>
                                <p>Halo <strong>{$pendaftaran->nama}</strong>,</p>
                                <p>Selamat! Pendaftaran akun peserta Anda telah berhasil diproses. Berikut adalah rincian data akun dan kata sandi Anda untuk masuk ke sistem:</p>

                                <div style='background-color: #f8fafc; border-left: 4px solid #0d9488; padding: 16px; margin: 20px 0; border-radius: 6px;'>
                                    <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                                        <tr>
                                            <td style='padding: 6px 0; color: #64748b; width: 40%;'>Nomor Pendaftaran</td>
                                            <td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>: {$noPendaftaran}</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 6px 0; color: #64748b;'>Nomor KTP (NIK)</td>
                                            <td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>: {$pendaftaran->no_ktp}</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 6px 0; color: #64748b;'>Kata Sandi (Password)</td>
                                            <td style='padding: 6px 0; font-weight: bold; color: #b45309; font-size: 16px;'>: {$randomPassword}</td>
                                        </tr>
                                    </table>
                                </div>
                                <p>Silakan gunakan <strong>Nomor Pendaftaran</strong> (atau Nomor KTP) dan <strong>Kata Sandi</strong> di atas untuk login ke portal peserta:</p>
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='{$loginUrl}' style='background-color: #0d9488; color: white; padding: 12px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>Masuk ke Portal Peserta</a>
                                </div>
                                <p style='color: #64748b; font-size: 12px; margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 12px;'>
                                    * Harap simpan informasi kata sandi ini dengan baik dan jangan bagikan kepada siapa pun.
                                </p>
                            </div>
                        </div>
                    ", function ($message) use ($pendaftaran) {
                        $message->to($pendaftaran->email, $pendaftaran->nama)
                                ->subject('Informasi Akun & Kata Sandi Pendaftaran - LSK LHL');
                    });
                }
            } catch (\Exception $mailEx) {
                Log::warning('Gagal mengirim email kredensial ke ' . $pendaftaran->email . ': ' . $mailEx->getMessage());
            }
            // =========================================================================

            return [
                'pendaftaran' => $pendaftaran,
                'user' => $user,
                'plain_password' => $randomPassword,
                'no_pendaftaran' => $noPendaftaran,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Find pendaftaran by nomor pendaftaran
     */
    public function findByNoPendaftaran(string $noPendaftaran): ?Pendaftaran
    {
        return $this->repository->findByNoPendaftaran($noPendaftaran);
    }

    /**
     * Get pendaftarans with filters
     */
    public function getPendaftarans(array $filters = [], int $perPage = 15): array
    {
        $query = $this->repository->model->query();

        // Filter by status
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Search
        if (!empty($filters['search'])) {
            $query = $this->repository->search($filters['search']);
        }

        $pendaftaran = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return [
            'data' => $pendaftaran->items(),
            'pagination' => [
                'total' => $pendaftaran->total(),
                'per_page' => $pendaftaran->perPage(),
                'current_page' => $pendaftaran->currentPage(),
                'last_page' => $pendaftaran->lastPage(),
            ],
        ];
    }

    /**
     * Update status pendaftaran
     */
    public function updateStatus(int $id, string $status, ?string $catatan = null, ?int $verifiedBy = null): Pendaftaran
    {
        return $this->repository->updateStatus($id, $status, $catatan, $verifiedBy);
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    /**
     * Delete pendaftaran
     */
    public function delete(int $id): bool
    {
        $pendaftaran = Pendaftaran::withTrashed()->find($id);
        if (!$pendaftaran) {
            return false;
        }

        $noPendaftaran = $pendaftaran->no_pendaftaran;
        $noKtp = $pendaftaran->no_ktp;
        $email = $pendaftaran->email;
        $noHp = $pendaftaran->no_hp;

        // Force delete dari tabel pendaftarans agar bersih total
        $pendaftaran->forceDelete();

        // Hapus akun user
        User::where(function ($q) use ($noPendaftaran, $noKtp, $email, $noHp) {
            $q->where('username', $noPendaftaran)
              ->orWhere('username', $noKtp)
              ->orWhere('no_ktp', $noKtp);
            if (!empty($email)) $q->orWhere('email', $email);
            if (!empty($noHp)) $q->orWhere('no_telp', $noHp);
        })->delete();

        // Hapus asesi jika ada
        \App\Models\Asesi::where('no_pendaftaran', $noPendaftaran)
            ->orWhere('no_ktp', $noKtp)
            ->delete();

        return true;
    }

    /**
     * Get pendaftarans by status
     */
    public function getByStatus(string $status): \Illuminate\Database\Eloquent\Collection
    {
        return $this->repository->getByStatus($status);
    }
}
