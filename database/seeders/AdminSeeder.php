<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Seed Superadmin Account
        $superadminAccount = Account::where('email', 'superadmin@sahabatsos.com')->where('provider', 'local')->first();
        if (!$superadminAccount) {
            $superadmin = User::create([
                'name'    => 'Super Admin',
                'role'    => 'superadmin',
                'alamat'  => 'Kantor Pusat Sahabat SOS',
                'no_telp' => '081234567891',
            ]);

            Account::create([
                'user_id'  => $superadmin->id,
                'provider' => 'local',
                'email'    => 'superadmin@sahabatsos.com',
                'password' => Hash::make('password123'),
            ]);
        } else if ($superadminAccount->user && empty($superadminAccount->user->no_telp)) {
            $superadminAccount->user->update([
                'no_telp' => '081234567891',
                'alamat'  => 'Kantor Pusat Sahabat SOS',
            ]);
        }

        // 2. Seed Admin Account
        $adminAccount = Account::where('email', 'admin@sahabatsos.com')->where('provider', 'local')->first();
        if (!$adminAccount) {
            $admin = User::create([
                'name'                   => 'Admin Utama',
                'role'                   => 'admin',
                'alamat'                 => 'Kantor Pusat Sahabat SOS',
                'no_telp'                => '081234567890',
                'permissions'            => [
                    'kelola_laporan',
                    'pantau_peta',
                    'kelola_relawan',
                    'verifikasi_relawan',
                    'broadcast_sirene',
                    'kelola_admin',
                ],
                'permissions_granted_at' => now(),
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
