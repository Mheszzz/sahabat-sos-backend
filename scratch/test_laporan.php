<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Laporan;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\LaporanController;
use Illuminate\Http\UploadedFile;

$controller = new LaporanController();
$user = User::where('role', 'pengguna')->first();

// 1. Test Get Options
$resOptions = $controller->getOptions();
echo "Get Options Status: " . $resOptions->getStatusCode() . "\n";
echo "Get Options Data: " . $resOptions->getContent() . "\n\n";

// 2. Test Store Laporan (Kirim Laporan Cepat)
$reqStore = Request::create('/api/laporan', 'POST', [
    'kategori_laporan'    => 'Butuh Pendamping',
    'lokasi_laporan'      => 'Jl. Sudirman No. 45, Jakarta',
    'pesan_cepat'         => ['Saya butuh bantuan di lokasi saya', 'Saya tidak dapat berbicara / mendengar'],
    'keterangan_tambahan' => 'Patokan dekat halte busway.',
]);
$reqStore->setUserResolver(fn() => $user);

$resStore = $controller->store($reqStore);
echo "Store Laporan Status: " . $resStore->getStatusCode() . "\n";
echo "Store Laporan JSON: " . $resStore->getContent() . "\n\n";

$createdData = json_decode($resStore->getContent(), true)['data'];
$laporanId = $createdData['id'];

// 3. Test Index Laporan
$reqIndex = Request::create('/api/laporan', 'GET');
$reqIndex->setUserResolver(fn() => $user);

$resIndex = $controller->index($reqIndex);
echo "Index Laporan Status: " . $resIndex->getStatusCode() . "\n";
echo "Index Laporan Items: " . count(json_decode($resIndex->getContent(), true)['data']['data']) . "\n\n";

// 4. Test Show Laporan
$reqShow = Request::create("/api/laporan/{$laporanId}", 'GET');
$reqShow->setUserResolver(fn() => $user);

$resShow = $controller->show($laporanId, $reqShow);
echo "Show Laporan Status: " . $resShow->getStatusCode() . "\n";
echo "Show Laporan JSON: " . $resShow->getContent() . "\n\n";

// 5. Test Update Status Laporan by Relawan
$relawan = User::where('role', 'relawan')->first() ?? User::create(['name' => 'Relawan A', 'role' => 'relawan']);

$reqUpdate = Request::create("/api/laporan/{$laporanId}/status", 'PUT', [
    'status' => 'proses'
]);
$reqUpdate->setUserResolver(fn() => $relawan);

$resUpdate = $controller->updateStatus($laporanId, $reqUpdate);
echo "Update Status Status: " . $resUpdate->getStatusCode() . "\n";
echo "Update Status JSON: " . $resUpdate->getContent() . "\n\n";
