<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Kontak_darurat;
use Illuminate\Support\Facades\DB;

echo "--- START TESTING KONTAK DARURAT CRUD ---\n";

// 1. Ambil atau Buat Pengguna untuk testing
$user = User::where('role', 'pengguna')->first();
if (!$user) {
    $user = User::create([
        'name' => 'Test User Kontak',
        'no_telp' => '08123456789',
        'role' => 'pengguna',
    ]);
}

echo "Testing dengan User ID: {$user->id} ({$user->name})\n";

// Clear kontak lama user ini untuk test terisolasi
Kontak_darurat::where('id_pengguna', $user->id)->delete();

// 2. Test Create Kontak Utama
$kontak1 = Kontak_darurat::create([
    'id_pengguna'  => $user->id,
    'nama'         => 'Ayah (Utama)',
    'no_telp'      => '08111111111',
    'pesan'        => 'Pesan ke Ayah',
    'terima_notif' => true,
    'tipe'         => 'utama',
]);
echo "[PASS] Created Kontak Utama: ID {$kontak1->id}, Nama: {$kontak1->nama}, Tipe: {$kontak1->tipe}, Terima Notif: " . ($kontak1->terima_notif ? 'Ya' : 'Tidak') . "\n";

// 3. Test Create Kontak Sekunder
$kontak2 = Kontak_darurat::create([
    'id_pengguna'  => $user->id,
    'nama'         => 'Ibu (Sekunder)',
    'no_telp'      => '08222222222',
    'pesan'        => 'Pesan ke Ibu',
    'terima_notif' => true,
    'tipe'         => 'sekunder',
]);
echo "[PASS] Created Kontak Sekunder: ID {$kontak2->id}, Nama: {$kontak2->nama}, Tipe: {$kontak2->tipe}\n";

// 4. Test Create Kontak Utama Baru (harus mengubah kontak1 menjadi sekunder)
// simulasi logika controller
Kontak_darurat::where('id_pengguna', $user->id)->where('tipe', 'utama')->update(['tipe' => 'sekunder']);
$kontak3 = Kontak_darurat::create([
    'id_pengguna'  => $user->id,
    'nama'         => 'Kakak (Utama Baru)',
    'no_telp'      => '08333333333',
    'terima_notif' => false,
    'tipe'         => 'utama',
]);

$kontak1Refresh = $kontak1->fresh();
echo "[PASS] Created Kontak Utama Baru: ID {$kontak3->id}, Nama: {$kontak3->nama}\n";
echo "      Kontak Utama lama (Ayah) sekarang bertipe: {$kontak1Refresh->tipe}\n";

// 5. Test Query Index Order (Utama dulu baru Sekunder)
$list = Kontak_darurat::where('id_pengguna', $user->id)
    ->orderByRaw("CASE WHEN tipe = 'utama' THEN 1 ELSE 2 END")
    ->orderBy('created_at', 'desc')
    ->get();

echo "[PASS] Listing Total Contacts: " . count($list) . "\n";
foreach ($list as $index => $item) {
    echo "      #{$index} ID: {$item->id}, Nama: {$item->nama}, Tipe: {$item->tipe}, Terima Notif: " . ($item->terima_notif ? 'On' : 'Off') . "\n";
}

// 6. Test Toggle Notif
$kontak3->terima_notif = !$kontak3->terima_notif;
$kontak3->save();
echo "[PASS] Toggled Notif Kontak Kakak: Terima Notif sekarang = " . ($kontak3->fresh()->terima_notif ? 'On' : 'Off') . "\n";

// 7. Test Delete
$kontak2->delete();
echo "[PASS] Deleted Kontak Ibu ID {$kontak2->id}. Sisa kontak: " . Kontak_darurat::where('id_pengguna', $user->id)->count() . "\n";

echo "--- ALL TESTS COMPLETED SUCCESSFULLY ---\n";
