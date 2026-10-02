<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;

echo "Users columns:\n";
echo implode(', ', Schema::getColumnListing('users')) . "\n\n";

echo "Migrations status:\n";
\Illuminate\Support\Facades\Artisan::call('migrate:status');
echo \Illuminate\Support\Facades\Artisan::output();
