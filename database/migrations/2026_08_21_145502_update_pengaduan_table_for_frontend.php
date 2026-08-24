<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pengaduan', function (Blueprint $table) {
            // Add new columns
            $table->string('no_pengaduan', 20)->nullable()->unique()->after('id');
            $table->date('tanggal')->nullable()->after('no_pengaduan');
            $table->time('waktu')->nullable()->after('tanggal');

            // Track read status
            $table->boolean('dibaca')->default(false)->after('catatan_internal');
            $table->string('dibaca_oleh')->nullable()->after('dibaca');
            $table->timestamp('dibaca_tanggal')->nullable()->after('dibaca_oleh');

            // Soft delete
            $table->softDeletes()->after('ip_address');
        });

        // First convert existing data to lowercase
        DB::statement("UPDATE pengaduan SET jenis_responden = 'peserta' WHERE jenis_responden IN ('Peserta', 'peserta')");
        DB::statement("UPDATE pengaduan SET jenis_responden = 'penguji' WHERE jenis_responden IN ('Penguji', 'penguji')");
        DB::statement("UPDATE pengaduan SET jenis_responden = 'masyarakat' WHERE jenis_responden IN ('Masyarakat', 'masyarakat')");

        // Update status enum to new values (with both old and new temporarily)
        DB::statement("ALTER TABLE pengaduan MODIFY COLUMN status ENUM('waiting', 'processing', 'completed', 'archived', 'Masuk', 'Diproses', 'Selesai', 'Ditutup') DEFAULT 'waiting'");

        // Update existing data to new values
        DB::statement("UPDATE pengaduan SET status = 'waiting' WHERE status IN ('Masuk', 'waiting') OR status IS NULL");
        DB::statement("UPDATE pengaduan SET status = 'processing' WHERE status IN ('Diproses', 'processing')");
        DB::statement("UPDATE pengaduan SET status = 'completed' WHERE status IN ('Selesai', 'completed')");
        DB::statement("UPDATE pengaduan SET status = 'archived' WHERE status IN ('Ditutup', 'archived')");

        // Now set enum to only new values
        DB::statement("ALTER TABLE pengaduan MODIFY COLUMN status ENUM('waiting', 'processing', 'completed', 'archived') DEFAULT 'waiting'");
        DB::statement("ALTER TABLE pengaduan MODIFY COLUMN jenis_responden ENUM('peserta', 'penguji', 'masyarakat') NULL");

        // Add indexes
        Schema::table('pengaduan', function (Blueprint $table) {
            $table->index('no_pengaduan');
            $table->index('tanggal');
            $table->index('dibaca');
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pengaduan', function (Blueprint $table) {
            $table->dropIndex(['no_pengaduan']);
            $table->dropIndex(['tanggal']);
            $table->dropIndex(['dibaca']);
            $table->dropIndex(['deleted_at']);

            $table->dropColumn(['no_pengaduan', 'tanggal', 'waktu', 'dibaca', 'dibaca_oleh', 'dibaca_tanggal']);
            $table->dropSoftDeletes();
        });

        // Revert status enum
        DB::statement("ALTER TABLE pengaduan MODIFY COLUMN status ENUM('waiting', 'processing', 'completed', 'archived', 'Masuk', 'Diproses', 'Selesai', 'Ditutup') DEFAULT 'Masuk'");
        DB::statement("UPDATE pengaduan SET status = 'Masuk' WHERE status = 'waiting'");
        DB::statement("UPDATE pengaduan SET status = 'Diproses' WHERE status = 'processing'");
        DB::statement("UPDATE pengaduan SET status = 'Selesai' WHERE status = 'completed'");
        DB::statement("UPDATE pengaduan SET status = 'Ditutup' WHERE status = 'archived'");
        DB::statement("ALTER TABLE pengaduan MODIFY COLUMN status ENUM('Masuk', 'Diproses', 'Selesai', 'Ditutup') DEFAULT 'Masuk'");

        DB::statement("ALTER TABLE pengaduan MODIFY COLUMN jenis_responden ENUM('peserta', 'penguji', 'masyarakat', 'Peserta', 'Penguji', 'Masyarakat') NULL");
        DB::statement("UPDATE pengaduan SET jenis_responden = 'Peserta' WHERE jenis_responden = 'peserta'");
        DB::statement("UPDATE pengaduan SET jenis_responden = 'Penguji' WHERE jenis_responden = 'penguji'");
        DB::statement("UPDATE pengaduan SET jenis_responden = 'Masyarakat' WHERE jenis_responden = 'masyarakat'");
        DB::statement("ALTER TABLE pengaduan MODIFY COLUMN jenis_responden ENUM('Peserta', 'Penguji', 'Masyarakat') NULL");
    }
};
