<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RelawanPreRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_pre_register_relawan_without_password(): void
    {
        $admin = User::create([
            'name'        => 'Super Admin',
            'role'        => 'superadmin',
            'is_active'   => true,
        ]);
        $adminToken = $admin->createToken('admin_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson('/api/admin/relawan', [
                'name'      => 'Andi Relawan',
                'email'     => 'andi.relawan@gmail.com',
                'no_telp'   => '081234567890',
                'alamat'    => 'Jl. Pahlawan No. 45',
                'pekerjaan' => 'Tim SAR',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.role', 'relawan')
            ->assertJsonPath('data.status_verifikasi', 'terverifikasi');

        $this->assertDatabaseHas('users', [
            'name'              => 'Andi Relawan',
            'role'              => 'relawan',
            'status_verifikasi' => 'terverifikasi',
            'is_active'         => true,
        ]);

        $this->assertDatabaseHas('accounts', [
            'email'       => 'andi.relawan@gmail.com',
            'provider'    => 'google',
            'provider_id' => null,
            'password'    => null,
        ]);
    }

    public function test_pre_registered_relawan_logs_in_via_google_and_links_account(): void
    {
        // 1. Admin pre-registers relawan
        $user = User::create([
            'name'              => 'Siti Relawan',
            'role'              => 'relawan',
            'no_telp'           => '081298765432',
            'alamat'            => 'Jl. Gatot Subroto No. 10',
            'status_verifikasi' => 'terverifikasi',
            'is_active'         => true,
        ]);

        Account::create([
            'user_id'     => $user->id,
            'provider'    => 'google',
            'provider_id' => null,
            'email'       => 'siti.relawan@gmail.com',
            'password'    => null,
        ]);

        // 2. Mock Google Token Info
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'sub'     => 'google-uid-99999',
                'email'   => 'siti.relawan@gmail.com',
                'name'    => 'Siti Relawan Asli',
                'picture' => 'https://lh3.googleusercontent.com/photo.jpg',
            ], 200),
        ]);

        // 3. Relawan logs in via Google Mobile
        $loginResponse = $this->postJson('/api/auth/google/mobile', [
            'id_token' => 'valid-mock-token',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonPath('is_profile_complete', true)
            ->assertJsonPath('status_verifikasi', 'terverifikasi')
            ->assertJsonPath('user.role', 'relawan')
            ->assertJsonPath('user.id', $user->id);

        // Pastikan provider_id Google tertaut
        $this->assertDatabaseHas('accounts', [
            'email'       => 'siti.relawan@gmail.com',
            'provider'    => 'google',
            'provider_id' => 'google-uid-99999',
        ]);
    }

    public function test_cannot_pre_register_relawan_with_duplicate_email(): void
    {
        $admin = User::create([
            'name'      => 'Super Admin',
            'role'      => 'superadmin',
            'is_active' => true,
        ]);
        $adminToken = $admin->createToken('admin_token')->plainTextToken;

        // Buat akun pertama
        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson('/api/admin/relawan', [
                'name'    => 'Relawan 1',
                'email'   => 'sama@gmail.com',
                'no_telp' => '0811111111',
                'alamat'  => 'Jl. Satu',
            ])->assertStatus(201);

        // Coba buat akun kedua dengan email yang sama
        $dupResponse = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson('/api/admin/relawan', [
                'name'    => 'Relawan 2',
                'email'   => 'sama@gmail.com',
                'no_telp' => '0822222222',
                'alamat'  => 'Jl. Dua',
            ]);

        $dupResponse->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_new_unregistered_email_can_sign_in_via_google(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'sub'     => 'google-brand-new-user-12345',
                'email'   => 'pengguna.baru@gmail.com',
                'name'    => 'Pengguna Baru',
                'picture' => 'https://lh3.googleusercontent.com/avatar.jpg',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/google/mobile', [
            'id_token' => 'valid-mock-token',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('user.is_active', true)
            ->assertJsonPath('user.role', 'pengguna');

        $this->assertDatabaseHas('users', [
            'name'      => 'Pengguna Baru',
            'role'      => 'pengguna',
            'is_active' => true,
        ]);
    }
}
