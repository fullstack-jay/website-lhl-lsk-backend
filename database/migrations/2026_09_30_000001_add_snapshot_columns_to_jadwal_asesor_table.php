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
        // 1. Tambah kolom snapshot di tabel jadwal_asesor
        if (Schema::hasTable('jadwal_asesor')) {
            Schema::table('jadwal_asesor', function (Blueprint $table) {
                if (!Schema::hasColumn('jadwal_asesor', 'nama_asesor')) {
                    $table->string('nama_asesor', 255)->nullable()->after('id_asesor');
                }
                if (!Schema::hasColumn('jadwal_asesor', 'gelar_depan')) {
                    $table->string('gelar_depan', 50)->nullable()->after('nama_asesor');
                }
                if (!Schema::hasColumn('jadwal_asesor', 'gelar_blk')) {
                    $table->string('gelar_blk', 50)->nullable()->after('gelar_depan');
                }
                if (!Schema::hasColumn('jadwal_asesor', 'no_lisensi')) {
                    $table->string('no_lisensi', 100)->nullable()->after('gelar_blk');
                }
                if (!Schema::hasColumn('jadwal_asesor', 'no_ktp')) {
                    $table->string('no_ktp', 30)->nullable()->after('no_lisensi');
                }
            });

            // Backfill nama asesor untuk data lama di jadwal_asesor
            try {
                DB::statement("
                    UPDATE jadwal_asesor ja
                    JOIN asesor a ON ja.id_asesor = a.id
                    SET ja.nama_asesor = a.nama,
                        ja.gelar_depan = a.gelar_depan,
                        ja.gelar_blk = a.gelar_blk,
                        ja.no_lisensi = a.no_lisensi,
                        ja.no_ktp = a.no_ktp
                    WHERE ja.nama_asesor IS NULL
                ");
            } catch (\Throwable $e) {}
        }

        // 2. Tambah kolom snapshot di tabel penilaian_asesi
        if (Schema::hasTable('penilaian_asesi')) {
            Schema::table('penilaian_asesi', function (Blueprint $table) {
                if (!Schema::hasColumn('penilaian_asesi', 'nama_asesor')) {
                    $table->string('nama_asesor', 255)->nullable()->after('id_asesor');
                }
            });

            // Backfill nama asesor untuk data lama di penilaian_asesi
            try {
                DB::statement("
                    UPDATE penilaian_asesi pa
                    JOIN asesor a ON pa.id_asesor = a.id
                    SET pa.nama_asesor = a.nama
                    WHERE pa.nama_asesor IS NULL
                ");
            } catch (\Throwable $e) {}
        }

        // 3. Tambah kolom snapshot di tabel asesi_asesmen
        if (Schema::hasTable('asesi_asesmen')) {
            Schema::table('asesi_asesmen', function (Blueprint $table) {
                if (!Schema::hasColumn('asesi_asesmen', 'nama_asesor')) {
                    $table->string('nama_asesor', 255)->nullable()->after('id_asesor');
                }
            });

            // Backfill nama asesor untuk data lama di asesi_asesmen
            try {
                DB::statement("
                    UPDATE asesi_asesmen aa
                    JOIN asesor a ON aa.id_asesor = a.id
                    SET aa.nama_asesor = a.nama
                    WHERE aa.nama_asesor IS NULL
                ");
            } catch (\Throwable $e) {}
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('jadwal_asesor')) {
            Schema::table('jadwal_asesor', function (Blueprint $table) {
                $columns = ['nama_asesor', 'gelar_depan', 'gelar_blk', 'no_lisensi', 'no_ktp'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('jadwal_asesor', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('penilaian_asesi')) {
            Schema::table('penilaian_asesi', function (Blueprint $table) {
                if (Schema::hasColumn('penilaian_asesi', 'nama_asesor')) {
                    $table->dropColumn('nama_asesor');
                }
            });
        }

        if (Schema::hasTable('asesi_asesmen')) {
            Schema::table('asesi_asesmen', function (Blueprint $table) {
                if (Schema::hasColumn('asesi_asesmen', 'nama_asesor')) {
                    $table->dropColumn('nama_asesor');
                }
            });
        }
    }
};
