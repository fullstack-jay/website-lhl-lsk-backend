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
        Schema::create('riwayat_ponses', function (Blueprint $table) {
            $table->id();
            $table->integer('pengaduan_id')->unsigned();
            $table->date('tanggal');
            $table->time('waktu');
            $table->string('admin');
            $table->text('isi');
            $table->string('lampiran')->nullable();
            $table->timestamps();

            $table->foreign('pengaduan_id')->references('id')->on('pengaduan')->onDelete('cascade');
            $table->index('pengaduan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('riwayat_ponses');
    }
};
