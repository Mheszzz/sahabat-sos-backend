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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('kontras_tinggi')->default(false)->after('text_besar');
            $table->string('metode_komunikasi')->nullable()->after('kategori_user'); // e.g. chat, pesan_suara_audio, keduanya
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['kontras_tinggi', 'metode_komunikasi']);
        });
    }
};
