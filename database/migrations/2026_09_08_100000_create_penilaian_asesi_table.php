<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel penilaian_asesi — Form Penilaian Asesi (4 Instrumen Uji)
 * Sesuai docs/BACKEND_FORM_PENILAIAN.md:
 * - 4 nilai mentah instrumen (VP/PT/DPSK/PW, 0-100)
 * - 4 nilai terbobot + total_skor (kalkulasi backend — single source of truth)
 * - rekomendasi K/BK otomatis (passing grade 70.00)
 * - rubrik_detail JSON (snapshot kalkulator portofolio)
 * UNIQUE (id_jadwal, no_pendaftaran)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('penilaian_asesi')) {
            return;
        }

        Schema::create('penilaian_asesi', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('id_jadwal');
            $table->unsignedInteger('id_asesmen');
            $table->string('no_pendaftaran', 50);
            $table->unsignedInteger('id_asesor');

            // 4 Nilai Mentah Hasil Test (0.00 - 100.00)
            $table->decimal('nilai_vp', 5, 2)->default(0);      // Verifikasi Portofolio
            $table->decimal('nilai_pt', 5, 2)->default(0);      // Pertanyaan Tertulis
            $table->decimal('nilai_dpsk', 5, 2)->default(0);    // Daftar Pertanyaan Studi Kasus
            $table->decimal('nilai_pw', 5, 2)->default(0);      // Pertanyaan Wawancara

            // 4 Nilai Terbobot (Hasil Kalkulasi Backend)
            $table->decimal('skor_vp', 5, 2)->default(0);
            $table->decimal('skor_pt', 5, 2)->default(0);
            $table->decimal('skor_dpsk', 5, 2)->default(0);
            $table->decimal('skor_pw', 5, 2)->default(0);
            $table->decimal('total_skor', 5, 2)->default(0);

            // Rekomendasi Otomatis (K / BK — passing grade 70)
            $table->enum('rekomendasi', ['K', 'BK']);
            $table->text('catatan')->nullable();
            $table->json('rubrik_detail')->nullable();
            $table->date('tgl_penilaian');

            $table->timestamps();

            $table->unique(['id_jadwal', 'no_pendaftaran'], 'uq_jadwal_asesi_penilaian');
            $table->index('id_asesor');
            $table->index('id_asesmen');

            $table->foreign('id_jadwal')->references('id')->on('jadwal_asesmen')->cascadeOnDelete();
            $table->foreign('id_asesmen')->references('id')->on('asesi_asesmen')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penilaian_asesi');
    }
};
