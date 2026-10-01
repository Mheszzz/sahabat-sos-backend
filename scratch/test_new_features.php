<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\SOS;
use App\Models\Laporan;
use App\Models\SOSActivity;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\DashboardAdminController;
use App\Http\Controllers\Api\SOSController;

echo "=== TESTING 4 NEW FEATURES ===\n\n";

// 1. Get or create test users
$admin = User::where('role', 'admin')->first() ?? User::create([
    'name' => 'Admin Test',
    'role' => 'admin',
    'no_telp' => '081199998888',
]);

$pengguna = User::firstOrCreate(['no_telp' => '081234567800'], [
    'name' => 'Korban Test',
    'role' => 'pengguna',
    'kategori_user' => 'tunanetra',
    'device_id' => 'DEVICE-TEST-UUID-999',
]);

$relawan = User::firstOrCreate(['no_telp' => '085611223344'], [
    'name' => 'Relawan Test',
    'role' => 'relawan',
    'status_ketersediaan' => 'tersedia',
    'latitude' => -6.2088,
    'longitude' => 106.8456,
]);

// 2. Feature 1 & 2: Create SOS with device_info and verify activities & device_info
echo "1. Testing SOS Trigger with device_info...\n";
$sosController = new SOSController();
$request = Request::create('/api/sos/trigger', 'POST', [
    'latitude' => -6.2100,
    'longitude' => 106.8450,
    'battery_level' => 18,
    'signal_strength' => '3G / Lemah',
    'device_info' => [
        'brand' => 'Xiaomi',
        'model' => 'Redmi Note 12',
        'battery_level' => 18,
        'signal_strength' => '3G / Lemah',
    ]
]);
$request->setUserResolver(fn() => $pengguna);

// Clear active SOS for test user if any
SOS::where('id_pengguna', $pengguna->id)->delete();

$response = $sosController->store($request);
$sosData = json_decode($response->getContent(), true)['data'];
$sosId = $sosData['id'];

echo "Created SOS #{$sosId}. Status: {$sosData['status_sos']}\n";
echo "Device Info: " . json_encode($sosData['device_info']) . "\n";
assert(isset($sosData['device_info']['battery_level']), "Battery level must exist");
assert($sosData['device_info']['battery_status'] === 'Kritis', "Battery status should be Kritis (<20%)");
echo "-> Feature 2 (device_info in SOS) PASSED!\n\n";

// Test Dispatch & Activity Log
echo "2. Testing Admin Dispatch & Activity Log...\n";
$adminController = new DashboardAdminController();
$dispatchReq = Request::create('/api/admin/dashboard/dispatch', 'POST', [
    'sos_id' => $sosId,
    'relawan_id' => $relawan->id,
]);
$dispatchReq->setUserResolver(fn() => $admin);
$dispatchRes = $adminController->dispatchRelawan($dispatchReq);
echo "Dispatched volunteer: " . json_decode($dispatchRes->getContent(), true)['message'] . "\n";

// Test GET /api/admin/sos/{id}/activities
echo "3. Testing GET /api/admin/sos/{id}/activities...\n";
$actReq = Request::create("/api/admin/sos/{$sosId}/activities", 'GET');
$actReq->setUserResolver(fn() => $admin);
$actRes = $adminController->getSosActivities($actReq, $sosId);
$actData = json_decode($actRes->getContent(), true);
echo "Total activities recorded: {$actData['total']}\n";
foreach ($actData['activity_log'] as $act) {
    echo " - [{$act['action']}] {$act['description']} (By: {$act['actor']})\n";
}
assert($actData['total'] >= 2, "Must have at least 2 activities (triggered and dispatched)");
echo "-> Feature 1 (Log Riwayat Aktivitas) PASSED!\n\n";

// Test Feature 4: Bunyikan Sirene Posko
echo "4. Testing POST /api/admin/posko/sirene...\n";
$sirenReq = Request::create('/api/admin/posko/sirene', 'POST', [
    'action' => 'trigger',
    'pesan' => 'Peringatan Darurat: Sinyal Darurat Posko Aktif!',
    'sos_id' => $sosId,
]);
$sirenReq->setUserResolver(fn() => $admin);
$sirenRes = $adminController->triggerSirenePosko($sirenReq);
$sirenData = json_decode($sirenRes->getContent(), true);
echo "Siren response: {$sirenData['message']}\n";
echo "Siren status active: " . ($sirenData['data']['sirene_active'] ? 'YES' : 'NO') . "\n";
assert($sirenData['data']['sirene_active'] === true, "Siren should be active");
echo "-> Feature 4 (Bunyikan Sirene Posko) PASSED!\n\n";

// Test Feature 3: Ekspor CSV Laporan
echo "5. Testing GET /api/admin/laporan/export...\n";
// Create test laporan if none
if (Laporan::count() === 0) {
    Laporan::create([
        'id_pengguna'      => $pengguna->id,
        'kategori_laporan' => 'ancaman_bahaya',
        'deskripsi'        => 'Ada kebakaran kecil di dekat halte',
        'status'           => 'aktif',
        'latitude'         => -6.2088,
        'longitude'        => 106.8456,
        'lokasi_laporan'   => 'Halte Busway Menara Astra',
    ]);
}

$exportReq = Request::create('/api/admin/laporan/export', 'GET');
$exportReq->setUserResolver(fn() => $admin);
$exportRes = $adminController->exportLaporanCsv($exportReq);

assert($exportRes instanceof \Symfony\Component\HttpFoundation\StreamedResponse, "Must return StreamedResponse");
echo "Export CSV status: " . $exportRes->getStatusCode() . "\n";
echo "Content-Type: " . $exportRes->headers->get('Content-Type') . "\n";
echo "Content-Disposition: " . $exportRes->headers->get('Content-Disposition') . "\n";
echo "-> Feature 3 (Ekspor CSV Laporan) PASSED!\n\n";

echo "ALL 4 FEATURES TESTED SUCCESSFULLY!\n";
