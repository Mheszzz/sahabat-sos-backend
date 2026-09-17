<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\AuthController;

$auth = new AuthController();

// Create dummy user
$user = User::create([
    'name' => 'Relawan Test ' . time(),
    'role' => 'pengguna', // initial role from Google / Auth
]);

$reqComplete = Request::create('/api/user/complete-profile', 'POST', [
    'alamat' => 'Jl. Merdeka No 123',
    'no_telp' => '0899' . rand(100000, 999999),
    'role' => 'relawan',
    'pekerjaan' => 'Dokter / Tim Medis',
    'alasan_relawan' => 'Ingin membantu sesama',
]);

$reqComplete->setUserResolver(fn() => $user);
$response = $auth->completeProfile($reqComplete);

echo "Status Code: " . $response->getStatusCode() . "\n";
echo "Response Body: " . json_encode($response->getData(), JSON_PRETTY_PRINT) . "\n";

$user->refresh();
echo "Updated User Role: " . $user->role . "\n";
echo "Updated Pekerjaan: " . $user->pekerjaan . "\n";
echo "Updated Alasan Relawan: " . $user->alasan_relawan . "\n";

// Clean up
$user->delete();
