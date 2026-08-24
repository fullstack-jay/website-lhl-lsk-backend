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
        Schema::table('skema_kkni', function (Blueprint $table) {
            // Change apl02 from ENUM to VARCHAR to support more options
            $table->string('apl02', 20)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('skema_kkni', function (Blueprint $table) {
            // Revert back to ENUM
            $table->enum('apl02', ['elemen', 'KUK'])->nullable()->change();
        });
    }
};
