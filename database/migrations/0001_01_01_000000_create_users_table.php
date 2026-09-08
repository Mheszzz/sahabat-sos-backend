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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('alamat')->nullable();
            $table->string('no_telp')->unique()->nullable();
            $table->enum('role', ['superadmin', 'admin', 'pengguna', 'relawan'])->default('pengguna');
            $table->string('device_id')->unique()->nullable();
            $table->string('foto_profile')->nullable();
            $table->boolean('getaran')->default(false);
            $table->boolean('talkback')->default(false);
            $table->boolean('panduan_suara')->default(false);
            $table->boolean('text_besar')->default(false);
            $table->string('lokasi_user')->nullable();
            $table->enum('kategori_user', ['umum', 'tunarungu', 'tunanetra', 'tunawicara'])->default('umum');
            $table->string('status_ketersediaan')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamp('last_located_at')->nullable();
            $table->enum('status_verifikasi', ['pending', 'terverifikasi', 'ditolak'])->default('terverifikasi');
            $table->boolean('persetujuan_privasi')->default(false);
            $table->timestamp('waktu_persetujuan')->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
