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
        Schema::create('pendaftarans', function (Blueprint $table) {
            $table->id();
            $table->string('no_pendaftaran')->unique();

            // Informasi Pribadi
            $table->string('nama');
            $table->string('email');
            $table->string('no_hp', 14);
            $table->string('no_ktp', 16)->unique();
            $table->string('kebangsaan');
            $table->enum('kualifikasi_pendidikan', ['D4', 'S1', 'S2', 'S3']);
            $table->string('bidang_keahlian');

            // Alamat Lengkap
            $table->text('alamat');
            $table->string('propinsi')->nullable();
            $table->string('kota')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kelurahan')->nullable();

            // Lokasi Uji Kompetensi
            $table->string('wil_ujikom');

            // Data Pekerjaan Sekarang
            $table->string('nama_institusi');
            $table->string('jabatan');
            $table->text('alamat_kantor');
            $table->string('kode_pos', 5);
            $table->string('no_telp_kantor')->nullable();
            $table->string('no_fax_kantor')->nullable();
            $table->string('email_kantor')->nullable();

            // Password untuk akses sistem
            $table->string('password')->nullable();

            // Status & Metadata
            $table->enum('status', ['PENDING', 'DIVERIFIKASI', 'DISETUJUI', 'DITOLAK'])->default('PENDING');
            $table->text('catatan')->nullable();
            $table->timestamp('tanggal_verifikasi')->nullable();
            $table->string('verified_by')->nullable();

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('status');
            $table->index('no_pendaftaran');
            $table->index('no_ktp');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pendaftarans');
    }
};
