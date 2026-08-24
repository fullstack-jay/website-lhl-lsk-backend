<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMutudocRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only authenticated admin users can create
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'jenis' => 'required|integer|exists:mutudoc_jenisdoc,id',
            'kategori' => 'required|integer|exists:mutudoc_kategoridoc,id',
            'judul' => 'required|string|max:255',
            'deskripsi' => 'required|string|max:5000',
            'tgl_terbit' => 'required|date|before_or_equal:today',
            'no_dokumen' => 'required|string|max:100',
            'no_revisi' => 'required|integer|min:0',
            'penyusun' => 'required|string|max:255',
            'pengesahan' => 'required|string|max:255',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,gif|max:10240', // Max 10MB
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'jenis.required' => 'Jenis dokumen wajib dipilih',
            'jenis.exists' => 'Jenis dokumen tidak valid',
            'kategori.required' => 'Kategori dokumen wajib dipilih',
            'kategori.exists' => 'Kategori dokumen tidak valid',
            'judul.required' => 'Judul dokumen wajib diisi',
            'deskripsi.required' => 'Deskripsi dokumen wajib diisi',
            'deskripsi.max' => 'Deskripsi maksimal 5000 karakter',
            'tgl_terbit.required' => 'Tanggal terbit wajib diisi',
            'tgl_terbit.before_or_equal' => 'Tanggal terbit tidak boleh lebih dari hari ini',
            'no_dokumen.required' => 'Nomor dokumen wajib diisi',
            'no_revisi.required' => 'Nomor revisi wajib diisi',
            'penyusun.required' => 'Nama penyusun wajib diisi',
            'pengesahan.required' => 'Nama pengesah wajib diisi',
            'file.mimes' => 'File harus berupa PDF atau gambar (JPG, PNG, GIF)',
            'file.max' => 'Ukuran file maksimal 10MB',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Sanitize input
        $this->merge([
            'judul' => strip_tags(trim($this->judul)),
            'deskripsi' => strip_tags($this->deskripsi),
            'no_dokumen' => strip_tags(trim($this->no_dokumen)),
        ]);
    }
}
