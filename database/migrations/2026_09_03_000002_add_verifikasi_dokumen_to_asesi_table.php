<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('asesi', 'verifikasi_dokumen')) {
            Schema::table('asesi', function (Blueprint $table) {
                $table->json('verifikasi_dokumen')->nullable()->after('verifikasi');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('asesi', 'verifikasi_dokumen')) {
            Schema::table('asesi', function (Blueprint $table) {
                $table->dropColumn('verifikasi_dokumen');
            });
        }
    }
};
