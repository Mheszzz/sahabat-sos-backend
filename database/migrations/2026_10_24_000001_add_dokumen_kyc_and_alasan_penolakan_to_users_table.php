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
            if (!Schema::hasColumn('users', 'dokumen_kyc')) {
                $table->string('dokumen_kyc')->nullable()->after('alasan_relawan');
            }
            if (!Schema::hasColumn('users', 'alasan_penolakan')) {
                $table->text('alasan_penolakan')->nullable()->after('status_verifikasi');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'dokumen_kyc')) {
                $table->dropColumn('dokumen_kyc');
            }
            if (Schema::hasColumn('users', 'alasan_penolakan')) {
                $table->dropColumn('alasan_penolakan');
            }
        });
    }
};
