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
        if (Schema::hasTable('asesi_pemeliharaan') && !Schema::hasColumn('asesi_pemeliharaan', 'link_dokumen_lengkap')) {
            Schema::table('asesi_pemeliharaan', function (Blueprint $table) {
                $table->text('link_dokumen_lengkap')->nullable()->after('file_cover_tim');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('asesi_pemeliharaan') && Schema::hasColumn('asesi_pemeliharaan', 'link_dokumen_lengkap')) {
            Schema::table('asesi_pemeliharaan', function (Blueprint $table) {
                $table->dropColumn('link_dokumen_lengkap');
            });
        }
    }
};
