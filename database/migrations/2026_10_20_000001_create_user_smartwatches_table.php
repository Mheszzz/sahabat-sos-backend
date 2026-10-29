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
        Schema::create('user_smartwatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_pengguna')->constrained('users')->onDelete('cascade');
            $table->string('device_identifier'); // UUID / MAC / Peer Device ID
            $table->string('brand')->default('generic_ble'); // huawei, wearos, generic_ble, etc.
            $table->string('device_model')->nullable(); // e.g. Huawei Watch GT 4
            $table->string('mac_address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('last_battery_level')->nullable();
            $table->integer('last_heart_rate')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['id_pengguna', 'device_identifier']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_smartwatches');
    }
};
