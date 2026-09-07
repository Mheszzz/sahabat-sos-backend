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
        Schema::create('laporans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_pengguna')->constrained('users')->onDelete('cascade');
            $table->foreignId('id_relawan')->nullable()->constrained('users')->onDelete('set null');
            $table->string('lokasi_laporan');
            $table->string('kategori_laporan');
            $table->text('deskripsi');
            $table->string('foto_laporan')->nullable();
            $table->string('rekam_suara')->nullable();
            $table->enum('status', ['aktif', 'proses', 'selesai'])->default('aktif');
            $table->timestamp('waktu_laporan')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laporans');
    }
};
