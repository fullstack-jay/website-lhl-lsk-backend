<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResponPengaduanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Must be authenticated admin
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'respon_admin' => 'required|string|min:5|max:5000',
            'catatan_internal' => 'nullable|string|max:1000',
            'status' => 'required|in:masuk,diproses,selesai,ditutup',
            'kirim_notifikasi' => 'nullable|boolean',
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
            'respon_admin.required' => 'Respon admin wajib diisi',
            'respon_admin.min' => 'Respon admin minimal 5 karakter',
            'respon_admin.max' => 'Respon admin maksimal 5000 karakter',
            'status.required' => 'Status wajib dipilih',
            'status.in' => 'Status tidak valid',
        ];
    }
}
