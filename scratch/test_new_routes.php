<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use Illuminate\Http\Request;

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$auth = new \App\Http\Controllers\Api\AuthController();

// 1. Test register pengguna (tanpa field role di JSON body)
$reqPengguna = Request::create('/api/auth/register/pengguna', 'POST', [
    'name'                => 'Pengguna Baru Test Route',
    'email'               => 'user_route_test_' . time() . '@example.com',
    'password'            => 'password123',
    'persetujuan_privasi' => true,
]);

$resPengguna = $auth->registerPengguna($reqPengguna);
echo "STATUS REGISTER PENGGUNA ROUTE: " . $resPengguna->getStatusCode() . "\n";
echo "BODY: " . $resPengguna->getContent() . "\n\n";

// 2. Test register relawan (tanpa field role di JSON body)
$reqRelawan = Request::create('/api/auth/register/relawan', 'POST', [
    'name'                => 'Relawan Baru Test Route',
    'email'               => 'relawan_route_test_' . time() . '@example.com',
    'password'            => 'password123',
    'persetujuan_privasi' => true,
    'no_telp'             => '08' . rand(1000000000, 9999999999),
    'alamat'              => 'Jl. Testing Route No. 1',
    'pekerjaan'           => 'Relawan Test',
    'alasan_relawan'      => 'Ingin membantu sesama via route relawan.',
]);

$resRelawan = $auth->registerRelawan($reqRelawan);
echo "STATUS REGISTER RELAWAN ROUTE: " . $resRelawan->getStatusCode() . "\n";
echo "BODY: " . $resRelawan->getContent() . "\n\n";
