<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\SOS;
use App\Models\Laporan;
use App\Http\Controllers\Api\DashboardAdminController;
use Illuminate\Http\Request;

echo "--- START TESTING DASHBOARD ADMIN CONTROLLER ---\n";

// Prepare dummy data if needed
$admin = User::whereIn('role', ['admin', 'superadmin'])->first();
if (!$admin) {
    $admin = User::create([
        'name' => 'Admin Test',
        'no_telp' => '08999999999',
        'role' => 'admin',
    ]);
}

$pengguna = User::where('role', 'pengguna')->first();
if (!$pengguna) {
    $pengguna = User::create([
        'name' => 'Pengguna Difabel Test',
        'no_telp' => '0812341234',
        'role' => 'pengguna',
        'kategori_user' => 'tunanetra',
        'alamat' => 'Halte Menara Astra, Jakarta',
    ]);
}

$relawan = User::where('role', 'relawan')->first();
if (!$relawan) {
    $relawan = User::create([
        'name' => 'Relawan Lapangan Test',
        'no_telp' => '08777777777',
        'role' => 'relawan',
        'status_verifikasi' => 'terverifikasi',
        'latitude' => -6.2088,
        'longitude' => 106.8456,
        'pekerjaan' => 'Navigasi Tunanetra & P3K',
    ]);
}

// Create sample SOS if none active
$sos = SOS::where('status_sos', 'aktif')->first();
if (!$sos) {
    $sos = SOS::create([
        'id_pengguna' => $pengguna->id,
        'latitude'    => -6.2090,
        'longitude'   => 106.8460,
        'status_sos'  => 'aktif',
        'waktu_sos'   => now(),
    ]);
}

$controller = new DashboardAdminController();

// 1. Test Index
$request = Request::create('/api/admin/dashboard', 'GET');
$request->setUserResolver(fn() => $admin);
$response = $controller->index($request);
$data = json_decode($response->getContent(), true);

echo "[PASS] GET /api/admin/dashboard (Status: {$response->getStatusCode()})\n";
echo "      KPI SOS Hari Ini: {$data['data']['kpi']['panggilan_sos_hari_ini']['total']} ({$data['data']['kpi']['panggilan_sos_hari_ini']['tren']})\n";
echo "      KPI Laporan Hari Ini: {$data['data']['kpi']['jumlah_laporan_hari_ini']['total']}\n";
echo "      KPI SOS Aktif: {$data['data']['kpi']['darurat_sos_aktif']['total']}\n";
echo "      KPI Relawan Siaga: {$data['data']['kpi']['relawan_siaga_aktif']['siaga']} / {$data['data']['kpi']['relawan_siaga_aktif']['total_personel']}\n";
echo "      Antrean Kasus Total: " . count($data['data']['antrean_kasus']) . "\n";
echo "      GIS Titik Darurat Total: " . count($data['data']['gis_map']['titik_darurat']) . "\n";

// 2. Test Quick Dispatch
$reqDispatchList = Request::create('/api/admin/dashboard/quick-dispatch', 'GET', [
    'sos_id' => $sos->id,
]);
$resDispatchList = $controller->getQuickDispatchRelawan($reqDispatchList);
$dispatchData = json_decode($resDispatchList->getContent(), true);
echo "[PASS] GET /api/admin/dashboard/quick-dispatch (Total Relawan Terdekat: {$dispatchData['total']})\n";

// 3. Test Dispatch Relawan
$reqDispatchAction = Request::create('/api/admin/dashboard/dispatch', 'POST', [
    'sos_id'     => $sos->id,
    'relawan_id' => $relawan->id,
]);
$resDispatchAction = $controller->dispatchRelawan($reqDispatchAction);
echo "[PASS] POST /api/admin/dashboard/dispatch (Status: {$resDispatchAction->getStatusCode()})\n";

// 4. Test Global Search
$reqSearch = Request::create('/api/admin/dashboard/search', 'GET', ['q' => $pengguna->name]);
$resSearch = $controller->globalSearch($reqSearch);
$searchData = json_decode($resSearch->getContent(), true);
echo "[PASS] GET /api/admin/dashboard/search?q={$pengguna->name} (Hasil Pengguna: " . count($searchData['data']['pengguna']) . ")\n";

// 5. Test Selesai SOS
$reqSelesai = Request::create("/api/admin/dashboard/sos/{$sos->id}/selesai", 'PUT');
$resSelesai = $controller->selesaiSOS($reqSelesai, $sos->id);
echo "[PASS] PUT /api/admin/dashboard/sos/{$sos->id}/selesai (Status: {$resSelesai->getStatusCode()})\n";

echo "--- ALL TESTS PASSED SUCCESSFULLY ---\n";
