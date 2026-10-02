<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Account;
use App\Models\SOS;
use App\Models\Laporan;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Faker\Factory as Faker;

class DummyDataSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        // 1. Buat 10 User Biasa (Masyarakat)
        $users = [];
        for ($i = 0; $i < 10; $i++) {
            $user = User::create([
                'name'    => $faker->name,
                'no_telp' => $faker->phoneNumber,
                'role'    => 'pengguna',
            ]);
            Account::create([
                'user_id'  => $user->id,
                'provider' => 'local',
                'email'    => 'user' . $i . '@sahabatsos.test',
                'password' => Hash::make('password123'),
            ]);
            $users[] = $user;
        }

        // 2. Buat 5 User Relawan / Petugas
        $relawans = [];
        for ($i = 0; $i < 5; $i++) {
            $relawan = User::create([
                'name'    => $faker->name . ' (Relawan)',
                'no_telp' => $faker->phoneNumber,
                'role'    => 'admin', // Asumsi relawan menggunakan role admin atau petugas. Nanti disesuaikan jika ada role spesifik. 
            ]); // Oh wait, I checked DatabaseSeeder before, it used 'role' => 'admin'. I'll set role 'petugas' / 'relawan', let's set 'relawan'.
            $relawan->update(['role' => 'relawan']);

            Account::create([
                'user_id'  => $relawan->id,
                'provider' => 'local',
                'email'    => 'relawan' . $i . '@sahabatsos.test',
                'password' => Hash::make('password123'),
            ]);
            $relawans[] = $relawan;
        }

        // Area Koordinat Seturan dan Babaran, Jogja
        $locations = [
            'seturan' => [
                'min_lat' => -7.7710, 'max_lat' => -7.7600,
                'min_lng' => 110.4030, 'max_lng' => 110.4130,
                'nama' => 'Area Seturan, Depok, Sleman'
            ],
            'babaran' => [
                'min_lat' => -7.8130, 'max_lat' => -7.8050,
                'min_lng' => 110.3750, 'max_lng' => 110.3880,
                'nama' => 'Area Babaran, Umbulharjo, Yogyakarta'
            ]
        ];

        // 3. Buat 20 Data Laporan 
        $laporanStatuses = ['aktif', 'proses', 'selesai'];
        $kategoriList = ['butuh_pendamping', 'kondisi_medis', 'ancaman_bahaya', 'tersesat', 'aksesibilitas_rusak', 'lainnya'];

        for ($i = 0; $i < 20; $i++) {
            // Selang seling antara Seturan dan Babaran
            $locKey = $i % 2 == 0 ? 'seturan' : 'babaran';
            $loc = $locations[$locKey];
            $lat = $faker->randomFloat(6, $loc['min_lat'], $loc['max_lat']);
            $lng = $faker->randomFloat(6, $loc['min_lng'], $loc['max_lng']);
            $status = $faker->randomElement($laporanStatuses);
            
            $id_pengguna = $faker->randomElement($users)->id;
            $id_relawan = in_array($status, ['proses', 'selesai']) ? $faker->randomElement($relawans)->id : null;

            Laporan::create([
                'id_pengguna'      => $id_pengguna,
                'id_relawan'       => $id_relawan,
                'lokasi_laporan'   => $loc['nama'] . ' - ' . $faker->streetAddress,
                'latitude'         => $lat,
                'longitude'        => $lng,
                'kategori_laporan' => $faker->randomElement($kategoriList),
                'deskripsi'        => "Mohon bantuannya, " . $faker->sentence(10),
                'status'           => $status,
                'waktu_laporan'    => Carbon::now()->subDays(rand(0, 14))->subHours(rand(0, 24)),
            ]);
        }

        // 4. Buat 20 Data SOS
        $sosStatuses = ['aktif', 'proses', 'selesai', 'batal'];
        
        for ($i = 0; $i < 20; $i++) {
            $locKey = $i % 2 == 0 ? 'babaran' : 'seturan';
            $loc = $locations[$locKey];
            $lat = $faker->randomFloat(6, $loc['min_lat'], $loc['max_lat']);
            $lng = $faker->randomFloat(6, $loc['min_lng'], $loc['max_lng']);
            $status = $faker->randomElement($sosStatuses);

            $id_pengguna = $faker->randomElement($users)->id;
            $id_relawan = in_array($status, ['proses', 'selesai']) ? $faker->randomElement($relawans)->id : null;

            SOS::create([
                'id_pengguna'  => $id_pengguna,
                'id_relawan'   => $id_relawan,
                'latitude'     => $lat,
                'longitude'    => $lng,
                'status_sos'   => $status,
                'alasan_batal' => $status === 'batal' ? $faker->sentence() : null,
                'waktu_sos'    => Carbon::now()->subDays(rand(0, 14))->subHours(rand(0, 24)),
            ]);
        }
    }
}
