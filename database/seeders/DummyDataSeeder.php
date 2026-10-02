<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DummyDataSeeder extends Seeder
{
    public function run(): void
    {
        // Panggil seeder modular untuk pengguna/relawan, laporan dengan berbagai status, dan SOS
        $this->call([
            UserSeeder::class,
            LaporanSeeder::class,
            SosSeeder::class,
        ]);
    }
}
