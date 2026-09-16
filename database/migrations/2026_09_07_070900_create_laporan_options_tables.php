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
        Schema::create('kategori_laporans', function (Blueprint $table) {
            $table->string('id')->primary(); // e.g. 'butuh_pendamping'
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pesan_cepats', function (Blueprint $table) {
            $table->id();
            $table->string('pesan');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pesan_cepats');
        Schema::dropIfExists('kategori_laporans');
    }
};
