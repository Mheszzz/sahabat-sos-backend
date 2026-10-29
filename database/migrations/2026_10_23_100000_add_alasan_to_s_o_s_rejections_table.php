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
        if (Schema::hasTable('s_o_s_rejections') && !Schema::hasColumn('s_o_s_rejections', 'alasan')) {
            Schema::table('s_o_s_rejections', function (Blueprint $table) {
                $table->string('alasan')->nullable()->after('id_relawan');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('s_o_s_rejections') && Schema::hasColumn('s_o_s_rejections', 'alasan')) {
            Schema::table('s_o_s_rejections', function (Blueprint $table) {
                $table->dropColumn('alasan');
            });
        }
    }
};
