<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMutudocRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'jenis' => 'nullable|integer|exists:mutudoc_jenisdoc,id',
            'kategori' => 'nullable|integer|exists:mutudoc_kategoridoc,id',
            'judul' => 'nullable|string|max:255',
            'deskripsi' => 'nullable|string|max:5000',
            'tgl_terbit' => 'nullable|date|before_or_equal:today',
            'no_dokumen' => 'nullable|string|max:100',
            'no_revisi' => 'nullable|integer|min:0',
            'penyusun' => 'nullable|string|max:255',
            'pengesahan' => 'nullable|string|max:255',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,gif|max:10240',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'jenis.exists' => 'Jenis dokumen tidak valid',
            'kategori.exists' => 'Kategori dokumen tidak valid',
            'deskripsi.max' => 'Deskripsi maksimal 5000 karakter',
        ];
    }
}
