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
            $data['password'] = Hash::make($randomPassword);
            // Set status DIVERIFIKASI karena data langsung diterima tanpa verifikasi email
            $data['status'] = 'DIVERIFIKASI';

            // Create pendaftaran
            $pendaftaran = $this->repository->create($data);

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
            ];

            $user = User::create($userData);

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
        return $this->repository->delete($id);
    }

    /**
     * Get pendaftarans by status
     */
    public function getByStatus(string $status): \Illuminate\Database\Eloquent\Collection
    {
        return $this->repository->getByStatus($status);
    }
}
