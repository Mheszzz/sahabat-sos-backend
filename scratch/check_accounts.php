<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\User;

$accounts = Account::select('id', 'user_id', 'provider', 'email')->get()->toArray();
$users = User::select('id', 'name', 'role')->get()->toArray();

echo json_encode(['accounts' => $accounts, 'users' => $users], JSON_PRETTY_PRINT);
