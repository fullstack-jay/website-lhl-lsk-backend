<?php

namespace App\Repositories;

use App\Models\Pendaftaran;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class PendaftaranRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'nama',
        'email',
        'no_pendaftaran',
    ];

    /**
     * Get searchable fields array
     */
    public function getFieldsSearchable(): array
    {
        return $this->fieldSearchable;
    }

    /**
     * Configure the Model
     */
    public function model(): string
    {
        return Pendaftaran::class;
    }

    /**
     * Create pendaftaran with generated no_pendaftaran and password
     */
    public function createWithCredentials(array $input): Model
    {
        // Generate nomor pendaftaran
        $input['no_pendaftaran'] = Pendaftaran::generateNoPendaftaran();

        // Generate random password
        $randomPassword = Pendaftaran::generateRandomPassword();
        $input['password'] = Hash::make($randomPassword);

        // Set default status
        $input['status'] = 'PENDING';

        $model = $this->model->newInstance($input);
        $model->save();

        // Attach plain password to model for response (temporary)
        $model->plain_password = $randomPassword;

        return $model;
    }

    /**
     * Find pendaftaran by no_pendaftaran
     */
    public function findByNoPendaftaran(string $noPendaftaran): ?Model
    {
        return $this->model->where('no_pendaftaran', $noPendaftaran)->first();
    }

    /**
     * Get pendaftarans by status
     */
    public function getByStatus(string $status): Collection
    {
        return $this->model->where('status', $status)->get();
    }

    /**
     * Search pendaftarans
     */
    public function search(string $keyword): Builder
    {
        return $this->model->where(function ($query) use ($keyword) {
            $query->where('nama', 'like', "%{$keyword}%")
                  ->orWhere('no_pendaftaran', 'like', "%{$keyword}%")
                  ->orWhere('email', 'like', "%{$keyword}%");
        });
    }

    /**
     * Update status pendaftaran
     */
    public function updateStatus(int $id, string $status, ?string $catatan = null, ?int $verifiedBy = null): Model
    {
        $model = $this->model->findOrFail($id);

        $model->update([
            'status' => $status,
            'catatan' => $catatan,
            'tanggal_verifikasi' => now(),
            'verified_by' => $verifiedBy,
        ]);

        return $model;
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        return [
            'total' => $this->model->count(),
            'pending' => $this->model->where('status', 'PENDING')->count(),
            'diverifikasi' => $this->model->where('status', 'DIVERIFIKASI')->count(),
            'disetujui' => $this->model->where('status', 'DISETUJUI')->count(),
            'ditolak' => $this->model->where('status', 'DITOLAK')->count(),
        ];
    }
}
