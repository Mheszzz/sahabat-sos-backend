<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
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
    }
}
