<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePendaftaranRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            // Informasi Pribadi
            'nama' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:pendaftarans,email',
            'no_hp' => 'required|string|max:14|regex:/^[0-9]+$/',
            'no_ktp' => 'required|string|size:16|regex:/^[0-9]+$/|unique:pendaftarans,no_ktp',
            'kebangsaan' => 'required|string|max:100',
            'kualifikasi_pendidikan' => 'required|in:D4,S1,S2,S3',
            'bidang_keahlian' => 'required|string|max:255',

            // Alamat
            'alamat' => 'required|string',
            'propinsi' => 'nullable|string|max:100',
            'kota' => 'nullable|string|max:100',
            'kecamatan' => 'nullable|string|max:100',
            'kelurahan' => 'nullable|string|max:100',

            // Lokasi Uji Kompetensi
            'wil_ujikom' => 'required|string|max:100',

            // Data Pekerjaan
            'nama_institusi' => 'required|string|max:255',
            'jabatan' => 'required|string|max:255',
            'alamat_kantor' => 'required|string',
            'kode_pos' => 'required|string|max:5|regex:/^[0-9]+$/',
            'no_telp_kantor' => 'nullable|string|max:20',
            'no_fax_kantor' => 'nullable|string|max:20',
            'email_kantor' => 'nullable|email|max:255',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi',
            'email' => 'Format :attribute tidak valid',
            'unique' => ':attribute sudah terdaftar',
            'regex' => 'Format :attribute tidak valid',
            'in' => ':attribute harus salah satu dari: :values',
            'size' => ':attribute harus :size karakter',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nama' => 'Nama Lengkap',
            'email' => 'Email',
            'no_hp' => 'Nomor HP',
            'no_ktp' => 'Nomor KTP',
            'kebangsaan' => 'Kebangsaan',
            'kualifikasi_pendidikan' => 'Kualifikasi Pendidikan',
            'bidang_keahlian' => 'Bidang Keahlian',
            'alamat' => 'Alamat',
            'propinsi' => 'Provinsi',
            'kota' => 'Kota/Kabupaten',
            'kecamatan' => 'Kecamatan',
            'kelurahan' => 'Kelurahan',
            'wil_ujikom' => 'Lokasi Uji Kompetensi',
            'nama_institusi' => 'Nama Institusi',
            'jabatan' => 'Jabatan',
            'alamat_kantor' => 'Alamat Kantor',
            'kode_pos' => 'Kode Pos',
            'no_telp_kantor' => 'Nomor Telepon Kantor',
            'no_fax_kantor' => 'Nomor Fax Kantor',
            'email_kantor' => 'Email Kantor',
        ];
    }
}
