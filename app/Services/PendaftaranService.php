<?php

namespace App\Services;

use App\Models\Pendaftaran;
use App\Models\User;
use App\Repositories\PendaftaranRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
