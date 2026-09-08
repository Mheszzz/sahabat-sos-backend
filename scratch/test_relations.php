<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Kontak_darurat;
use App\Models\SOS;
use App\Models\Laporan;

$pengguna = User::create([
    'name' => 'Pengguna Test',
    'role' => 'pengguna'
]);

$relawan = User::create([
    'name' => 'Relawan Test',
    'role' => 'relawan'
]);

$kontak = $pengguna->kontakDarurat()->create([
    'nama' => 'Ayah',
    'no_telp' => '08123456789',
    'pesan' => 'Tolong saya!'
]);

$sos = $pengguna->sosCreated()->create([
    'lokasi_sos' => 'Jl. Merdeka No 1',
    'status_sos' => 'aktif',
    'id_relawan' => $relawan->id
]);

$laporan = $pengguna->laporanCreated()->create([
    'lokasi_laporan' => 'Jl. Sudirman',
    'kategori_laporan' => 'Bencana',
    'deskripsi' => 'Ada banjir',
    'id_relawan' => $relawan->id
]);

echo "Kontak Owner: " . $kontak->pengguna->name . "\n";
echo "SOS Creator: " . $sos->pengguna->name . "\n";
echo "SOS Resolver: " . $sos->relawan->name . "\n";
echo "Laporan Creator: " . $laporan->pengguna->name . "\n";
echo "Laporan Resolver: " . $laporan->relawan->name . "\n";
echo "Relawan SOS Handled Count: " . $relawan->sosHandled()->count() . "\n";
echo "Relawan Laporan Handled Count: " . $relawan->laporanHandled()->count() . "\n";
