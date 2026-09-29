<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy: kolom `komite.pekerjaan` varchar(3) semula menyimpan kode master
 * tabel `pekerjaan`. Profil komite sekarang mengisinya sebagai teks bebas
 * (mis. "Praktisi Lingkungan") sehingga INSERT/UPDATE gagal dengan
 * "Data too long for column 'pekerjaan'".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('komite', function (Blueprint $table) {
            $table->string('pekerjaan', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('komite', function (Blueprint $table) {
            $table->string('pekerjaan', 3)->nullable()->change();
        });
    }
};
