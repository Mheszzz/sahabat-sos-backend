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
        Schema::create('catatan_penanganans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_admin')->constrained('users')->onDelete('cascade');
            $table->foreignId('id_laporan')->nullable()->constrained('laporans')->onDelete('cascade');
            $table->foreignId('id_sos')->nullable()->constrained('s_o_s')->onDelete('cascade');
            $table->text('catatan');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catatan_penanganans');
    }
};
