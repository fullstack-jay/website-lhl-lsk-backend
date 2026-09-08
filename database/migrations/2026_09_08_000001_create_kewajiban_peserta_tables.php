<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel Kewajiban Peserta (pemegang sertifikat ATPA/KTPA)
 * Sesuai docs/ALUR_LOGIC_KEWAJIBAN_PESERTA.md §2:
 * - asesi_pemeliharaan       : PKB per tahun per sertifikat
 * - asesi_pemeliharaan_pkb   : rows Form PKB (N kegiatan per pemeliharaan)
 * - asesi_evaluasi           : evaluasi 3 tahunan
 * - asesi_logbook            : logbook kegiatan AMDAL (Form ATPA 10-field)
 * - asesi_notifikasi         : notifikasi kewajiban
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 2.2 asesi_pemeliharaan ──
        if (!Schema::hasTable('asesi_pemeliharaan')) {
            Schema::create('asesi_pemeliharaan', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('id_asesi', 50);                      // = asesi.no_pendaftaran
                $table->unsignedBigInteger('id_asesmen')->nullable(); // rujukan asesi_asesmen.id
                $table->string('sertifikat_no', 100)->nullable();
                $table->smallInteger('tahun');
                $table->string('status', 20)->default('BELUM');      // state machine §4
                $table->dateTime('tanggal_upload')->nullable();
                $table->dateTime('tanggal_evaluasi')->nullable();
                $table->text('catatan_evaluator')->nullable();
                $table->string('file_penunjukan', 255)->nullable();  // bukti 1
                $table->string('file_logbook', 255)->nullable();     // bukti 2
                $table->string('file_cover_tim', 255)->nullable();   // bukti 3
                $table->string('file_ka_andal', 255)->nullable();    // bukti 4
                $table->timestamp('waktu')->nullable();
                $table->unique(['id_asesi', 'tahun']);                // 1 record per tahun
                $table->index('status');
            });
        }

        // ── 2.3 asesi_pemeliharaan_pkb ──
        if (!Schema::hasTable('asesi_pemeliharaan_pkb')) {
            Schema::create('asesi_pemeliharaan_pkb', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('id_pemeliharaan');       // FK → asesi_pemeliharaan.id
                $table->string('bentuk_kegiatan', 20);               // Bimtek/Seminar/Workshop/Lainnya
                $table->string('bentuk_lainnya', 100)->nullable();
                $table->string('tema', 255);
                $table->string('penyelenggara', 255);
                $table->string('lokasi', 255);
                $table->date('waktu');
                $table->text('deskripsi_singkat')->nullable();
                $table->string('file_bukti', 255)->nullable();
                $table->index('id_pemeliharaan');
            });
        }

        // ── 2.4 asesi_evaluasi ──
        if (!Schema::hasTable('asesi_evaluasi')) {
            Schema::create('asesi_evaluasi', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('id_asesi', 50);
                $table->string('sertifikat_no', 100)->nullable();
                $table->tinyInteger('tahun_ke');
                $table->smallInteger('tahun_jatuh_tempo');
                $table->string('status', 20)->default('BELUM_UPLOAD');
                $table->dateTime('tanggal_upload')->nullable();
                $table->dateTime('tanggal_evaluasi')->nullable();
                $table->text('catatan_evaluator')->nullable();
                $table->string('jenis_dokumen', 20)->nullable();     // cover/tim/pengesahan
                $table->string('file_dokumen', 255)->nullable();     // pdf max 10MB
                $table->timestamp('waktu')->nullable();
                $table->unique(['id_asesi', 'tahun_ke']);
                $table->index('status');
            });
        }

        // ── 2.5 asesi_logbook ──
        if (!Schema::hasTable('asesi_logbook')) {
            Schema::create('asesi_logbook', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('id_asesi', 50);
                $table->smallInteger('tahun');
                $table->string('nama_kegiatan', 255);
                $table->string('lokasi_kegiatan', 255)->nullable();
                $table->date('tanggal_mulai')->nullable();
                $table->date('tanggal_selesai')->nullable();
                $table->string('tipe_penyusun', 20)->default('LPJP');   // LPJP/Perorangan
                $table->string('nama_lpjp', 255)->nullable();
                $table->string('alamat_lpjp', 255)->nullable();
                $table->string('nomor_lpjp', 100)->nullable();
                $table->string('telepon_email_lpjp', 150)->nullable();
                $table->string('file_surat_tugas_lpjp', 255)->nullable();  // bukti wajib #4
                $table->string('nama_pemrakarsa', 255)->nullable();
                $table->string('alamat_pemrakarsa', 255)->nullable();
                $table->string('telepon_pemrakarsa', 100)->nullable();
                $table->string('file_referensi_pemrakarsa', 255)->nullable(); // bukti wajib #5
                $table->string('kpa_tingkat', 20)->nullable();      // Pusat/Provinsi/Kab-Kota
                $table->string('kpa_wilayah', 255)->nullable();
                $table->string('kpa_alamat', 255)->nullable();
                $table->string('kpa_telepon', 100)->nullable();
                $table->string('file_ba_persetujuan_kpa', 255)->nullable(); // bukti wajib #6
                $table->string('status_dokumen', 20)->default('PROSES');    // PROSES/DISETUJUI
                $table->string('nomor_persetujuan', 100)->nullable();
                $table->date('tanggal_persetujuan')->nullable();
                $table->smallInteger('tahun_persetujuan')->nullable();
                $table->string('jabatan', 255)->default('Anggota Tim Penyusun');
                $table->string('ahli_bidang', 255)->nullable();     // CSV
                $table->string('ahli_bidang_lainnya', 255)->nullable();
                $table->string('spesifikasi_tenaga_ahli', 255)->nullable();
                $table->timestamp('waktu')->nullable();
                $table->index(['id_asesi', 'tahun']);
            });
        }

        // ── 2.6 asesi_notifikasi ──
        if (!Schema::hasTable('asesi_notifikasi')) {
            Schema::create('asesi_notifikasi', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('id_asesi', 50);
                $table->string('tipe', 20)->default('info');        // info/warning/success/error
                $table->string('judul', 255);
                $table->text('pesan')->nullable();
                $table->string('kategori', 30)->nullable();         // pemeliharaan/evaluasi/sertifikat/logbook
                $table->tinyInteger('dibaca')->default(0);
                $table->dateTime('waktu')->nullable();
                $table->dateTime('waktu_dibaca')->nullable();
                $table->index(['id_asesi', 'dibaca']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asesi_notifikasi');
        Schema::dropIfExists('asesi_logbook');
        Schema::dropIfExists('asesi_evaluasi');
        Schema::dropIfExists('asesi_pemeliharaan_pkb');
        Schema::dropIfExists('asesi_pemeliharaan');
    }
};
