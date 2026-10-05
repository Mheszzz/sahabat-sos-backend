<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SOS;
use App\Events\RelawanLocationUpdate;
use App\Services\MapboxService;
use App\HasGeoCalculations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class RelawanLocationController extends Controller
{
    /**
     * Endpoint untuk relawan mengirimkan posisi lokasi secara realtime
     */
    
    use HasGeoCalculations;

    public function updateLocation(Request $request, MapboxService $mapboxService)
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

        // 1. REDIS: Cache Lokasi Relawan
        Cache::put("relawan:{$user->id}:location", [
            'latitude'   => $lat,
            'longitude'  => $lng,
            'heading'    => $heading,
            'updated_at' => now()->toIso8601String(),
        ], 3600);

        // 2. REVERB & MAPBOX OFF-ROUTE CHECK
        $activeSos = SOS::where('id_relawan', $user->id)
            ->where('status_sos', 'proses')
            ->first();

        $newRouteData = null;
        $isRerouted = false;

        if ($activeSos) {
            // A. Jarak Haversine
            $distanceInMeters = $this->calculateDistance(
                $lat, $lng,
                $activeSos->latitude, $activeSos->longitude
            );

            // B. Broadcast WebSocket ke Korban
            broadcast(new RelawanLocationUpdate(
                $activeSos->id,
                $lat,
                $lng,
                $heading,
                $distanceInMeters
            ))->toOthers();

            // C. Deteksi Off-Route (Jarak ke polyline > 50m)
            $cachedPolyline = Cache::get("sos:{$activeSos->id}:polyline");
            $lastRerouteTime = Cache::get("sos:{$activeSos->id}:last_reroute");
            $currentTime = time();

            // Evaluasi hanya jika ada cache polyline dan cooldown > 20 detik
            if ($cachedPolyline && (!$lastRerouteTime || ($currentTime - (int)$lastRerouteTime) >= 20)) {
                $offRoute = $this->isOffRoute($lat, $lng, $cachedPolyline, 50.0);

                if ($offRoute) {
                    $newRouteData = $mapboxService->getRoute(
                        $lat, $lng,
                        (float) $activeSos->latitude, (float) $activeSos->longitude
                    );

                    if ($newRouteData && isset($newRouteData['polyline'])) {
                        Cache::put("sos:{$activeSos->id}:polyline", $newRouteData['polyline'], 3600);
                        Cache::put("sos:{$activeSos->id}:last_reroute", $currentTime, 3600);
                        $isRerouted = true;
                    }
                }
            }
        }

        // 3. POSTGRESQL SYNC (Throttled 30 detik)
        $lastDbSync = Cache::get("relawan:{$user->id}:last_db_sync");
        $currentTime = time();

        if (!$lastDbSync || ($currentTime - (int)$lastDbSync) >= 30) {
            $user->update([
                'latitude'  => $lat,
                'longitude' => $lng,
            ]);
            Cache::put("relawan:{$user->id}:last_db_sync", $currentTime, 3600);
        }

        return response()->json([
            'status'         => 'success',
            'message'        => 'Lokasi berhasil diproses.',
            'is_active_task' => $activeSos ? true : false,
            'is_rerouted'    => $isRerouted,
            'new_route'      => $newRouteData,
        ]);
    }

    /**
     * Private helper menghitung jarak terdekat relawan ke segmen polyline
     */
    private function isOffRoute(float $currentLat, float $currentLng, array $polylinePoints, float $thresholdMeters = 50.0): bool
    {
        if (empty($polylinePoints)) {
            return false;
        }

        $minDistance = PHP_FLOAT_MAX;

        foreach ($polylinePoints as $point) {
            // Mapbox format: [longitude, latitude]
            $pointLng = (float) $point[0];
            $pointLat = (float) $point[1];

            $distance = $this->calculateDistance($currentLat, $currentLng, $pointLat, $pointLng);

            if ($distance < $minDistance) {
                $minDistance = $distance;
            }
        }

        return $minDistance > $thresholdMeters;
    }

    /**
     * Helper privat untuk menghitung jarak antara 2 titik koordinat (Haversine Formula)
     * Output: Meter
     */
   
}