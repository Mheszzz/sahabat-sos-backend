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
        // 1. Tambahkan kolom status perangkat (telemetri) ke tabel s_o_s jika belum ada
        Schema::table('s_o_s', function (Blueprint $table) {
            if (!Schema::hasColumn('s_o_s', 'battery_level')) {
                $table->integer('battery_level')->nullable()->after('waktu_sos');
            }
            if (!Schema::hasColumn('s_o_s', 'signal_strength')) {
                $table->string('signal_strength')->nullable()->after('battery_level');
            }
            if (!Schema::hasColumn('s_o_s', 'device_info')) {
                $table->json('device_info')->nullable()->after('signal_strength');
            }
        });

        // 2. Buat tabel s_o_s_activities jika belum ada
        if (!Schema::hasTable('s_o_s_activities')) {
            Schema::create('s_o_s_activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sos_id')->constrained('s_o_s')->onDelete('cascade');
                $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
                $table->string('action'); // e.g. sos_dipicu, relawan_menerima, relawan_menolak, admin_dispatch, sirene_posko, sos_selesai, sos_dibatalkan
                $table->text('description');
                $table->json('extra_data')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('s_o_s_activities')) {
            Schema::dropIfExists('s_o_s_activities');
        }

        Schema::table('s_o_s', function (Blueprint $table) {
            if (Schema::hasColumn('s_o_s', 'device_info')) {
                $table->dropColumn('device_info');
            }
            if (Schema::hasColumn('s_o_s', 'signal_strength')) {
                $table->dropColumn('signal_strength');
            }
            if (Schema::hasColumn('s_o_s', 'battery_level')) {
                $table->dropColumn('battery_level');
            }
        });
    }
};
