<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Laporan;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LaporanController;

$authCtrl = new AuthController();
$laporanCtrl = new LaporanController();

// 1. Buat User Pengguna & 2 Relawan
$pengguna = User::create([
    'name'                => 'Pengguna Darurat',
    'role'                => 'pengguna',
    'persetujuan_privasi' => true,
]);

$relawanMonas = User::create([
    'name'                => 'Relawan Monas Jakarta',
    'role'                => 'relawan',
    'persetujuan_privasi' => true,
]);

$relawanBandung = User::create([
    'name'                => 'Relawan Bandung',
    'role'                => 'relawan',
    'persetujuan_privasi' => true,
]);

// 2. Update Lokasi GPS Relawan
// Relawan Monas: -6.1754, 106.8272
$reqLoc1 = Request::create('/api/user/update-location', 'POST', [
    'latitude'            => -6.1754,
    'longitude'           => 106.8272,
    'lokasi_user'         => 'Monas, Jakarta Pusat',
    'status_ketersediaan' => 'tersedia',
]);
$reqLoc1->setUserResolver(fn() => $relawanMonas);
$authCtrl->updateLocation($reqLoc1);

// Relawan Bandung: -6.9175, 107.6191
$reqLoc2 = Request::create('/api/user/update-location', 'POST', [
    'latitude'            => -6.9175,
    'longitude'           => 107.6191,
    'lokasi_user'         => 'Alun-alun Bandung',
    'status_ketersediaan' => 'tersedia',
]);
$reqLoc2->setUserResolver(fn() => $relawanBandung);
$authCtrl->updateLocation($reqLoc2);

echo "Lokasi Relawan Monas: " . $relawanMonas->refresh()->latitude . ", " . $relawanMonas->longitude . "\n";
echo "Lokasi Relawan Bandung: " . $relawanBandung->refresh()->latitude . ", " . $relawanBandung->longitude . "\n\n";

// 3. Pengguna Membuat Laporan di Sekitar Monas (-6.1800, 106.8300)
$reqLaporan = Request::create('/api/laporan', 'POST', [
    'kategori_laporan'    => 'Kondisi Medis',
    'lokasi_laporan'      => 'Jl. Medan Merdeka Selatan No. 8',
    'latitude'            => -6.1800,
    'longitude'           => 106.8300,
    'radius'              => 5.0, // 5 KM
    'pesan_cepat'         => ['Saya butuh bantuan di lokasi saya'],
    'keterangan_tambahan' => 'Butuh obat P3K cepat',
]);
$reqLaporan->setUserResolver(fn() => $pengguna);

$resLaporan = $laporanCtrl->store($reqLaporan);
echo "Store Laporan Realtime Status: " . $resLaporan->getStatusCode() . "\n";
echo "Store Laporan JSON: " . $resLaporan->getContent() . "\n\n";

// 4. Relawan Monas Mengecek Laporan Terdekat (GET /api/laporan/nearby)
$reqNearby = Request::create('/api/laporan/nearby', 'GET', [
    'latitude'  => -6.1754,
    'longitude' => 106.8272,
    'radius'    => 5.0,
]);
$reqNearby->setUserResolver(fn() => $relawanMonas);

$resNearby = $laporanCtrl->nearby($reqNearby);
echo "Nearby Laporan Status: " . $resNearby->getStatusCode() . "\n";
echo "Nearby Laporan JSON: " . $resNearby->getContent() . "\n\n";
