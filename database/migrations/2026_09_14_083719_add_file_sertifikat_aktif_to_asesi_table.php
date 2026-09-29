<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('asesi')) {
            Schema::table('asesi', function (Blueprint $table) {
                if (!Schema::hasColumn('asesi', 'file_sertifikat_aktif')) {
                    $table->string('file_sertifikat_aktif', 255)->nullable()->after('sertifikat_atpa_ktpa');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('asesi')) {
            Schema::table('asesi', function (Blueprint $table) {
                if (Schema::hasColumn('asesi', 'file_sertifikat_aktif')) {
                    $table->dropColumn('file_sertifikat_aktif');
                }
            });
        }
    }
};
