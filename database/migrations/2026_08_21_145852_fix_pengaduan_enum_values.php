<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update empty strings to NULL
        DB::statement("UPDATE pengaduan SET jenis_responden = NULL WHERE jenis_responden = ''");

        // Update existing data to lowercase
        DB::statement("UPDATE pengaduan SET jenis_responden = LOWER(jenis_responden) WHERE jenis_responden IS NOT NULL");

        // Convert status to new values
        DB::statement("UPDATE pengaduan SET status = 'waiting' WHERE status IN ('Masuk', 'waiting') OR status IS NULL");
        DB::statement("UPDATE pengaduan SET status = 'processing' WHERE status IN ('Diproses', 'processing')");
        DB::statement("UPDATE pengaduan SET status = 'completed' WHERE status IN ('Selesai', 'completed')");
        DB::statement("UPDATE pengaduan SET status = 'archived' WHERE status IN ('Ditutup', 'archived')");

        // Now modify enum to only new values
        DB::statement("ALTER TABLE pengaduan MODIFY COLUMN status ENUM('waiting', 'processing', 'completed', 'archived') DEFAULT 'waiting'");
        DB::statement("ALTER TABLE pengaduan MODIFY COLUMN jenis_responden ENUM('peserta', 'penguji', 'masyarakat') NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Allow old and new values temporarily
        DB::statement("ALTER TABLE pengaduan MODIFY COLUMN status ENUM('waiting', 'processing', 'completed', 'archived', 'Masuk', 'Diproses', 'Selesai', 'Ditutup') DEFAULT 'Masuk'");
        DB::statement("ALTER TABLE pengaduan MODIFY COLUMN jenis_responden ENUM('peserta', 'penguji', 'masyarakat', 'Peserta', 'Penguji', 'Masyarakat') NULL");

        // Convert back to old values
        DB::statement("UPDATE pengaduan SET status = 'Masuk' WHERE status = 'waiting'");
        DB::statement("UPDATE pengaduan SET status = 'Diproses' WHERE status = 'processing'");
        DB::statement("UPDATE pengaduan SET status = 'Selesai' WHERE status = 'completed'");
        DB::statement("UPDATE pengaduan SET status = 'Ditutup' WHERE status = 'archived'");

        DB::statement("UPDATE pengaduan SET jenis_responden = CONCAT(UPPER(SUBSTRING(jenis_responden, 1, 1)), SUBSTRING(jenis_responden, 2)) WHERE jenis_responden IS NOT NULL");

        // Now modify enum to only old values
        DB::statement("ALTER TABLE pengaduan MODIFY COLUMN status ENUM('Masuk', 'Diproses', 'Selesai', 'Ditutup') DEFAULT 'Masuk'");
        DB::statement("ALTER TABLE pengaduan MODIFY COLUMN jenis_responden ENUM('Peserta', 'Penguji', 'Masyarakat') NULL");
    }
};
