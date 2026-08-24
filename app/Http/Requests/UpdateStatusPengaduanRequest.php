<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStatusPengaduanRequest extends FormRequest
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
            'status' => 'required|in:masuk,diproses,selesai,ditutup',
            'catatan' => 'nullable|string|max:500',
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
            'status.required' => 'Status wajib dipilih',
            'status.in' => 'Status tidak valid. Pilih: masuk, diproses, selesai, atau ditutup',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $pengaduan = $this->route('pengaduan');
            $newStatus = $this->status;

            // Validate status transition
            if ($pengaduan) {
                $currentStatus = strtolower($pengaduan->status);

                // Define allowed transitions
                $allowedTransitions = [
                    'masuk' => ['diproses', 'ditutup'],
                    'diproses' => ['selesai', 'ditutup'],
                    'selesai' => ['ditutup'],
                    'ditutup' => [], // Cannot change from ditutup
                ];

                if (!isset($allowedTransitions[$currentStatus]) ||
                    !in_array($newStatus, $allowedTransitions[$currentStatus])) {
                    $validator->errors()->add('status', 'Status tidak valid untuk transisi dari ' . ucfirst($currentStatus));
                }
            }
        });
    }
}
