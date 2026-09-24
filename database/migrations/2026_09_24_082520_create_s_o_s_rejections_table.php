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
        Schema::create('s_o_s_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_sos')->constrained('s_o_s')->onDelete('cascade');
            $table->foreignId('id_relawan')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_o_s_rejections');
    }
};
