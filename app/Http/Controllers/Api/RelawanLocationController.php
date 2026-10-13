<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SOS;
use App\Models\User;
use App\Events\RelawanLocationUpdate;
use App\Services\MapboxService;
use App\HasGeoCalculations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
            ->where('status_sos', 'ditangani')
            ->first();

        $newRouteData = null;
        $isRerouted = false;
        $distanceInMeters = 0.0;

        if ($activeSos) {
            // A. Jarak Haversine
            $distanceInMeters = $this->calculateDistance(
                $lat, $lng,
                $activeSos->latitude, $activeSos->longitude
            );

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

        // B. Broadcast WebSocket Reverb (Admin Command Center & Mobile Korban jika ada sos)
        $statusLabel = $activeSos ? 'dalam_misi' : ($user->status_ketersediaan === 'tersedia' ? 'siaga_bebas' : 'off_istirahat');
        try {
            broadcast(new RelawanLocationUpdate(
                $activeSos ? $activeSos->id : null,
                $lat,
                $lng,
                $heading,
                $distanceInMeters,
                $user->id,
                $user->name,
                $statusLabel,
                $user->lokasi_user ?? 'Area Siaga'
            ));
        } catch (\Throwable $e) {
            Log::warning("Gagal broadcast RelawanLocationUpdate: " . $e->getMessage());
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
     * Mendapatkan data detail & lokasi relawan (untuk Admin / Kelola Relawan)
     */
    public function show(Request $request, $id)
    {
        $relawan = User::with('accounts')
            ->where('role', 'relawan')
            ->find($id);

        if (!$relawan) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Relawan tidak ditemukan.'
            ], 404);
        }

        // Ambil lokasi realtime dari cache jika ada
        $cachedLocation = Cache::get("relawan:{$relawan->id}:location");

        $lat = $cachedLocation['latitude'] ?? ($relawan->latitude ? (float) $relawan->latitude : null);
        $lng = $cachedLocation['longitude'] ?? ($relawan->longitude ? (float) $relawan->longitude : null);
        $heading = $cachedLocation['heading'] ?? null;
        $updatedAt = $cachedLocation['updated_at'] ?? $relawan->updated_at?->toIso8601String();

        // Cek apakah sedang menangani tugas SOS aktif
        $activeSos = SOS::with('pengguna')
            ->where('id_relawan', $relawan->id)
            ->whereIn('status_sos', ['belum ditangani', 'ditangani', 'aktif', 'proses'])
            ->first();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'id'                  => $relawan->id,
                'name'                => $relawan->name,
                'email'               => $relawan->accounts->first()?->email ?? '-',
                'no_telp'             => $relawan->no_telp,
                'alamat'              => $relawan->alamat,
                'pekerjaan'           => $relawan->pekerjaan,
                'foto_profile'        => $relawan->foto_profile,
                'status_verifikasi'   => $relawan->status_verifikasi,
                'status_ketersediaan' => $relawan->status_ketersediaan ?? 'tersedia',
                'is_active'           => (bool) $relawan->is_active,
                'latitude'            => $lat,
                'longitude'           => $lng,
                'heading'             => $heading,
                'lokasi_user'         => $relawan->lokasi_user ?? 'Area Siaga',
                'lokasi_terakhir_at'  => $updatedAt,
                'tugas_aktif'         => $activeSos ? [
                    'id'       => $activeSos->id,
                    'id_kasus' => "#SOS-{$activeSos->id}",
                    'status'   => $activeSos->status_sos,
                    'korban'   => $activeSos->pengguna?->name ?? 'Pengguna',
                    'alamat'   => $activeSos->alamat ?? 'Lokasi Kejadian',
                ] : null,
                'alasan_relawan'      => $relawan->alasan_relawan,
                'created_at'          => $relawan->created_at,
            ]
        ], 200);
    }

    /**
     * Mendapatkan semua lokasi relawan aktif untuk admin
     */
    public function getActiveLocations(Request $request)
    {
        $volunteers = User::where('role', 'relawan')
            ->where('is_active', true)
            ->select('id', 'name', 'no_telp', 'latitude', 'longitude', 'status_ketersediaan', 'lokasi_user')
            ->get()
            ->map(function ($r) {
                $cached = Cache::get("relawan:{$r->id}:location");
                return [
                    'id'                  => $r->id,
                    'name'                => $r->name,
                    'no_telp'             => $r->no_telp,
                    'latitude'            => $cached['latitude'] ?? (float) $r->latitude,
                    'longitude'           => $cached['longitude'] ?? (float) $r->longitude,
                    'status_ketersediaan' => $r->status_ketersediaan ?? 'tersedia',
                    'lokasi_user'         => $r->lokasi_user ?? 'Area Siaga',
                    'is_live'             => !empty($cached),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data'   => $volunteers
        ]);
    }
}