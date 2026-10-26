<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSmartwatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SmartwatchController extends Controller
{
    /**
     * Menampilkan daftar smartwatch milik pengguna
     */
    public function index(Request $request)
    {
        $smartwatches = $request->user()
            ->smartwatches()
            ->orderBy('is_active', 'desc')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $smartwatches,
        ]);
    }

    /**
     * Mendaftarkan atau menyambungkan smartwatch baru ke akun pengguna
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_identifier'  => 'required|string|max:100',
            'brand'              => 'nullable|string|max:50',
            'device_model'       => 'nullable|string|max:100',
            'mac_address'        => 'nullable|string|max:50',
            'last_battery_level' => 'nullable|integer|between:0,100',
            'last_heart_rate'    => 'nullable|integer|between:30,250',
        ]);

        $userId = $request->user()->id;

        $smartwatch = UserSmartwatch::updateOrCreate(
            [
                'id_pengguna'        => $userId,
                'device_identifier'  => $validated['device_identifier'],
            ],
            [
                'brand'              => $validated['brand'] ?? 'generic_ble',
                'device_model'       => $validated['device_model'] ?? 'Smartwatch',
                'mac_address'        => $validated['mac_address'] ?? null,
                'is_active'          => true,
                'last_battery_level' => $validated['last_battery_level'] ?? null,
                'last_heart_rate'    => $validated['last_heart_rate'] ?? null,
                'last_synced_at'     => now(),
            ]
        );

        Log::info("Pengguna ID {$userId} berhasil memasangkan smartwatch: {$smartwatch->device_model} ({$smartwatch->device_identifier})");

        return response()->json([
            'success' => true,
            'message' => 'Smartwatch berhasil dipasangkan.',
            'data'    => $smartwatch,
        ], 201);
    }

    /**
     * Menampilkan detail satu smartwatch
     */
    public function show(Request $request, $id)
    {
        $smartwatch = $request->user()
            ->smartwatches()
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $smartwatch,
        ]);
    }

    /**
     * Memperbarui informasi atau status aktif smartwatch
     */
    public function update(Request $request, $id)
    {
        $smartwatch = $request->user()
            ->smartwatches()
            ->findOrFail($id);

        $validated = $request->validate([
            'device_model' => 'nullable|string|max:100',
            'is_active'    => 'nullable|boolean',
        ]);

        $smartwatch->update(array_filter($validated, fn($v) => !is_null($v)));

        return response()->json([
            'success' => true,
            'message' => 'Data smartwatch berhasil diperbarui.',
            'data'    => $smartwatch,
        ]);
    }

    /**
     * Menghapus pasangan (unpair) smartwatch dari akun
     */
    public function destroy(Request $request, $id)
    {
        $smartwatch = $request->user()
            ->smartwatches()
            ->findOrFail($id);

        $modelName = $smartwatch->device_model;
        $smartwatch->delete();

        Log::info("Pengguna ID {$request->user()->id} memutus pasangan smartwatch {$modelName}");

        return response()->json([
            'success' => true,
            'message' => 'Smartwatch berhasil diputuskan (unpaired).',
        ]);
    }

    /**
     * Menyinkronkan telemetri kesehatan terkini dari smartwatch (BPM, baterai)
     */
    public function syncTelemetry(Request $request, $id)
    {
        $smartwatch = $request->user()
            ->smartwatches()
            ->findOrFail($id);

        $validated = $request->validate([
            'battery_level' => 'nullable|integer|between:0,100',
            'heart_rate'    => 'nullable|integer|between:30,250',
        ]);

        $smartwatch->update([
            'last_battery_level' => $validated['battery_level'] ?? $smartwatch->last_battery_level,
            'last_heart_rate'    => $validated['heart_rate'] ?? $smartwatch->last_heart_rate,
            'last_synced_at'     => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Telemetri smartwatch berhasil diperbarui.',
            'data'    => [
                'id'                 => $smartwatch->id,
                'last_battery_level' => $smartwatch->last_battery_level,
                'last_heart_rate'    => $smartwatch->last_heart_rate,
                'last_synced_at'     => $smartwatch->last_synced_at->toIso8601String(),
            ],
        ]);
    }
}
