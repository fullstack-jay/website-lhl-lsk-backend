<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePengaduanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Public endpoint
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'nama' => 'required|string|min:3|max:100',
            'email' => 'nullable|email|max:100',
            'no_hp' => 'nullable|string|min:10|max:20|regex:/^[0-9\+\-\s]+$/',
            'jenis_responden' => 'required|string|in:peserta,penguji,masyarakat',
            'aduan' => 'required|string|min:10|max:5000',
            'lampiran' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048', // Max 2MB
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama wajib diisi',
            'nama.min' => 'Nama minimal 3 karakter',
            'email.email' => 'Format email tidak valid',
            'no_hp.regex' => 'Format nomor HP tidak valid',
            'jenis_responden.required' => 'Jenis responden wajib dipilih',
            'jenis_responden.in' => 'Jenis responden tidak valid',
            'aduan.required' => 'Isi pengaduan wajib diisi',
            'aduan.min' => 'Isi pengaduan minimal 10 karakter',
            'aduan.max' => 'Isi pengaduan maksimal 5000 karakter',
            'lampiran.mimes' => 'File lampiran harus berupa PDF, JPG, atau PNG',
            'lampiran.max' => 'Ukuran file lampiran maksimal 2MB',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Sanitize input
        $this->merge([
            'nama' => strip_tags(trim($this->nama)),
            'aduan' => strip_tags($this->aduan),
        ]);
    }
}
