<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HubungiWhatsAppController;
use App\Models\Kontak_darurat;
use App\Models\SOS;
use App\Models\SOSActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HubungiWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $name, string $role, ?string $phone = null): User
    {
        static $counter = 10;
        $phoneNumber = $phone ?? ('0812345678' . str_pad((string) $counter++, 2, '0', STR_PAD_LEFT));

        return User::create([
            'name'                => $name,
            'role'                => $role,
            'no_telp'             => $phoneNumber,
            'alamat'              => 'Jl. Contoh Alamat No. 12',
            'is_active'           => true,
            'status_verifikasi'   => 'terverifikasi',
            'persetujuan_privasi' => true,
        ]);
    }

    /**
     * TASK-2.3: Uji Format Nomor WhatsApp Internasional (+62 / 62)
     */
    public function test_format_whatsapp_number_converts_to_international_standard(): void
    {
        // 08xx -> 628xx
        $this->assertEquals('628123456789', HubungiWhatsAppController::formatWhatsAppNumber('08123456789'));

        // +628xx -> 628xx
        $this->assertEquals('628123456789', HubungiWhatsAppController::formatWhatsAppNumber('+628123456789'));

        // Format dengan spasi dan strip (+62 812-3456-789) -> 628123456789
        $this->assertEquals('628123456789', HubungiWhatsAppController::formatWhatsAppNumber('+62 812-3456-789'));

        // Format awalan 8xx tanpa 0 -> 628xx
        $this->assertEquals('628123456789', HubungiWhatsAppController::formatWhatsAppNumber('8123456789'));

        // Format 6208xx -> 628xx
        $this->assertEquals('628123456789', HubungiWhatsAppController::formatWhatsAppNumber('6208123456789'));

        // Format null / string kosong / non-angka
        $this->assertNull(HubungiWhatsAppController::formatWhatsAppNumber(null));
        $this->assertNull(HubungiWhatsAppController::formatWhatsAppNumber(''));
        $this->assertNull(HubungiWhatsAppController::formatWhatsAppNumber('abc-def'));
    }

    /**
     * TASK-2.3: Uji Draft Pesan Otomatis WhatsApp untuk Kontak Darurat Keluarga
     */
    public function test_generate_whatsapp_message_for_emergency_contact(): void
    {
        $pengguna = $this->createUser('Budi Korban', 'pengguna');
        $pengguna->update([
            'kategori_user' => 'tunarungu',
            'catatan_medis' => 'Tunarungu, gunakan komunikasi teks',
        ]);

        $kontak = Kontak_darurat::create([
            'id_pengguna'  => $pengguna->id,
            'nama'         => 'Ayah Kandung',
            'no_telp'      => '081299998888',
            'pesan'        => 'Tolong segera datang jika ada bahaya',
            'terima_notif' => true,
            'tipe'         => 'utama',
        ]);

        $sos = SOS::create([
            'id_pengguna' => $pengguna->id,
            'latitude'    => -7.7956,
            'longitude'   => 110.3695,
            'status_sos'  => 'belum ditangani',
            'nomor_kasus' => 'SOS-999',
            'waktu_sos'   => now(),
        ]);

        $pesan = HubungiWhatsAppController::generateWhatsAppMessage($sos, 'sos', $pengguna, $kontak);

        // Pastikan draft pesan darurat terisi komponen penting
        $this->assertStringContainsString('PEMBERITAHUAN DARURAT - SAHABAT SOS', $pesan);
        $this->assertStringContainsString('Halo Bapak/Ibu *Ayah Kandung* *(Kontak Darurat Utama)*', $pesan);
        $this->assertStringContainsString('Budi Korban', $pesan);
        $this->assertStringContainsString('Tunarungu', $pesan);
        $this->assertStringContainsString('Tunarungu, gunakan komunikasi teks', $pesan);
        $this->assertStringContainsString('SOS-999', $pesan);
        $this->assertStringContainsString('https://www.google.com/maps?q=-7.7956,110.3695', $pesan);
        $this->assertStringContainsString('Tolong segera datang jika ada bahaya', $pesan);
    }

    /**
     * TASK-2.3: Admin dapat memicu tautan WhatsApp kontak darurat keluarga
     */
    public function test_admin_can_call_hubungi_kontak_darurat_endpoint(): void
    {
        $admin = $this->createUser('Admin Posko', 'admin');
        $admin->update(['permissions' => ['kelola_laporan']]);

        $pengguna = $this->createUser('Dewi', 'pengguna');
        $kontak = Kontak_darurat::create([
            'id_pengguna'  => $pengguna->id,
            'nama'         => 'Ibu Dewi',
            'no_telp'      => '081555556666',
            'terima_notif' => true,
            'tipe'         => 'utama',
        ]);

        $sos = SOS::create([
            'id_pengguna' => $pengguna->id,
            'latitude'    => -7.7956,
            'longitude'   => 110.3695,
            'status_sos'  => 'belum ditangani',
            'nomor_kasus' => 'SOS-100',
        ]);

        $token = $admin->createToken('admin_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/tugas-aktif/sos/{$sos->id}/hubungi-kontak-darurat");

        $response->assertStatus(200)
            ->assertJsonPath('data.kasus_id', $sos->id)
            ->assertJsonPath('data.target_kontak.nama', 'Ibu Dewi')
            ->assertJsonPath('data.target_kontak.no_telp_wa', '6281555556666');

        $waUrl = $response->json('data.whatsapp_url');
        $this->assertStringStartsWith('https://wa.me/6281555556666?text=', $waUrl);

        // Pastikan SOSActivity tercatat
        $this->assertDatabaseHas('s_o_s_activities', [
            'sos_id' => $sos->id,
            'action' => 'kontak_darurat_wa',
        ]);
    }

    /**
     * TASK-2.3: Relawan penanggung jawab kasus dapat memicu tautan WhatsApp kontak darurat
     */
    public function test_assigned_relawan_can_call_hubungi_kontak_darurat(): void
    {
        $relawan = $this->createUser('Rian Relawan', 'relawan');
        $pengguna = $this->createUser('Dewi', 'pengguna');

        $kontak = Kontak_darurat::create([
            'id_pengguna'  => $pengguna->id,
            'nama'         => 'Kakak Dewi',
            'no_telp'      => '08177778888',
            'terima_notif' => true,
            'tipe'         => 'utama',
        ]);

        $sos = SOS::create([
            'id_pengguna' => $pengguna->id,
            'id_relawan'  => $relawan->id,
            'latitude'    => -7.7956,
            'longitude'   => 110.3695,
            'status_sos'  => 'ditangani',
            'nomor_kasus' => 'SOS-101',
        ]);

        $token = $relawan->createToken('relawan_token')->plainTextToken;

        // Relawan memanggil endpoint dari mobile (/api/tugas-aktif/sos/{id}/hubungi-kontak-darurat)
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/tugas-aktif/sos/{$sos->id}/hubungi-kontak-darurat");

        $response->assertStatus(200)
            ->assertJsonPath('data.target_kontak.no_telp_wa', '628177778888');

        $this->assertStringStartsWith('https://wa.me/628177778888?text=', $response->json('data.whatsapp_url'));

        // Cek log aktivitas mencantumkan peran Relawan
        $lastActivity = SOSActivity::where('sos_id', $sos->id)->latest('id')->first();
        $this->assertNotNull($lastActivity);
        $this->assertStringContainsString('Relawan Rian Relawan', $lastActivity->description);
    }

    /**
     * Keamanan Otorisasi: Relawan yang TIDAK ditugaskan pada kasus tersebut dilarang menghubungi kontak darurat
     */
    public function test_unassigned_relawan_cannot_call_hubungi_kontak_darurat(): void
    {
        $relawanBertugas = $this->createUser('Relawan 1', 'relawan');
        $relawanLain = $this->createUser('Relawan 2', 'relawan');
        $pengguna = $this->createUser('Korban', 'pengguna');

        Kontak_darurat::create([
            'id_pengguna'  => $pengguna->id,
            'nama'         => 'Ibu Korban',
            'no_telp'      => '081888889999',
            'terima_notif' => true,
            'tipe'         => 'utama',
        ]);

        $sos = SOS::create([
            'id_pengguna' => $pengguna->id,
            'id_relawan'  => $relawanBertugas->id,
            'latitude'    => -7.7956,
            'longitude'   => 110.3695,
            'status_sos'  => 'ditangani',
        ]);

        $tokenLain = $relawanLain->createToken('relawan_lain_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$tokenLain}")
            ->postJson("/api/tugas-aktif/sos/{$sos->id}/hubungi-kontak-darurat");

        $response->assertStatus(403);
    }
}
