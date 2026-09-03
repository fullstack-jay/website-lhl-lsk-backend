<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $items = [
            [
                'shortcode' => 'ijazah',
                'persyaratan' => 'Scan Ijazah (Minimal S1/D4)',
                'aktif' => 'Y',
                'wajib' => 'Y',
            ],
            [
                'shortcode' => 'sertifikat_amdal',
                'persyaratan' => 'Sertifikat Pelatihan AMDAL',
                'aktif' => 'Y',
                'wajib' => 'Y',
            ],
            [
                'shortcode' => 'bukti_keterlibatan',
                'persyaratan' => 'Bukti Keterlibatan AMDAL',
                'aktif' => 'Y',
                'wajib' => 'Y',
            ],
            [
                'shortcode' => 'dokumen_amdal',
                'persyaratan' => 'Salinan Dokumen AMDAL',
                'aktif' => 'Y',
                'wajib' => 'Y',
            ],
            [
                'shortcode' => 'cv',
                'persyaratan' => 'Curriculum Vitae (CV)',
                'aktif' => 'Y',
                'wajib' => 'N',
            ],
            [
                'shortcode' => 'foto',
                'persyaratan' => 'Pas Foto (3x4)',
                'aktif' => 'Y',
                'wajib' => 'N',
            ],
            [
                'shortcode' => 'ktp',
                'persyaratan' => 'Scan KTP',
                'aktif' => 'Y',
                'wajib' => 'N',
            ],
            [
                'shortcode' => 'sertifikat_kompetensi_lain',
                'persyaratan' => 'Sertifikat Pelatihan / Kompetensi Relevan',
                'aktif' => 'Y',
                'wajib' => 'N',
            ],
            [
                'shortcode' => 'form_pendaftaran',
                'persyaratan' => 'Form Pendaftaran',
                'aktif' => 'Y',
                'wajib' => 'N',
            ],
            [
                'shortcode' => 'sertifikat_atpa_ktpa',
                'persyaratan' => 'Sertifikat Kompetensi ATPA / KTPA Sebelumnya',
                'aktif' => 'Y',
                'wajib' => 'N',
            ],
        ];

        foreach ($items as $item) {
            $exists = DB::table('asesi_persyaratanpokok')->where('shortcode', $item['shortcode'])->first();
            if ($exists) {
                DB::table('asesi_persyaratanpokok')->where('id', $exists->id)->update([
                    'persyaratan' => $item['persyaratan'],
                    'aktif' => $item['aktif'],
                    'wajib' => $item['wajib'],
                ]);
            } else {
                DB::table('asesi_persyaratanpokok')->insert($item);
            }
        }

        DB::table('asesi_persyaratanpokok')
            ->whereIn('shortcode', ['suket', 'transkrip', 'kk'])
            ->update(['aktif' => 'N', 'wajib' => 'N']);
    }

    public function down(): void
    {
    }
};
