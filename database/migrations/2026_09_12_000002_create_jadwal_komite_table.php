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
        if (!Schema::hasTable('jadwal_komite')) {
            Schema::create('jadwal_komite', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_jadwal');
                $table->unsignedBigInteger('id_komite');
                $table->string('peran', 50)->nullable();
                $table->timestamps();

                $table->index('id_jadwal');
                $table->index('id_komite');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jadwal_komite');
    }
};
