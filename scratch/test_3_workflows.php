<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Account;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LaporanController;

$auth = new AuthController();
$laporan = new LaporanController();

echo "========================================\n";
echo "1. WORKFLOW PENGGUNA\n";
echo "========================================\n";

// 1. Regis Pengguna
$resPenggunaReg = $auth->register(Request::create('/api/auth/register', 'POST', [
    'name' => 'Pengguna Skenario',
    'email' => 'pengguna.skenario@test.com',
    'password' => 'password123',
    'role' => 'pengguna',
    'persetujuan_privasi' => true,
]));
echo "1a. Regis Pengguna Status: " . $resPenggunaReg->getStatusCode() . "\n";
$userPengguna = User::whereHas('accounts', fn($q)=>$q->where('email', 'pengguna.skenario@test.com'))->first();

// 1b. Complete Profile
$reqComplete = Request::create('/api/user/complete-profile', 'POST', [
    'alamat' => 'Jl. Tebet Raya No 10',
    'no_telp' => '081122334455',
    'kategori_user' => 'tunarungu',
]);
$reqComplete->setUserResolver(fn() => $userPengguna);
$resComplete = $auth->completeProfile($reqComplete);
echo "1b. Complete Profile Status: " . $resComplete->getStatusCode() . "\n";

// 1c. Kirim Laporan Cepat
$reqKirim = Request::create('/api/laporan', 'POST', [
    'kategori_laporan' => 'Kondisi Medis',
    'lokasi_laporan' => 'Jl. Tebet Raya No 10, Jakarta Selatan',
    'latitude' => -6.2250,
    'longitude' => 106.8550,
    'pesan_cepat' => ['Saya tidak dapat berbicara / mendengar'],
    'keterangan_tambahan' => 'Butuh bantuan segera',
]);
$reqKirim->setUserResolver(fn() => $userPengguna);
$resKirim = $laporan->store($reqKirim);
echo "1c. Kirim Laporan Status: " . $resKirim->getStatusCode() . "\n\n";

echo "========================================\n";
echo "2. WORKFLOW RELAWAN\n";
echo "========================================\n";

// 2a. Regis Relawan
$resRelawanReg = $auth->register(Request::create('/api/auth/register', 'POST', [
    'name' => 'Relawan Skenario',
    'email' => 'relawan.skenario@test.com',
    'password' => 'password123',
    'role' => 'relawan',
    'persetujuan_privasi' => true,
]));
echo "2a. Regis Relawan Status: " . $resRelawanReg->getStatusCode() . "\n";
$userRelawan = User::whereHas('accounts', fn($q)=>$q->where('email', 'relawan.skenario@test.com'))->first();
echo "Status Verifikasi Awal Relawan: " . $userRelawan->status_verifikasi . "\n";

echo "========================================\n";
echo "3. WORKFLOW ADMIN\n";
echo "========================================\n";

// 3a. Admin Login
$resAdminLogin = $auth->adminLogin(Request::create('/api/auth/admin/login', 'POST', [
    'email' => 'superadmin@sahabatsos.com',
    'password' => 'password123',
]));
echo "3a. Admin Login Status: " . $resAdminLogin->getStatusCode() . "\n";
$admin = User::where('role', 'superadmin')->first();

// 3b. Admin Lihat Pending Relawan
$reqPending = Request::create('/api/admin/relawan/pending', 'GET');
$reqPending->setUserResolver(fn() => $admin);
$resPending = $auth->getPendingRelawan($reqPending);
echo "3b. Admin Get Pending Relawan Count: " . json_decode($resPending->getContent(), true)['total'] . "\n";

// 3c. Admin Verifikasi Relawan (Approve)
$reqVerif = Request::create("/api/admin/relawan/{$userRelawan->id}/verifikasi", 'PUT', [
    'status_verifikasi' => 'terverifikasi'
]);
$reqVerif->setUserResolver(fn() => $admin);
$resVerif = $auth->verifikasiRelawan($userRelawan->id, $reqVerif);
echo "3c. Admin Verifikasi Status: " . $resVerif->getStatusCode() . "\n";
echo "Status Verifikasi Relawan Setelah Verif: " . $userRelawan->refresh()->status_verifikasi . "\n\n";

echo "========================================\n";
echo "2d (Lanjutan). RELAWAN PROSES LAPORAN\n";
echo "========================================\n";

// 2b. Relawan Update GPS Location (-6.2260, 106.8560) - dekat Tebet
$reqGPS = Request::create('/api/user/update-location', 'POST', [
    'latitude' => -6.2260,
    'longitude' => 106.8560,
    'status_ketersediaan' => 'tersedia',
]);
$reqGPS->setUserResolver(fn() => $userRelawan);
$resGPS = $auth->updateLocation($reqGPS);
echo "2b. Relawan Update GPS Status: " . $resGPS->getStatusCode() . "\n";

// 2c. Relawan Cek Laporan Terdekat (Radius 5 KM)
$reqNearby = Request::create('/api/laporan/nearby', 'GET', [
    'latitude' => -6.2260,
    'longitude' => 106.8560,
    'radius' => 5.0,
]);
$reqNearby->setUserResolver(fn() => $userRelawan);
$resNearby = $laporan->nearby($reqNearby);
echo "2c. Relawan Get Nearby Laporan Found: " . json_decode($resNearby->getContent(), true)['total_found'] . "\n";

// 2d. Relawan Proses Laporan
$laporanId = json_decode($resKirim->getContent(), true)['data']['id'];
$reqProses = Request::create("/api/laporan/{$laporanId}/status", 'PUT', [
    'status' => 'proses'
]);
$reqProses->setUserResolver(fn() => $userRelawan);
$resProses = $laporan->updateStatus($laporanId, $reqProses);
echo "2d. Relawan Ubah Status Laporan to Proses: " . $resProses->getStatusCode() . "\n";
