<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSkkniRequest extends FormRequest
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
            'no_skkni' => 'required|string|max:100|unique:skkni,no_skkni',
            'nama' => 'required|string|max:255',
            'jenis_standar' => 'required|in:SKKNI,SKK,SI',
            'sektor' => 'nullable|string|max:100',
            'subsektor' => 'nullable|string|max:100',
            'legalitas' => 'nullable|string|max:255',
            'penyusun' => 'nullable|string|max:255',
            'file' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'no_skkni.required' => 'Nomor standar wajib diisi',
            'no_skkni.unique' => 'Nomor standar sudah ada',
            'nama.required' => 'Nama standar wajib diisi',
            'jenis_standar.required' => 'Jenis standar wajib dipilih',
            'jenis_standar.in' => 'Jenis standar tidak valid',
            'file.mimes' => 'File harus berupa PDF, DOC, atau DOCX',
            'file.max' => 'Ukuran file maksimal 10MB',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'no_skkni' => strip_tags(trim($this->no_skkni)),
            'nama' => strip_tags(trim($this->nama)),
        ]);
    }
}
