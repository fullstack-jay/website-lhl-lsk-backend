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
        Schema::table('pengaduan', function (Blueprint $table) {
            $table->text('respon_admin')->nullable()->after('jenis_responden');
            $table->dateTime('tgl_respon')->nullable()->after('respon_admin');
            $table->text('catatan_internal')->nullable()->after('tgl_respon');
            $table->string('lampiran', 255)->nullable()->after('catatan_internal');
            $table->string('ip_address', 45)->nullable()->after('lampiran');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pengaduan', function (Blueprint $table) {
            $table->dropColumn(['respon_admin', 'tgl_respon', 'catatan_internal', 'lampiran', 'ip_address']);
        });
    }
};
