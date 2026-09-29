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
        // Add columns to asesi table
        if (Schema::hasTable('asesi')) {
            Schema::table('asesi', function (Blueprint $table) {
                if (!Schema::hasColumn('asesi', 'jenis_sertifikat')) {
                    $table->string('jenis_sertifikat', 20)->nullable()->after('sertifikat_atpa_ktpa');
                }
                if (!Schema::hasColumn('asesi', 'masa_berlaku_sertifikat')) {
                    $table->date('masa_berlaku_sertifikat')->nullable()->after('tgl_sertifikat');
                }
                if (!Schema::hasColumn('asesi', 'status_sertifikat')) {
                    $table->string('status_sertifikat', 30)->default('BELUM_UPLOAD')->after('masa_berlaku_sertifikat');
                }
                if (!Schema::hasColumn('asesi', 'catatan_sertifikat')) {
                    $table->text('catatan_sertifikat')->nullable()->after('status_sertifikat');
                }
                if (!Schema::hasColumn('asesi', 'tgl_verifikasi_sertifikat')) {
                    $table->timestamp('tgl_verifikasi_sertifikat')->nullable()->after('catatan_sertifikat');
                }
                if (!Schema::hasColumn('asesi', 'verified_by_sertifikat')) {
                    $table->string('verified_by_sertifikat', 100)->nullable()->after('tgl_verifikasi_sertifikat');
                }
            });
        }

        // Add columns to pendaftarans table
        if (Schema::hasTable('pendaftarans')) {
            Schema::table('pendaftarans', function (Blueprint $table) {
                if (!Schema::hasColumn('pendaftarans', 'has_active_certificate')) {
                    $table->boolean('has_active_certificate')->default(false)->after('password');
                }
                if (!Schema::hasColumn('pendaftarans', 'jenis_sertifikat')) {
                    $table->string('jenis_sertifikat', 20)->nullable()->after('has_active_certificate');
                }
                if (!Schema::hasColumn('pendaftarans', 'no_sertifikat')) {
                    $table->string('no_sertifikat', 100)->nullable()->after('jenis_sertifikat');
                }
                if (!Schema::hasColumn('pendaftarans', 'tgl_sertifikat')) {
                    $table->date('tgl_sertifikat')->nullable()->after('no_sertifikat');
                }
                if (!Schema::hasColumn('pendaftarans', 'masa_berlaku_sertifikat')) {
                    $table->date('masa_berlaku_sertifikat')->nullable()->after('tgl_sertifikat');
                }
                if (!Schema::hasColumn('pendaftarans', 'file_sertifikat')) {
                    $table->string('file_sertifikat', 255)->nullable()->after('masa_berlaku_sertifikat');
                }
                if (!Schema::hasColumn('pendaftarans', 'status_sertifikat')) {
                    $table->string('status_sertifikat', 30)->default('BELUM_UPLOAD')->after('file_sertifikat');
                }
                if (!Schema::hasColumn('pendaftarans', 'catatan_sertifikat')) {
                    $table->text('catatan_sertifikat')->nullable()->after('status_sertifikat');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('asesi')) {
            Schema::table('asesi', function (Blueprint $table) {
                $columns = [
                    'jenis_sertifikat',
                    'masa_berlaku_sertifikat',
                    'status_sertifikat',
                    'catatan_sertifikat',
                    'tgl_verifikasi_sertifikat',
                    'verified_by_sertifikat',
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('asesi', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('pendaftarans')) {
            Schema::table('pendaftarans', function (Blueprint $table) {
                $columns = [
                    'has_active_certificate',
                    'jenis_sertifikat',
                    'no_sertifikat',
                    'tgl_sertifikat',
                    'masa_berlaku_sertifikat',
                    'file_sertifikat',
                    'status_sertifikat',
                    'catatan_sertifikat',
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('pendaftarans', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
