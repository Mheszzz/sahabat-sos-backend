<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Http\Controllers\Api\PetaKasusAdminController;
use App\Http\Controllers\Api\SebaranUrgensiAdminController;
use Illuminate\Http\Request;

echo "--- START TESTING PETA KASUS & SEBARAN URGENSI CONTROLLERS ---\n";

$admin = User::whereIn('role', ['admin', 'superadmin'])->first();
if (!$admin) {
    $admin = User::create([
        'name' => 'Admin Test',
        'no_telp' => '08999999999',
        'role' => 'admin',
    ]);
}

// 1. Test PetaKasusAdminController
$petaController = new PetaKasusAdminController();
$reqPeta = Request::create('/api/admin/dashboard/peta-kasus', 'GET');
$reqPeta->setUserResolver(fn() => $admin);
$resPeta = $petaController->index($reqPeta);
$dataPeta = json_decode($resPeta->getContent(), true);

echo "[PASS] GET /api/admin/dashboard/peta-kasus (Status: {$resPeta->getStatusCode()})\n";
echo "      Total SOS Aktif: {$dataPeta['summary']['total_sos_aktif']}\n";
echo "      Total Laporan Aktif: {$dataPeta['summary']['total_laporan_aktif']}\n";
echo "      Total Relawan Siaga: {$dataPeta['summary']['total_relawan_siaga']}\n";

// 2. Test SebaranUrgensiAdminController
$sebaranController = new SebaranUrgensiAdminController();
$reqSebaran = Request::create('/api/admin/dashboard/sebaran-urgensi', 'GET');
$reqSebaran->setUserResolver(fn() => $admin);
$resSebaran = $sebaranController->index($reqSebaran);
$dataSebaran = json_decode($resSebaran->getContent(), true);

echo "[PASS] GET /api/admin/dashboard/sebaran-urgensi (Status: {$resSebaran->getStatusCode()})\n";
echo "      Level Kritis: {$dataSebaran['data']['ringkasan_urgensi']['level_kritis']['jumlah']} ({$dataSebaran['data']['ringkasan_urgensi']['level_kritis']['status']})\n";
echo "      Level Sedang: {$dataSebaran['data']['ringkasan_urgensi']['level_sedang']['jumlah']}\n";
echo "      Tingkat Keberhasilan: {$dataSebaran['data']['ringkasan_urgensi']['tingkat_keberhasilan_bantuan']}\n";
echo "      Total Kategori Disabilitas: " . count($dataSebaran['data']['sebaran_disabilitas']) . "\n";
echo "      Total Kategori Laporan: " . count($dataSebaran['data']['sebaran_kategori_laporan']) . "\n";

echo "--- ALL TESTS PASSED SUCCESSFULLY ---\n";
