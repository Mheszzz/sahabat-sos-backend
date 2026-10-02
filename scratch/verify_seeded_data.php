<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Laporan;
use App\Models\SOS;
use App\Models\User;

echo "=== RINGKASAN DATA LAPORAN ===\n";
$laporanPerStatus = Laporan::select('status', \DB::raw('count(*) as total'))
    ->groupBy('status')
    ->get();
foreach ($laporanPerStatus as $row) {
    echo "Status: {$row->status} -> Total: {$row->total}\n";
}

echo "\nDetail Laporan:\n";
foreach (Laporan::with(['pengguna', 'relawan'])->latest('id')->get() as $lap) {
    $pelapor = $lap->pengguna ? "{$lap->pengguna->name} ({$lap->pengguna->kategori_user})" : 'Tanpa Pelapor';
    $relawan = $lap->relawan ? $lap->relawan->name : '-';
    echo "#LAP-{$lap->id} | Status: {$lap->status} | Kategori: {$lap->kategori_laporan} | Pelapor: {$pelapor} | Relawan: {$relawan} | Waktu: {$lap->waktu_laporan}\n";
}

echo "\n=== RINGKASAN DATA SOS ===\n";
$sosPerStatus = SOS::select('status_sos', \DB::raw('count(*) as total'))
    ->groupBy('status_sos')
    ->get();
foreach ($sosPerStatus as $row) {
    echo "Status SOS: {$row->status_sos} -> Total: {$row->total}\n";
}

echo "\nDetail Kasus SOS:\n";
foreach (SOS::with(['pengguna', 'relawan', 'activities'])->latest('id')->get() as $s) {
    $pelapor = $s->pengguna ? "{$s->pengguna->name} ({$s->pengguna->kategori_user})" : 'Tanpa Pelapor';
    $relawan = $s->relawan ? $s->relawan->name : '-';
    $actCount = $s->activities->count();
    $baterai = $s->device_info['battery_level'] ?? $s->battery_level;
    echo "#SOS-{$s->id} | Status: {$s->status_sos} | Pelapor: {$pelapor} | Relawan: {$relawan} | Baterai: {$baterai}% | Log Aktivitas: {$actCount} | Waktu: {$s->waktu_sos}\n";
}

echo "\n=== RINGKASAN USER & RELAWAN ===\n";
$users = User::select('role', \DB::raw('count(*) as total'))->groupBy('role')->get();
foreach ($users as $u) {
    echo "Role: {$u->role} -> Total: {$u->total}\n";
}
