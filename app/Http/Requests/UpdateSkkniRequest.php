<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSkkniRequest extends FormRequest
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
        $id = $this->route('id');
        return [
            'no_skkni' => 'nullable|string|max:100|unique:skkni,no_skkni,' . $id,
            'nama' => 'nullable|string|max:255',
            'jenis_standar' => 'nullable|in:SKKNI,SKK,SI',
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
            'no_skkni.unique' => 'Nomor standar sudah ada',
            'jenis_standar.in' => 'Jenis standar tidak valid',
            'file.mimes' => 'File harus berupa PDF, DOC, atau DOCX',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('no_skkni')) {
            $this->merge([
                'no_skkni' => strip_tags(trim($this->no_skkni)),
            ]);
        }
        if ($this->has('nama')) {
            $this->merge([
                'nama' => strip_tags(trim($this->nama)),
            ]);
        }
    }
}
