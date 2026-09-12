<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$accounts = \App\Models\Account::with('user')->get();

foreach ($accounts as $a) {
    echo "ID Account: {$a->id} | Email: {$a->email} | Provider: {$a->provider} | Role: {$a->user->role} | Nama: {$a->user->name}\n";
}
