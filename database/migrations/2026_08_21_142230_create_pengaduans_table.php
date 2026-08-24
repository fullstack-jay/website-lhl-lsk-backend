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
        Schema::create('pengaduans', function (Blueprint $table) {
            $table->id();
            $table->string('no_tiket', 50)->unique();
            $table->string('nama', 100);
            $table->string('email', 100)->nullable();
            $table->string('nohp', 20)->nullable();
            $table->text('aduan');
            $table->enum('status', ['masuk', 'diproses', 'selesai', 'ditutup'])->default('masuk');
            $table->date('tgl_aduan');
            $table->enum('jenis_responden', ['Peserta', 'Penguji', 'Masyarakat'])->nullable();
            $table->text('respon_admin')->nullable();
            $table->dateTime('tgl_respon')->nullable();
            $table->text('catatan_internal')->nullable();
            $table->string('lampiran', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('tgl_aduan');
            $table->index('jenis_responden');
        });

        // Tabel Riwayat Pengaduan
        Schema::create('pengaduan_riwayat', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_pengaduan');
            $table->string('status_sebelumnya', 20)->nullable();
            $table->string('status_baru', 20);
            $table->string('aksi', 50);
            $table->string('oleh', 50);
            $table->timestamp('waktu')->useCurrent();
            $table->text('catatan')->nullable();

            $table->foreign('id_pengaduan')->references('id')->on('pengaduans')->onDelete('cascade');
            $table->index('id_pengaduan');
            $table->index('waktu');
        });

        // Tabel Kategori Pengaduan
        Schema::create('pengaduan_kategori', function (Blueprint $table) {
            $table->id();
            $table->string('nama_kategori', 100);
            $table->text('deskripsi')->nullable();
            $table->enum('aktif', ['Y', 'N'])->default('Y');
            $table->integer('urutan')->default(0);
        });

        // Insert kategori default
        DB::table('pengaduan_kategori')->insert([
            ['nama_kategori' => 'Layanan Sertifikasi', 'deskripsi' => 'Keluhan terkait proses sertifikasi', 'urutan' => 1],
            ['nama_kategori' => 'Jadwal Asesmen', 'deskripsi' => 'Pertanyaan terkait jadwal asesmen', 'urutan' => 2],
            ['nama_kategori' => 'Biaya & Pembayaran', 'deskripsi' => 'Keluhan terkait biaya dan pembayaran', 'urutan' => 3],
            ['nama_kategori' => 'Sertifikat & Dokumen', 'deskripsi' => 'Masalah terkait sertifikat dan dokumen', 'urutan' => 4],
            ['nama_kategori' => 'Kompetensi Asesor', 'deskripsi' => 'Keluhan terkait kompetensi asesor', 'urutan' => 5],
            ['nama_kategori' => 'Lainnya', 'deskripsi' => 'Kategori lainnya', 'urutan' => 99],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pengaduan_riwayat');
        Schema::dropIfExists('pengaduan_kategori');
        Schema::dropIfExists('pengaduans');
    }
};
