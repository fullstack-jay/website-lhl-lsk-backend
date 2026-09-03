<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $cols = [
            'dokumen_amdal',
            'bukti_keterlibatan',
            'sertifikat_amdal',
            'form_pendaftaran',
            'sertifikat_atpa_ktpa',
            'sertifikat_kompetensi_lain',
        ];

        Schema::table('asesi', function (Blueprint $table) use ($cols) {
            foreach ($cols as $col) {
                if (!Schema::hasColumn('asesi', $col)) {
                    $table->string($col, 255)->nullable();
                }
            }
        });

        Schema::table('users', function (Blueprint $table) use ($cols) {
            foreach ($cols as $col) {
                if (!Schema::hasColumn('users', $col)) {
                    $table->string($col, 255)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        $cols = [
            'dokumen_amdal',
            'bukti_keterlibatan',
            'sertifikat_amdal',
            'form_pendaftaran',
            'sertifikat_atpa_ktpa',
            'sertifikat_kompetensi_lain',
        ];

        Schema::table('asesi', function (Blueprint $table) use ($cols) {
            $table->dropColumn($cols);
        });

        Schema::table('users', function (Blueprint $table) use ($cols) {
            $table->dropColumn($cols);
        });
    }
};
