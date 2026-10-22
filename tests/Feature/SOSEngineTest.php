<?php

namespace Tests\Feature;

use App\Events\SOSCreated;
use App\Events\SOSUpdateStatus;
use App\Models\SOS;
use App\Models\SOSActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SOSEngineTest extends TestCase
{
    use RefreshDatabase;

    private function createPengguna(array $attributes = []): User
    {
        static $counter = 100;
        $phone = '0812345678' . str_pad((string) $counter++, 3, '0', STR_PAD_LEFT);

        return User::create(array_merge([
            'role'                => 'pengguna',
            'name'                => 'Budi Santoso',
            'no_telp'             => $phone,
            'kategori_user'       => 'tunanetra',
            'latitude'            => -7.7828,
            'longitude'           => 110.3670,
            'is_active'           => true,
            'status_verifikasi'   => 'terverifikasi',
            'persetujuan_privasi' => true,
        ], $attributes));
    }

    private function createRelawan(array $attributes = []): User
    {
        static $counter = 200;
        $phone = '0812999988' . str_pad((string) $counter++, 3, '0', STR_PAD_LEFT);

        return User::create(array_merge([
            'role'                => 'relawan',
            'name'                => 'Rian Hidayat',
            'no_telp'             => $phone,
            'status_ketersediaan' => 'tersedia',
            'latitude'            => -7.7830,
            'longitude'           => 110.3680,
            'is_active'           => true,
            'status_verifikasi'   => 'terverifikasi',
            'persetujuan_privasi' => true,
        ], $attributes));
    }

    // =========================================================================
    // TASK-4.1: Picu Tombol SOS Utama di Mobile App
    // =========================================================================

    public function test_pengguna_can_trigger_sos_with_coordinates_and_telemetry(): void
    {
        Event::fake([SOSCreated::class]);

        $pengguna = $this->createPengguna();
        $this->createRelawan(); // Relawan dalam radius 2 km

        $payload = [
            'latitude'        => -7.7925,
            'longitude'       => 110.3658,
            'trigger_source'  => 'app_button',
            'battery_level'   => 88,
            'signal_strength' => '4G',
            'device_info'     => [
                'mobile' => [
                    'brand'         => 'Samsung',
                    'model'         => 'Galaxy A54',
                    'battery_level' => 88,
                ]
            ],
        ];

        $response = $this->actingAs($pengguna)
            ->postJson('/api/sos/trigger', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Sinyal SOS berhasil dikirim.')
            ->assertJsonPath('data.status_sos', 'belum ditangani')
            ->assertJsonPath('data.latitude', -7.7925)
            ->assertJsonPath('data.longitude', 110.3658);

        $sosId = $response->json('data.id');

        // Verifikasi tersimpan di database
        $this->assertDatabaseHas('s_o_s', [
            'id'          => $sosId,
            'id_pengguna' => $pengguna->id,
            'status_sos'  => 'belum ditangani',
        ]);

        // Verifikasi log aktivitas tercatat
        $this->assertDatabaseHas('s_o_s_activities', [
            'sos_id'  => $sosId,
            'action'  => 'sos_dipicu',
            'user_id' => $pengguna->id,
        ]);

        // Verifikasi event WebSocket SOSCreated dipancarkan
        Event::assertDispatched(SOSCreated::class, function ($event) use ($sosId) {
            return $event->sos->id === $sosId;
        });
    }

    public function test_pengguna_can_also_trigger_sos_via_sos_route_alias(): void
    {
        Event::fake([SOSCreated::class]);

        $pengguna = $this->createPengguna();

        $payload = [
            'latitude'        => -7.7950,
            'longitude'       => 110.3660,
            'trigger_source'  => 'app_button',
            'battery_level'   => 90,
            'signal_strength' => '5G',
        ];

        // Memanggil route alias /api/sos
        $response = $this->actingAs($pengguna)
            ->postJson('/api/sos', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Sinyal SOS berhasil dikirim.');
    }

    public function test_pengguna_cannot_trigger_multiple_active_sos(): void
    {
        $pengguna = $this->createPengguna();

        // Buat sinyal SOS yang masih aktif
        SOS::create([
            'id_pengguna'     => $pengguna->id,
            'latitude'        => -7.7800,
            'longitude'       => 110.3700,
            'status_sos'      => 'belum ditangani',
            'waktu_sos'       => now(),
            'battery_level'   => 80,
            'signal_strength' => '4G',
        ]);

        // Mencoba memicu SOS lagi saat SOS sebelumnya belum selesai
        $payload = [
            'latitude'  => -7.7810,
            'longitude' => 110.3710,
        ];

        $response = $this->actingAs($pengguna)
            ->postJson('/api/sos/trigger', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Anda masih memiliki sinyal SOS aktif yang sedang diproses.');
    }

    // =========================================================================
    // TASK-4.2: Pembatalan Sinyal SOS (Slide to Cancel)
    // =========================================================================

    public function test_pengguna_can_cancel_active_sos_with_reason(): void
    {
        Event::fake([SOSUpdateStatus::class]);

        $pengguna = $this->createPengguna();

        $sos = SOS::create([
            'id_pengguna'     => $pengguna->id,
            'latitude'        => -7.7820,
            'longitude'       => 110.3675,
            'status_sos'      => 'belum ditangani',
            'waktu_sos'       => now(),
            'battery_level'   => 85,
            'signal_strength' => '4G',
        ]);

        $payload = [
            'alasan_batal' => 'Salah tekan tombol darurat (False Alarm)',
        ];

        $response = $this->actingAs($pengguna)
            ->postJson("/api/sos/{$sos->id}/cancel", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Sinyal SOS berhasil dibatalkan.')
            ->assertJsonPath('data.status_sos', 'batal')
            ->assertJsonPath('data.alasan_batal', 'Salah tekan tombol darurat (False Alarm)');

        // Verifikasi status di database berubah menjadi batal
        $this->assertDatabaseHas('s_o_s', [
            'id'           => $sos->id,
            'status_sos'   => 'batal',
            'alasan_batal' => 'Salah tekan tombol darurat (False Alarm)',
        ]);

        // Verifikasi tercatat di log aktivitas
        $this->assertDatabaseHas('s_o_s_activities', [
            'sos_id' => $sos->id,
            'action' => 'sos_dibatalkan',
        ]);

        // Verifikasi broadcast SOSUpdateStatus terkirim
        Event::assertDispatched(SOSUpdateStatus::class, function ($event) use ($sos) {
            return $event->sos->id === $sos->id && $event->sos->status_sos === 'batal';
        });
    }

    public function test_pengguna_cannot_cancel_other_user_sos(): void
    {
        $pengguna1 = $this->createPengguna(['name' => 'Budi']);
        $pengguna2 = $this->createPengguna(['name' => 'Siti']);

        $sosPengguna1 = SOS::create([
            'id_pengguna'     => $pengguna1->id,
            'latitude'        => -7.7820,
            'longitude'       => 110.3675,
            'status_sos'      => 'belum ditangani',
            'waktu_sos'       => now(),
            'battery_level'   => 85,
            'signal_strength' => '4G',
        ]);

        // Pengguna 2 mencoba membatalkan SOS milik Pengguna 1
        $response = $this->actingAs($pengguna2)
            ->postJson("/api/sos/{$sosPengguna1->id}/cancel", [
                'alasan_batal' => 'Mencoba batalkan punya orang lain',
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Sinyal SOS tidak ditemukan atau sudah tidak dapat dibatalkan.');

        // Status di DB tetap belum ditangani
        $this->assertEquals('belum ditangani', $sosPengguna1->fresh()->status_sos);
    }

    public function test_pengguna_cannot_cancel_already_resolved_sos(): void
    {
        $pengguna = $this->createPengguna();

        $sosSelesai = SOS::create([
            'id_pengguna'     => $pengguna->id,
            'latitude'        => -7.7820,
            'longitude'       => 110.3675,
            'status_sos'      => 'selesai',
            'waktu_sos'       => now()->subHours(1),
            'battery_level'   => 85,
            'signal_strength' => '4G',
        ]);

        $response = $this->actingAs($pengguna)
            ->postJson("/api/sos/{$sosSelesai->id}/cancel", [
                'alasan_batal' => 'Batalkan kasus yang sudah selesai',
            ]);

        $response->assertStatus(404);
    }

    // =========================================================================
    // TASK-4.3: Fallback GPS saat Satelit Tidak Terkunci
    // =========================================================================

    public function test_gps_fallback_when_coordinates_are_zero(): void
    {
        Event::fake([SOSCreated::class]);

        // Pengguna memiliki koordinat tersimpan di profil (-7.7828, 110.3670)
        $pengguna = $this->createPengguna([
            'latitude'  => -7.7828,
            'longitude' => 110.3670,
        ]);

        // Mobile gagal lock GPS dan mengirim 0.0, 0.0
        $payload = [
            'latitude'        => 0.0,
            'longitude'       => 0.0,
            'trigger_source'  => 'app_button',
            'battery_level'   => 75,
            'signal_strength' => '3G',
        ];

        $response = $this->actingAs($pengguna)
            ->postJson('/api/sos/trigger', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Sinyal SOS berhasil dikirim.')
            ->assertJsonPath('data.latitude', -7.7828)
            ->assertJsonPath('data.longitude', 110.3670)
            ->assertJsonPath('data.device_info.is_gps_fallback', true)
            ->assertJsonPath('data.device_info.gps_status', 'fallback_profile');

        $sosId = $response->json('data.id');

        // Verifikasi tersimpan di database dengan koordinat fallback dari profil
        $this->assertDatabaseHas('s_o_s', [
            'id'          => $sosId,
            'id_pengguna' => $pengguna->id,
            'latitude'    => -7.7828,
            'longitude'   => 110.3670,
        ]);

        // Verifikasi tercatat log aktivitas gps_fallback
        $this->assertDatabaseHas('s_o_s_activities', [
            'sos_id' => $sosId,
            'action' => 'gps_fallback',
        ]);
    }

    public function test_gps_fallback_when_coordinates_are_null_or_omitted(): void
    {
        Event::fake([SOSCreated::class]);

        $pengguna = $this->createPengguna([
            'latitude'  => -7.7828,
            'longitude' => 110.3670,
        ]);

        // Simulasikan izin lokasi dimatikan sehingga field latitude/longitude null
        $payload = [
            'latitude'        => null,
            'longitude'       => null,
            'trigger_source'  => 'app_button',
            'battery_level'   => 70,
            'signal_strength' => '4G',
        ];

        $response = $this->actingAs($pengguna)
            ->postJson('/api/sos/trigger', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.latitude', -7.7828)
            ->assertJsonPath('data.longitude', 110.3670)
            ->assertJsonPath('data.device_info.is_gps_fallback', true);

        $this->assertDatabaseHas('s_o_s', [
            'id_pengguna' => $pengguna->id,
            'latitude'    => -7.7828,
            'longitude'   => 110.3670,
        ]);
    }

    public function test_gps_fallback_to_previous_laporan_when_profile_coords_empty(): void
    {
        Event::fake([SOSCreated::class]);

        // Pengguna belum punya koordinat di profil
        $pengguna = $this->createPengguna([
            'latitude'  => null,
            'longitude' => null,
        ]);

        // Pengguna memiliki riwayat laporan dengan koordinat tersimpan
        \App\Models\Laporan::create([
            'id_pengguna'      => $pengguna->id,
            'kategori_laporan' => 'Medis',
            'deskripsi'        => 'Laporan sebelumnya',
            'lokasi_laporan'   => 'Jl. Malioboro',
            'latitude'         => -7.7915,
            'longitude'        => 110.3650,
            'status'           => 'selesai',
        ]);

        // Mobile gagal lock GPS mengirim 0.0, 0.0
        $response = $this->actingAs($pengguna)
            ->postJson('/api/sos/trigger', [
                'latitude'  => 0.0,
                'longitude' => 0.0,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.latitude', -7.7915)
            ->assertJsonPath('data.longitude', 110.3650)
            ->assertJsonPath('data.device_info.is_gps_fallback', true)
            ->assertJsonPath('data.device_info.gps_status', 'fallback_riwayat_laporan');
    }

    public function test_trigger_fails_when_coords_empty_and_user_has_no_profile_coords(): void
    {
        // Pengguna baru yang belum memiliki koordinat profil maupun riwayat apapun
        $penggunaTanpaLokasi = $this->createPengguna([
            'latitude'  => null,
            'longitude' => null,
        ]);

        // Jika request mengirim 0.0, 0.0 saat profil kosong, sistem menolak dan TIDAK menyimpan titik palsu 0.0, 0.0
        $responseZero = $this->actingAs($penggunaTanpaLokasi)
            ->postJson('/api/sos/trigger', [
                'latitude'  => 0.0,
                'longitude' => 0.0,
            ]);

        $responseZero->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude']);

        // Jika request mengirim null saat profil kosong
        $responseNull = $this->actingAs($penggunaTanpaLokasi)
            ->postJson('/api/sos/trigger', [
                'latitude'  => null,
                'longitude' => null,
            ]);

        $responseNull->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }
}
