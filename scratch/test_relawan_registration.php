<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Account;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\AuthController;

$auth = new AuthController();

echo "========================================\n";
echo "TEST 1: REGISTRASI RELAWAN TANPA FIELD WAJIB\n";
echo "========================================\n";

try {
    $reqFail = Request::create('/api/auth/register', 'POST', [
        'name' => 'Relawan Incomplete',
        'email' => 'relawan.inc@test.com',
        'password' => 'password123',
        'role' => 'relawan',
        'persetujuan_privasi' => true,
    ]);
    $resFail = $auth->register($reqFail);
    echo "Status Failure Attempt: " . $resFail->getStatusCode() . "\n";
} catch (\Illuminate\Validation\ValidationException $e) {
    echo "Validation Error Caught (Expected):\n";
    print_r($e->errors());
}

echo "\n========================================\n";
echo "TEST 2: REGISTRASI RELAWAN LENGKAP\n";
echo "========================================\n";

$reqSuccess = Request::create('/api/auth/register', 'POST', [
    'name' => 'Dr. Relawan Medis',
    'email' => 'medis.relawan@test.com',
    'password' => 'password123',
    'role' => 'relawan',
    'persetujuan_privasi' => true,
    'no_telp' => '081299998888',
    'alamat' => 'Jl. Kesehatan No. 10, Jakarta',
    'pekerjaan' => 'Dokter Umum',
    'alasan_relawan' => 'Saya berpengalaman dalam memberikan pertolongan pertama dan evakuasi medis darurat.',
]);

$resSuccess = $auth->register($reqSuccess);
echo "Status Registrasi Relawan Success: " . $resSuccess->getStatusCode() . "\n";

$userRelawan = User::whereHas('accounts', fn($q) => $q->where('email', 'medis.relawan@test.com'))->first();
echo "Status Verifikasi Awal: " . $userRelawan->status_verifikasi . "\n";
echo "Pekerjaan: " . $userRelawan->pekerjaan . "\n";
echo "Alasan Relawan: " . $userRelawan->alasan_relawan . "\n";

echo "\n========================================\n";
echo "TEST 3: ADMIN GET PENDING RELAWAN LIST\n";
echo "========================================\n";

$reqPending = Request::create('/api/admin/relawan/pending', 'GET');
$resPending = $auth->getPendingRelawan($reqPending);
$pendingData = json_decode($resPending->getContent(), true);

echo "Total Pending Relawan: " . $pendingData['total'] . "\n";
echo "Data Relawan Pertama:\n";
print_r([
    'name' => $pendingData['data'][0]['name'],
    'no_telp' => $pendingData['data'][0]['no_telp'],
    'alamat' => $pendingData['data'][0]['alamat'],
    'pekerjaan' => $pendingData['data'][0]['pekerjaan'],
    'alasan_relawan' => $pendingData['data'][0]['alasan_relawan'],
    'status_verifikasi' => $pendingData['data'][0]['status_verifikasi'],
    'email' => $pendingData['data'][0]['accounts'][0]['email'] ?? null,
]);

echo "\n========================================\n";
echo "TEST 4: ADMIN VERIFIKASI / APPROVE RELAWAN\n";
echo "========================================\n";

$reqVerif = Request::create("/api/admin/relawan/{$userRelawan->id}/verifikasi", 'PUT', [
    'status_verifikasi' => 'terverifikasi',
]);
$resVerif = $auth->verifikasiRelawan($userRelawan->id, $reqVerif);
echo "Status Response Verifikasi: " . $resVerif->getStatusCode() . "\n";
echo "Status Verifikasi Setelah Approve: " . $userRelawan->refresh()->status_verifikasi . "\n";

echo "\n========================================\n";
echo "ALL TESTS COMPLETED SUCCESSFULLY!\n";
echo "========================================\n";
