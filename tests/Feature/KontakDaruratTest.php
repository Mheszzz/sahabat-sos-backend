<?php

namespace Tests\Feature;

use App\Models\Kontak_darurat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KontakDaruratTest extends TestCase
{
    use RefreshDatabase;

    private function createPengguna(string $name = 'Budi Santoso', ?string $phone = null): User
    {
        static $counter = 1;
        $phoneNumber = $phone ?? ('0812345678' . str_pad((string) $counter++, 2, '0', STR_PAD_LEFT));

        return User::create([
            'name'                => $name,
            'role'                => 'pengguna',
            'no_telp'             => $phoneNumber,
            'alamat'              => 'Jl. Malioboro No. 10',
            'is_active'           => true,
            'status_verifikasi'   => 'terverifikasi',
            'persetujuan_privasi' => true,
        ]);
    }

    /**
     * TASK-2.1: Tambah Kontak Darurat Baru dengan toggle notifikasi darurat aktif
     */
    public function test_pengguna_can_add_kontak_darurat_with_toggle_notif_active(): void
    {
        $pengguna = $this->createPengguna();
        $token = $pengguna->createToken('test_token')->plainTextToken;

        $payload = [
            'nama'         => 'Ayah',
            'no_telp'      => '08123456789',
            'pesan'        => 'Keluarga Inti',
            'terima_notif' => true,
            'tipe'         => 'utama',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/pengguna/kontak-darurat', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Kontak darurat berhasil ditambahkan')
            ->assertJsonPath('data.nama', 'Ayah')
            ->assertJsonPath('data.no_telp', '08123456789')
            ->assertJsonPath('data.terima_notif', true)
            ->assertJsonPath('data.tipe', 'utama')
            ->assertJsonPath('data.no_telp_wa', '628123456789')
            ->assertJsonPath('data.is_utama', true);

        $this->assertDatabaseHas('kontak_darurats', [
            'id_pengguna'  => $pengguna->id,
            'nama'         => 'Ayah',
            'no_telp'      => '08123456789',
            'terima_notif' => true,
            'tipe'         => 'utama',
        ]);
    }

    /**
     * TASK-2.1 (Alias Compatibility): Input alias nama_kontak, nomor_telepon, dan hubungan
     */
    public function test_pengguna_can_add_kontak_using_postman_aliases(): void
    {
        $pengguna = $this->createPengguna();
        $token = $pengguna->createToken('test_token')->plainTextToken;

        $payload = [
            'nama_kontak'   => 'Istri Budi',
            'nomor_telepon' => '081999888777',
            'hubungan'      => 'Istri',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/pengguna/kontak-darurat', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.nama', 'Istri Budi')
            ->assertJsonPath('data.no_telp', '081999888777')
            ->assertJsonPath('data.terima_notif', true)
            ->assertJsonPath('data.tipe', 'utama'); // Kontak pertama otomatis menjadi utama
    }

    /**
     * TASK-2.1: Demote kontak utama sebelumnya jika menambahkan kontak utama baru
     */
    public function test_adding_new_utama_demotes_previous_utama_contact(): void
    {
        $pengguna = $this->createPengguna();
        $token = $pengguna->createToken('test_token')->plainTextToken;

        // Kontak 1 (Utama)
        $kontak1 = Kontak_darurat::create([
            'id_pengguna'  => $pengguna->id,
            'nama'         => 'Ayah',
            'no_telp'      => '08123456789',
            'terima_notif' => true,
            'tipe'         => 'utama',
        ]);

        // Kontak 2 (Baru diset Utama)
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/pengguna/kontak-darurat', [
                'nama'         => 'Ibu',
                'no_telp'      => '08129876543',
                'terima_notif' => true,
                'tipe'         => 'utama',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.tipe', 'utama');

        $this->assertEquals('sekunder', $kontak1->fresh()->tipe);
    }

    /**
     * TASK-2.2: Edit Kontak Darurat
     */
    public function test_pengguna_can_update_kontak_darurat(): void
    {
        $pengguna = $this->createPengguna();
        $token = $pengguna->createToken('test_token')->plainTextToken;

        $kontak = Kontak_darurat::create([
            'id_pengguna'  => $pengguna->id,
            'nama'         => 'Ayah',
            'no_telp'      => '08123456789',
            'terima_notif' => true,
            'tipe'         => 'utama',
        ]);

        $updatePayload = [
            'nama'    => 'Ayah Kandung',
            'no_telp' => '081200001111',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/pengguna/kontak-darurat/{$kontak->id}", $updatePayload);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Kontak darurat berhasil diperbarui')
            ->assertJsonPath('data.nama', 'Ayah Kandung')
            ->assertJsonPath('data.no_telp', '081200001111')
            ->assertJsonPath('data.no_telp_wa', '6281200001111');

        $this->assertDatabaseHas('kontak_darurats', [
            'id'      => $kontak->id,
            'nama'    => 'Ayah Kandung',
            'no_telp' => '081200001111',
        ]);
    }

    /**
     * TASK-2.2: Hapus Kontak Darurat
     */
    public function test_pengguna_can_delete_kontak_darurat(): void
    {
        $pengguna = $this->createPengguna();
        $token = $pengguna->createToken('test_token')->plainTextToken;

        $kontak = Kontak_darurat::create([
            'id_pengguna'  => $pengguna->id,
            'nama'         => 'Ayah Kandung',
            'no_telp'      => '08123456789',
            'terima_notif' => true,
            'tipe'         => 'utama',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/pengguna/kontak-darurat/{$kontak->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Kontak darurat berhasil dihapus');

        $this->assertDatabaseMissing('kontak_darurats', [
            'id' => $kontak->id,
        ]);
    }

    /**
     * TASK-2.2: Menghapus kontak utama mempromosikan kontak sekunder berikutnya menjadi utama
     */
    public function test_deleting_utama_promotes_next_contact_to_utama(): void
    {
        $pengguna = $this->createPengguna();
        $token = $pengguna->createToken('test_token')->plainTextToken;

        $kontak1 = Kontak_darurat::create([
            'id_pengguna'  => $pengguna->id,
            'nama'         => 'Kontak Pertama Utama',
            'no_telp'      => '081111111111',
            'terima_notif' => true,
            'tipe'         => 'utama',
        ]);

        $kontak2 = Kontak_darurat::create([
            'id_pengguna'  => $pengguna->id,
            'nama'         => 'Kontak Kedua Sekunder',
            'no_telp'      => '082222222222',
            'terima_notif' => true,
            'tipe'         => 'sekunder',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/pengguna/kontak-darurat/{$kontak1->id}")
            ->assertStatus(200);

        $this->assertEquals('utama', $kontak2->fresh()->tipe);
    }

    /**
     * TASK-2.1 / Toggle: Toggle Notifikasi Kontak Darurat
     */
    public function test_pengguna_can_toggle_notifikasi(): void
    {
        $pengguna = $this->createPengguna();
        $token = $pengguna->createToken('test_token')->plainTextToken;

        $kontak = Kontak_darurat::create([
            'id_pengguna'  => $pengguna->id,
            'nama'         => 'Kakak',
            'no_telp'      => '081333334444',
            'terima_notif' => true,
            'tipe'         => 'sekunder',
        ]);

        // Toggle dari true ke false tanpa body
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/pengguna/kontak-darurat/{$kontak->id}/toggle-notif");

        $response->assertStatus(200)
            ->assertJsonPath('data.terima_notif', false);

        // Toggle kembali dengan body eksplisit true
        $response2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/pengguna/kontak-darurat/{$kontak->id}/toggle-notif", [
                'terima_notif' => true,
            ]);

        $response2->assertStatus(200)
            ->assertJsonPath('data.terima_notif', true);
    }

    /**
     * Keamanan Otorisasi: Pengguna lain tidak boleh mengedit/menghapus kontak milik orang lain
     */
    public function test_pengguna_cannot_modify_other_user_contact(): void
    {
        $userA = $this->createPengguna('User A');
        $userB = $this->createPengguna('User B');

        $kontakA = Kontak_darurat::create([
            'id_pengguna'  => $userA->id,
            'nama'         => 'Kontak User A',
            'no_telp'      => '081122334455',
            'terima_notif' => true,
            'tipe'         => 'utama',
        ]);

        $tokenB = $userB->createToken('token_b')->plainTextToken;

        // User B mencoba update kontak User A
        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->putJson("/api/pengguna/kontak-darurat/{$kontakA->id}", [
                'nama' => 'Hacked',
            ])
            ->assertStatus(404);

        // User B mencoba delete kontak User A
        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->deleteJson("/api/pengguna/kontak-darurat/{$kontakA->id}")
            ->assertStatus(404);
    }
}
