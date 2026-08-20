<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PendaftaranResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'no_pendaftaran' => $this->no_pendaftaran,
            'nama' => $this->nama,
            'email' => $this->email,
            'no_hp' => $this->no_hp,
            'kebangsaan' => $this->kebangsaan,
            'kualifikasi_pendidikan' => $this->kualifikasi_pendidikan,
            'bidang_keahlian' => $this->bidang_keahlian,
            'wil_ujikom' => $this->wil_ujikom,
            'nama_institusi' => $this->nama_institusi,
            'jabatan' => $this->jabatan,
            'status' => $this->status,
            'catatan' => $this->catatan,
            'tanggal_verifikasi' => $this->tanggal_verifikasi?->format('d M Y H:i'),
            'created_at' => $this->created_at?->format('d M Y H:i'),
        ];
    }
}
