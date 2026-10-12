<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelawanVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_relawan_can_access_profile_but_blocked_from_operational_routes(): void
    {
        $relawan = User::create([
            'name'              => 'Budi Relawan',
            'role'              => 'relawan',
            'no_telp'           => '081234567890',
            'alamat'            => 'Jl. Merdeka No. 1',
            'status_verifikasi' => 'pending',
            'is_active'         => true,
        ]);

        Account::create([
            'user_id'  => $relawan->id,
            'provider' => 'google',
            'email'    => 'budi@gmail.com',
        ]);

        $token = $relawan->createToken('test_token')->plainTextToken;

        // 1. Profil harus bisa diakses
        $profileResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/relawan/profile');
        $profileResponse->assertStatus(200)
            ->assertJsonPath('status_verifikasi', 'pending')
            ->assertJsonPath('user.email', 'budi@gmail.com');

        // 2. Beranda harus diblokir (403)
        $berandaResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/relawan/beranda');
        $berandaResponse->assertStatus(403)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('status_verifikasi', 'pending');

        // 3. Tugas harus diblokir (403)
        $tugasResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/relawan/tugas');
        $tugasResponse->assertStatus(403);
    }

    public function test_admin_can_view_pending_and_verify_relawan(): void
    {
        $admin = User::create([
            'name'        => 'Admin Utama',
            'role'        => 'admin',
            'permissions' => ['verifikasi_relawan'],
        ]);

        $adminToken = $admin->createToken('admin_token')->plainTextToken;

        $pendingRelawan = User::create([
            'name'              => 'Siti Relawan',
            'role'              => 'relawan',
            'no_telp'           => '089876543210',
            'alamat'            => 'Jl. Sudirman No. 5',
            'pekerjaan'         => 'Paramedis',
            'alasan_relawan'    => 'Ingin membantu sesama',
            'status_verifikasi' => 'pending',
            'is_active'         => true,
        ]);

        Account::create([
            'user_id'  => $pendingRelawan->id,
            'provider' => 'google',
            'email'    => 'siti@gmail.com',
        ]);

        // 1. Admin melihat pending list
        $pendingList = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/admin/relawan/pending');
        $pendingList->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Siti Relawan');

        // 2. Admin menyetujui verifikasi relawan
        $verifyResponse = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->putJson("/api/admin/relawan/{$pendingRelawan->id}/verifikasi", [
                'status_verifikasi' => 'terverifikasi',
            ]);
        $verifyResponse->assertStatus(200)
            ->assertJsonPath('user.status_verifikasi', 'terverifikasi');

        $pendingRelawan->refresh();
        $this->assertEquals('terverifikasi', $pendingRelawan->status_verifikasi);
        $this->assertTrue((bool) $pendingRelawan->is_active);

        // 3. Sekarang relawan bisa mengakses beranda operasional
        auth()->forgetGuards();
        $relawanToken = $pendingRelawan->createToken('relawan_token')->plainTextToken;
        $berandaResponse = $this->withHeader('Authorization', "Bearer {$relawanToken}")
            ->getJson('/api/relawan/beranda');
        $berandaResponse->assertStatus(200);
    }

    public function test_complete_profile_as_relawan_sets_status_pending(): void
    {
        $user = User::create([
            'name'              => 'Calon Relawan Google',
            'role'              => 'pengguna',
            'status_verifikasi' => 'terverifikasi',
            'is_active'         => true,
        ]);

        $token = $user->createToken('token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/user/complete-profile', [
                'alamat'         => 'Jl. Pahlawan No. 10',
                'no_telp'        => '081299988877',
                'role'           => 'relawan',
                'pekerjaan'      => 'Relawan PMI',
                'alasan_relawan' => 'Panggilan hati untuk melayani',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status_verifikasi', 'pending')
            ->assertJsonPath('user.role', 'relawan')
            ->assertJsonPath('user.status_verifikasi', 'pending');

        $user->refresh();
        $this->assertEquals('pending', $user->status_verifikasi);
        $this->assertEquals('relawan', $user->role);
    }

    public function test_deactivated_relawan_can_access_profile_but_blocked_from_operational_routes(): void
    {
        $relawan = User::create([
            'name'              => 'Doni Relawan',
            'role'              => 'relawan',
            'no_telp'           => '081233344455',
            'alamat'            => 'Jl. Diponegoro No. 12',
            'status_verifikasi' => 'terverifikasi',
            'is_active'         => false, // Dinonaktifkan oleh admin
        ]);

        Account::create([
            'user_id'  => $relawan->id,
            'provider' => 'google',
            'email'    => 'doni@gmail.com',
        ]);

        $token = $relawan->createToken('doni_token')->plainTextToken;

        // 1. Profil harus tetap bisa diakses untuk melihat status akun dinonaktifkan
        $profileResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/relawan/profile');
        $profileResponse->assertStatus(200)
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('status_verifikasi', 'terverifikasi');

        // 1b. Endpoint /api/user/me juga harus bisa diakses untuk sinkronisasi role saat login
        $meResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user/me');
        $meResponse->assertStatus(200)
            ->assertJsonPath('user.role', 'relawan')
            ->assertJsonPath('user.is_active', false);

        // 2. Beranda harus diblokir (403)
        $berandaResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/relawan/beranda');
        $berandaResponse->assertStatus(403)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('is_active', false);

        // 3. Superadmin mengaktifkan kembali akun relawan
        $admin = User::create([
            'name'      => 'Super Admin',
            'role'      => 'superadmin',
            'is_active' => true,
        ]);
        $adminToken = $admin->createToken('superadmin_token')->plainTextToken;

        auth()->forgetGuards();
        $activateResponse = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->putJson("/api/superadmin/relawan/{$relawan->id}/updateStatus", [
                'is_active' => true,
            ]);
        $activateResponse->assertStatus(200)
            ->assertJsonPath('data.is_active', true);

        // 4. Setelah diaktifkan, relawan langsung bisa mengakses beranda lagi
        auth()->forgetGuards();
        $berandaResponseAfter = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/relawan/beranda');
        $berandaResponseAfter->assertStatus(200);
    }
}
