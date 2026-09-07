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
        Schema::create('kontak_darurats', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->id('pesan');
            $table->id('no_telp');
            $table->boolean('terima_notif');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kontak_darurats');
    }
};
