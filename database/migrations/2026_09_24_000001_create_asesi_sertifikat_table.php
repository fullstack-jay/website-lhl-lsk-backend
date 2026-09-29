<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relasi 1 peserta → banyak sertifikat.
 * Tabel `asesi.no_sertifikat` hanya memuat 1 nomor per orang; pemegang
 * dua sertifikat (mis. KTPA + ATPA) membutuhkan tabel relasi ini agar
 * seluruh sertifikat dapat ditampilkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asesi_sertifikat', function (Blueprint $table) {
            $table->id();
            $table->string('no_pendaftaran', 50)->index();
            $table->string('no_sertifikat', 100)->unique();
            $table->string('jenis_sertifikat', 20)->nullable();   // ATPA | KTPA
            $table->date('tgl_sertifikat')->nullable();
            $table->smallInteger('tahun')->nullable();
            $table->string('file_sertifikat')->nullable();        // upload berkas PDF nanti
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asesi_sertifikat');
    }
};
