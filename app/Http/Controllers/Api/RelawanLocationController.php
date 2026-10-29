<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SOS;
use App\Models\Laporan;
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
            'baterai'   => 'nullable|numeric|min:0|max:100',
            'battery'   => 'nullable|numeric|min:0|max:100',
            'akurasi'   => 'nullable|numeric|min:0',
            'accuracy'  => 'nullable|numeric|min:0',
        ]);

        $user = $request->user();

        if ($user->role !== 'relawan') {
            return response()->json(['message' => 'Hanya relawan yang dapat memperbarui lokasi.'], 403);
        }

        $lat = (float) $request->latitude;
        $lng = (float) $request->longitude;
        $heading = $request->heading ? (float) $request->heading : null;
        $baterai = $request->baterai ?? $request->battery;
        $akurasi = $request->akurasi ?? $request->accuracy;

        // 1. REDIS: Cache Lokasi Relawan & Telemetri
        Cache::put("relawan:{$user->id}:location", [
            'latitude'   => $lat,
            'longitude'  => $lng,
            'heading'    => $heading,
            'baterai'    => $baterai !== null ? (int) $baterai : null,
            'akurasi'    => $akurasi !== null ? (float) $akurasi : null,
            'updated_at' => now()->toIso8601String(),
        ], 3600);

        // 2. REVERB & MAPBOX/OSRM OFF-ROUTE CHECK
        $activeSos = SOS::where('id_relawan', $user->id)
            ->whereIn('status_sos', ['ditangani', 'proses'])
            ->first();

        $activeLaporan = null;
        if (!$activeSos) {
            $activeLaporan = Laporan::where('id_relawan', $user->id)
                ->whereIn('status', ['ditangani', 'proses'])
                ->first();
        }

        $activeTask = $activeSos ?? $activeLaporan;

        $newRouteData = null;
        $isRerouted = false;
        $distanceInMeters = 0.0;

        if ($activeTask && $activeTask->latitude && $activeTask->longitude) {
            // A. Jarak Haversine ke titik korban/pelapor
            $distanceInMeters = $this->calculateDistance(
                $lat, $lng,
                (float) $activeTask->latitude, (float) $activeTask->longitude
            );

            // C. Deteksi Off-Route (Jarak tegak lurus ke polyline segmen jalan > 50m)
            $cacheKeyPrefix = $activeSos ? "sos:{$activeSos->id}" : "laporan:{$activeLaporan->id}";
            $cachedPolyline = Cache::get("{$cacheKeyPrefix}:polyline");
            $lastRerouteTime = Cache::get("{$cacheKeyPrefix}:last_reroute");
            $currentTime = time();

            // Evaluasi jika ada cache polyline dan cooldown reroute >= 20 detik
            if ($cachedPolyline && (!$lastRerouteTime || ($currentTime - (int)$lastRerouteTime) >= 20)) {
                $offRoute = $this->isOffRoute($lat, $lng, $cachedPolyline, 50.0);

                if ($offRoute) {
                    $newRouteData = $mapboxService->getRoute(
                        $lat, $lng,
                        (float) $activeTask->latitude, (float) $activeTask->longitude
                    );

                    if ($newRouteData && isset($newRouteData['polyline'])) {
                        Cache::put("{$cacheKeyPrefix}:polyline", $newRouteData['polyline'], 3600);
                        Cache::put("{$cacheKeyPrefix}:last_reroute", $currentTime, 3600);
                        $isRerouted = true;
                    }
                }
            }
        }

        // B. Broadcast WebSocket Reverb (Admin Command Center & Mobile Korban jika ada sos)
        $statusLabel = ($activeSos || $activeLaporan) ? 'dalam_misi' : ($user->status_ketersediaan === 'tersedia' ? 'siaga_bebas' : 'off_istirahat');
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

        // 3. POSTGRESQL SYNC (Throttled 30 detik, tapi langsung jika koordinat di DB masih kosong)
        $lastDbSync = Cache::get("relawan:{$user->id}:last_db_sync");
        $currentTime = time();

        if ($user->latitude === null || $user->longitude === null || !$lastDbSync || ($currentTime - (int)$lastDbSync) >= 30) {
            $user->update([
                'latitude'        => $lat,
                'longitude'       => $lng,
                'last_located_at' => now(),
            ]);
            Cache::put("relawan:{$user->id}:last_db_sync", $currentTime, 3600);
        }

        return response()->json([
            'status'         => 'success',
            'message'        => 'Lokasi berhasil diproses.',
            'is_active_task' => ($activeSos || $activeLaporan) ? true : false,
            'is_rerouted'    => $isRerouted,
            'new_route'      => $newRouteData,
        ]);
    }

    /**
     * Private helper menghitung jarak terdekat relawan ke segmen polyline jalan (point-to-segment distance).
     * Mencegah false-positive reroute saat relawan berada di tengah segmen jalan lurus.
     */
    private function isOffRoute(float $currentLat, float $currentLng, array $polylinePoints, float $thresholdMeters = 50.0): bool
    {
        $count = count($polylinePoints);
        if ($count === 0) {
            return false;
        }

        if ($count === 1) {
            $pointLng = (float) $polylinePoints[0][0];
            $pointLat = (float) $polylinePoints[0][1];
            return $this->calculateDistance($currentLat, $currentLng, $pointLat, $pointLng) > $thresholdMeters;
        }

        $minDistance = PHP_FLOAT_MAX;

        for ($i = 0; $i < $count - 1; $i++) {
            $p1Lng = (float) $polylinePoints[$i][0];
            $p1Lat = (float) $polylinePoints[$i][1];
            $p2Lng = (float) $polylinePoints[$i + 1][0];
            $p2Lat = (float) $polylinePoints[$i + 1][1];

            $segmentDistance = $this->distanceToSegmentMeters($currentLat, $currentLng, $p1Lat, $p1Lng, $p2Lat, $p2Lng);
            if ($segmentDistance < $minDistance) {
                $minDistance = $segmentDistance;
            }
        }

        return $minDistance > $thresholdMeters;
    }

    /**
     * Menghitung jarak minimum relawan ke segmen garis lurus (p1 -> p2) dalam meter.
     */
    private function distanceToSegmentMeters(float $lat, float $lng, float $p1Lat, float $p1Lng, float $p2Lat, float $p2Lng): float
    {
        $dx = ($p2Lng - $p1Lng) * cos(deg2rad(($p1Lat + $p2Lat) / 2));
        $dy = $p2Lat - $p1Lat;

        if ($dx == 0.0 && $dy == 0.0) {
            return $this->calculateDistance($lat, $lng, $p1Lat, $p1Lng);
        }

        $t = (($lng - $p1Lng) * cos(deg2rad($lat)) * $dx + ($lat - $p1Lat) * $dy) / ($dx * $dx + $dy * $dy);
        $t = max(0.0, min(1.0, $t));

        $projLat = $p1Lat + $t * ($p2Lat - $p1Lat);
        $projLng = $p1Lng + $t * ($p2Lng - $p1Lng);

        return $this->calculateDistance($lat, $lng, $projLat, $projLng);
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
                'foto_profile_url'    => $relawan->foto_profile_url,
                'dokumen_kyc'         => $relawan->dokumen_kyc,
                'dokumen_kyc_url'     => $relawan->dokumen_kyc_url,
                'status_verifikasi'   => $relawan->status_verifikasi,
                'alasan_penolakan'    => $relawan->alasan_penolakan,
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
            ->where('status_verifikasi', 'terverifikasi')
            ->select('id', 'name', 'no_telp', 'latitude', 'longitude', 'status_ketersediaan', 'lokasi_user')
            ->get()
            ->map(function ($r) {
                $cached = Cache::get("relawan:{$r->id}:location");
                $lat = isset($cached['latitude']) ? (float) $cached['latitude'] : ($r->latitude !== null ? (float) $r->latitude : null);
                $lng = isset($cached['longitude']) ? (float) $cached['longitude'] : ($r->longitude !== null ? (float) $r->longitude : null);

                // Lewati relawan yang belum memiliki koordinat agar tidak menjadi 0.0 (Null Island)
                if ($lat === null || $lng === null) {
                    return null;
                }

                $activeSos = SOS::where('id_relawan', $r->id)
                    ->whereIn('status_sos', ['ditangani', 'proses'])
                    ->first();
                $activeLaporan = null;
                if (!$activeSos) {
                    $activeLaporan = Laporan::where('id_relawan', $r->id)
                        ->whereIn('status', ['ditangani', 'proses'])
                        ->first();
                }

                $statusLabel = ($activeSos || $activeLaporan)
                    ? 'dalam_misi'
                    : ($r->status_ketersediaan ?? 'tersedia');

                return [
                    'id'                  => $r->id,
                    'name'                => $r->name,
                    'no_telp'             => $r->no_telp,
                    'latitude'            => (float) $lat,
                    'longitude'           => (float) $lng,
                    'status_ketersediaan' => $statusLabel,
                    'lokasi_user'         => $r->lokasi_user ?? 'Area Siaga',
                    'is_live'             => !empty($cached),
                    'telemetri'           => [
                        'baterai' => isset($cached['baterai']) ? "{$cached['baterai']}%" : null,
                        'akurasi' => isset($cached['akurasi']) ? "±{$cached['akurasi']}m" : null,
                    ],
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'status' => 'success',
            'data'   => $volunteers
        ]);
    }
}