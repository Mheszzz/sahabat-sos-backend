<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Account;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\AuthController;

$controller = new AuthController();

// 1a. Test Register Without Consent (Should Fail with 422)
$reqNoConsent = Request::create('/api/auth/register', 'POST', [
    'name'     => 'Budi No Consent',
    'email'    => 'budinoconsent@test.com',
    'password' => 'password123',
    'role'     => 'pengguna',
]);

try {
    $resNoConsent = $controller->register($reqNoConsent);
    echo "Register No Consent Status: " . $resNoConsent->getStatusCode() . "\n";
    echo "Register No Consent JSON: " . $resNoConsent->getContent() . "\n\n";
} catch (\Illuminate\Validation\ValidationException $e) {
    echo "Register No Consent Validation Caught (422): " . json_encode($e->errors()) . "\n\n";
}

// 1b. Test Register With Consent (Should Succeed with 201)
$reqRegister = Request::create('/api/auth/register', 'POST', [
    'name'                => 'Budi Test',
    'email'               => 'budi@test.com',
    'password'            => 'password123',
    'role'                => 'pengguna',
    'persetujuan_privasi' => true,
    'no_telp'             => '089876543210',
]);

$resRegister = $controller->register($reqRegister);
echo "Register With Consent Response Status: " . $resRegister->getStatusCode() . "\n";
echo "Register With Consent Response JSON: " . $resRegister->getContent() . "\n\n";

// 2. Test Login
$reqLogin = Request::create('/api/auth/login', 'POST', [
    'email' => 'budi@test.com',
    'password' => 'password123',
]);

$resLogin = $controller->login($reqLogin);
echo "Login Response Status: " . $resLogin->getStatusCode() . "\n";
echo "Login Response JSON: " . $resLogin->getContent() . "\n\n";

// 3. Test Beranda
$user = User::whereHas('accounts', function($q) {
    $q->where('email', 'budi@test.com');
})->first();

$reqBeranda = Request::create('/api/beranda', 'GET');
$reqBeranda->setUserResolver(fn() => $user);

$resBeranda = $controller->beranda($reqBeranda);
echo "Beranda Response Status: " . $resBeranda->getStatusCode() . "\n";
echo "Beranda Response JSON: " . $resBeranda->getContent() . "\n\n";

// 4. Test Beranda Admin
$admin = User::where('role', 'superadmin')->first();

$reqBerandaAdmin = Request::create('/api/admin/beranda', 'GET');
$reqBerandaAdmin->setUserResolver(fn() => $admin);

$resBerandaAdmin = $controller->berandaAdmin($reqBerandaAdmin);
echo "Beranda Admin Response Status: " . $resBerandaAdmin->getStatusCode() . "\n";
echo "Beranda Admin Response JSON: " . $resBerandaAdmin->getContent() . "\n\n";
