<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SOS;
use App\Events\RelawanLocationUpdate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class RelawanLocationController extends Controller
{
    /**
     * Endpoint untuk relawan mengirimkan posisi lokasi secara realtime
     */
    public function updateLocation(Request $request)
    {
        $request->validate([
            'latitude'  => 'required|numeric',
            'longitude' => 'required|numeric',
            'heading'   => 'nullable|numeric',
        ]);

        $user = $request->user();

        if ($user->role !== 'relawan') {
            return response()->json(['message' => 'Hanya relawan yang dapat memperbarui lokasi.'], 403);
        }

        $lat = (float) $request->latitude;
        $lng = (float) $request->longitude;
        $heading = $request->heading ? (float) $request->heading : null;

        // ---------------------------------------------------------------
        // 1. REDIS: Simpan selalu lokasi paling baru (Overwrites Fast)
        // ---------------------------------------------------------------
        Cache::put("relawan:{$user->id}:location", [
            'latitude'   => $lat,
            'longitude'  => $lng,
            'heading'    => $heading,
            'updated_at' => now()->toIso8601String(),
        ], ttl: 3600); // TTL 1 jam

        // ---------------------------------------------------------------
        // 2. REVERB: Broadcast ke Korban jika sedang dalam tugas SOS
        // ---------------------------------------------------------------
        $activeSos = SOS::where('id_relawan', $user->id)
            ->where('status_sos', 'proses')
            ->first();

        if ($activeSos) {
            $distanceInMeters = $this->calculateDistance(
                $lat, $lng,
                $activeSos->latitude, $activeSos->longitude
            );

            broadcast(new RelawanLocationUpdate(
                $activeSos->id,
                $lat,
                $lng,
                $heading,
                $distanceInMeters
            ))->toOthers();
        }

        // ---------------------------------------------------------------
        // 3. POSTGRESQL: Throttled Sync (Hanya update DB tiap 30 detik)
        // ---------------------------------------------------------------
        $lastDbSync = Cache::get("relawan:{$user->id}:last_db_sync");
        $currentTime = time();

        // Tambahkan (int) untuk memastikan tipe data integer
        if (!$lastDbSync || ($currentTime - (int)$lastDbSync) >= 30) {
            $user->update([
                'latitude'  => $lat,
                'longitude' => $lng,
            ]);
            Cache::put("relawan:{$user->id}:last_db_sync", $currentTime, 3600);
        }else {
            \Illuminate\Support\Facades\Log::info(">>> SYNC POSTGRESQL DI-SKIP (SISA WAKTU: " . (30 - ($currentTime - (int)$lastDbSync)) . " DETIK)");
        }

        return response()->json([
            'status'         => 'success',
            'message'        => 'Lokasi berhasil diproses.',
            'is_active_task' => $activeSos ? true : false,
        ]);
    }

    /**
     * Helper privat untuk menghitung jarak antara 2 titik koordinat (Haversine Formula)
     * Output: Meter
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000; // Radius bumi dalam meter

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));

        return round($earthRadius * $angle, 2);
    }
}