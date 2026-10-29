<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MapboxService
{
    protected ?string $token;

    public function __construct()
    {
        $this->token = config('services.mapbox.token');
    }

    /**
     * Mengambil rute jalan, jarak, dan ETA dari Mapbox Directions API,
     * dengan graceful fallback otomatis ke OSRM jika token Mapbox kosong / gagal.
     */
    public function getRoute(float $originLat, float $originLng, float $destLat, float $destLng): ?array
    {
        // 1. Coba via Mapbox jika token tersedia
        if (!empty($this->token)) {
            $url = "https://api.mapbox.com/directions/v5/mapbox/driving/{$originLng},{$originLat};{$destLng},{$destLat}";

            try {
                $response = Http::timeout(5)->get($url, [
                    'geometries'   => 'geojson',
                    'overview'     => 'full',
                    'access_token' => $this->token,
                ]);

                if ($response->successful() && isset($response->json()['routes'][0])) {
                    $route = $response->json()['routes'][0];
                    return $this->formatRouteResponse(
                        (float) $route['distance'],
                        (float) $route['duration'],
                        $route['geometry']['coordinates']
                    );
                }
            } catch (\Exception $e) {
                Log::warning("Mapbox API Error, beralih ke fallback OSRM: " . $e->getMessage());
            }
        }

        // 2. Fallback ke OSRM jika token Mapbox kosong atau request Mapbox gagal
        return $this->getRouteFromOSRM($originLat, $originLng, $destLat, $destLng);
    }

    /**
     * Fallback OSRM Directions Engine
     */
    protected function getRouteFromOSRM(float $originLat, float $originLng, float $destLat, float $destLng): ?array
    {
        $osrmBaseUrl = config('services.osrm.url', env('OSRM_ROUTING_URL', 'https://router.project-osrm.org'));
        $url = "{$osrmBaseUrl}/route/v1/driving/{$originLng},{$originLat};{$destLng},{$destLat}?geometries=geojson&overview=full";

        try {
            $response = Http::timeout(5)->get($url);

            if ($response->successful() && isset($response->json()['routes'][0])) {
                $route = $response->json()['routes'][0];
                return $this->formatRouteResponse(
                    (float) $route['distance'],
                    (float) $route['duration'],
                    $route['geometry']['coordinates']
                );
            }
        } catch (\Exception $e) {
            Log::error("OSRM Routing API Error: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Format konsisten output data rute & polyline
     */
    protected function formatRouteResponse(float $distance, float $duration, array $geometry): array
    {
        return [
            'distance_in_meters'  => round($distance),
            'duration_in_seconds' => round($duration),
            'distance_formatted'  => $distance >= 1000
                ? round($distance / 1000, 2) . ' km'
                : round($distance) . ' m',
            'duration_formatted'  => round($duration / 60) . ' menit',
            'polyline'            => $geometry,
        ];
    }
}