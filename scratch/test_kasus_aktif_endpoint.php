<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Http\Controllers\Api\KasusAktifController;
use Illuminate\Http\Request;

$admin = User::where('role', 'admin')->first();

$controller = new KasusAktifController();
$request = Request::create('/api/admin/kasus-aktif', 'GET');
$request->setUserResolver(fn() => $admin);

$response = $controller->index($request);
$data = json_decode($response->getContent(), true);

echo "Status Code: " . $response->getStatusCode() . "\n";
echo "Message: " . ($data['message'] ?? '') . "\n";
echo "Total Laporan Aktif/Proses: " . count($data['data']['laporan']['data'] ?? []) . "\n";
foreach ($data['data']['laporan']['data'] as $l) {
    echo "  - [LAPORAN #{$l['id']}] Status: {$l['status']} | Alamat: {$l['lokasi']['alamat']}\n";
}

echo "Total SOS Aktif/Proses: " . count($data['data']['sos']['data'] ?? []) . "\n";
foreach ($data['data']['sos']['data'] as $s) {
    echo "  - [SOS #{$s['id']}] Status: {$s['status']} | Lat: {$s['lokasi']['latitude']}, Lng: {$s['lokasi']['longitude']}\n";
}
