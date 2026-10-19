<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSmartwatch;
use App\Models\SOS;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartwatchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_pair_and_list_smartwatches(): void
    {
        $user = User::factory()->create([
            'role'      => 'pengguna',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum');

        // 1. Pair smartwatch
        $response = $this->postJson('/api/pengguna/smartwatch', [
            'device_identifier'  => 'hw_watch_gt4_001',
            'brand'              => 'huawei',
            'device_model'       => 'Huawei Watch GT 4',
            'mac_address'        => 'AA:BB:CC:DD:EE:FF',
            'last_battery_level' => 88,
            'last_heart_rate'    => 75,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'device_identifier' => 'hw_watch_gt4_001',
                    'brand'             => 'huawei',
                    'device_model'      => 'Huawei Watch GT 4',
                ],
            ]);

        $this->assertDatabaseHas('user_smartwatches', [
            'id_pengguna'        => $user->id,
            'device_identifier'  => 'hw_watch_gt4_001',
        ]);

        // 2. List smartwatches
        $listResponse = $this->getJson('/api/pengguna/smartwatch');
        $listResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(1, 'data');
    }

    public function test_user_can_sync_smartwatch_telemetry(): void
    {
        $user = User::factory()->create([
            'role'      => 'pengguna',
            'is_active' => true,
        ]);

        $smartwatch = UserSmartwatch::create([
            'id_pengguna'        => $user->id,
            'device_identifier'  => 'ble_watch_002',
            'brand'              => 'generic_ble',
            'device_model'       => 'Smart Band 8',
            'is_active'          => true,
        ]);

        $this->actingAs($user, 'sanctum');

        $response = $this->postJson("/api/pengguna/smartwatch/{$smartwatch->id}/sync-telemetry", [
            'battery_level' => 45,
            'heart_rate'    => 82,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'last_battery_level' => 45,
                    'last_heart_rate'    => 82,
                ],
            ]);

        $this->assertDatabaseHas('user_smartwatches', [
            'id'                 => $smartwatch->id,
            'last_battery_level' => 45,
            'last_heart_rate'    => 82,
        ]);
    }

    public function test_sos_trigger_stores_smartwatch_telemetry(): void
    {
        $user = User::factory()->create([
            'role'      => 'pengguna',
            'is_active' => true,
            'latitude'  => -6.200000,
            'longitude' => 106.816666,
        ]);

        $this->actingAs($user, 'sanctum');

        $payload = [
            'latitude'       => -6.200000,
            'longitude'      => 106.816666,
            'trigger_source' => 'smartwatch_button',
            'device_info'    => [
                'mobile' => [
                    'brand'         => 'Samsung',
                    'model'         => 'Galaxy S23',
                    'battery_level' => 90,
                ],
                'smartwatch' => [
                    'device_id'     => 'hw_gt4_999',
                    'device_name'   => 'Huawei Watch GT 4',
                    'brand'         => 'huawei',
                    'battery_level' => 70,
                    'heart_rate'    => 105,
                    'fall_detected' => false,
                    'trigger_type'  => 'Tombol SOS Jam',
                ],
            ],
        ];

        $response = $this->postJson('/api/sos/trigger', $payload);

        $response->assertStatus(201);

        $sos = SOS::where('id_pengguna', $user->id)->first();
        $this->assertNotNull($sos);
        $this->assertEquals('smartwatch_button', $sos->device_info['trigger_source']);
        $this->assertNotNull($sos->device_info['smartwatch']);
        $this->assertEquals(105, $sos->device_info['smartwatch']['heart_rate']);
        $this->assertEquals('huawei', $sos->device_info['smartwatch']['brand']);
    }
}
