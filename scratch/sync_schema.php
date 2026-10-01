<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

if (!Schema::hasColumn('s_o_s', 'battery_level')) {
    Schema::table('s_o_s', function (Blueprint $table) {
        $table->integer('battery_level')->nullable();
        $table->string('signal_strength')->nullable();
        $table->json('device_info')->nullable();
    });
    echo "Added columns to s_o_s\n";
} else {
    echo "Columns already exist in s_o_s\n";
}

if (!Schema::hasTable('s_o_s_activities')) {
    Schema::create('s_o_s_activities', function (Blueprint $table) {
        $table->id();
        $table->foreignId('sos_id')->constrained('s_o_s')->onDelete('cascade');
        $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
        $table->string('action');
        $table->text('description');
        $table->json('extra_data')->nullable();
        $table->timestamps();
    });
    echo "Created s_o_s_activities table\n";
} else {
    echo "s_o_s_activities table already exists\n";
}
