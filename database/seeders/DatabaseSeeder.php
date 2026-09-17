<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use App\Models\KategoriLaporan;
use App\Models\PesanCepat;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed Superadmin Account
        $superadminAccount = Account::where('email', 'superadmin@sahabatsos.com')->where('provider', 'local')->first();
        if (!$superadminAccount) {
            $superadmin = User::create([
                'name' => 'Super Admin',
                'role' => 'superadmin',
            ]);

            Account::create([
                'user_id'  => $superadmin->id,
                'provider' => 'local',
                'email'    => 'superadmin@sahabatsos.com',
                'password' => Hash::make('password123'),
            ]);
        }

        // 2. Seed Admin Account
        $adminAccount = Account::where('email', 'admin@sahabatsos.com')->where('provider', 'local')->first();
        if (!$adminAccount) {
            $admin = User::create([
                'name'    => 'Admin Utama',
                'role'    => 'admin',
                'alamat'  => 'Kantor Pusat Sahabat SOS',
                'no_telp' => '081234567890',
            ]);

            Account::create([
                'user_id'  => $admin->id,
                'provider' => 'local',
                'email'    => 'admin@sahabatsos.com',
                'password' => Hash::make('password123'),
            ]);
        }

        // 3. Seed Kategori Laporans
        $kategoriList = [
            ['id' => 'butuh_pendamping', 'title' => 'Butuh Pendamping', 'subtitle' => 'Relawan & Petugas', 'icon' => 'people_alt_rounded', 'color' => '#1565C0'],
            ['id' => 'kondisi_medis', 'title' => 'Kondisi Medis', 'subtitle' => 'Ambulans & Obat', 'icon' => 'local_hospital_rounded', 'color' => '#D32F2F'],
            ['id' => 'ancaman_bahaya', 'title' => 'Ancaman / Bahaya', 'subtitle' => 'Keamanan Cepat', 'icon' => 'shield_rounded', 'color' => '#E65100'],
            ['id' => 'tersesat', 'title' => 'Tersesat', 'subtitle' => 'Panduan Arah', 'icon' => 'explore_rounded', 'color' => '#00838F'],
            ['id' => 'aksesibilitas_rusak', 'title' => 'Aksesibilitas Rusak', 'subtitle' => 'Bantuan Akses', 'icon' => 'accessible_rounded', 'color' => '#6A1B9A'],
            ['id' => 'lainnya', 'title' => 'Lainnya', 'subtitle' => 'Bantuan Khusus', 'icon' => 'more_horiz_rounded', 'color' => '#546E7A'],
        ];

        foreach ($kategoriList as $kat) {
            KategoriLaporan::firstOrCreate(['id' => $kat['id']], $kat);
        }

        // 4. Seed Pesan Cepat
        $pesanList = [
            'Saya butuh bantuan di lokasi saya',
            'Saya tidak dapat berbicara / mendengar',
            'Tolong hubungi kontak keluarga saya',
            'Saya butuh bantuan mobilitas / kursi roda',
        ];

        foreach ($pesanList as $pesan) {
            PesanCepat::firstOrCreate(['pesan' => $pesan]);
        }
    }
}
