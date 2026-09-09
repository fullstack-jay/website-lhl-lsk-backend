<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePenilaianAsesiRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role penguji diverifikasi di controller (ownership jadwal)
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'nilai_vp' => 'required|numeric|min:0|max:100',
            'nilai_pt' => 'required|numeric|min:0|max:100',
            'nilai_dpsk' => 'required|numeric|min:0|max:100',
            'nilai_pw' => 'required|numeric|min:0|max:100',
            'catatan' => 'nullable|string|max:3000',
            'rubrik_detail' => 'nullable|array',
            'rubrik_detail.durasi_pengalaman' => 'nullable|numeric',
            'rubrik_detail.posisi_jabatan' => 'nullable|numeric',
            'rubrik_detail.pengalaman_proyek' => 'nullable|numeric',
            'rubrik_detail.pendidikan' => 'nullable|numeric',
            'rubrik_detail.sertifikasi_pelatihan' => 'nullable|numeric',
        ];
    }

    public function messages(): array
    {
        return [
            'nilai_vp.required' => 'Nilai Verifikasi Portofolio (VP) wajib diisi.',
            'nilai_vp.min' => 'Nilai VP minimal 0.',
            'nilai_vp.max' => 'Nilai VP maksimal 100.',
            'nilai_pt.required' => 'Nilai Pertanyaan Tertulis (PT) wajib diisi.',
            'nilai_dpsk.required' => 'Nilai Daftar Pertanyaan Studi Kasus (DPSK) wajib diisi.',
            'nilai_pw.required' => 'Nilai Pertanyaan Wawancara (PW) wajib diisi.',
        ];
    }
}
