<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MapboxService
{
    protected string $token;

    public function __construct()
    {
        $this->token = config('services.mapbox.token');
    }

    /**
     * Mengambil rute jalan, jarak, dan ETA dari Mapbox Directions API
     */
    public function getRoute(float $originLat, float $originLng, float $destLat, float $destLng): ?array
    {
        // Format Mapbox API: Longitude,Latitude
        $url = "https://api.mapbox.com/directions/v5/mapbox/driving/{$originLng},{$originLat};{$destLng},{$destLat}";

        try {
            $response = Http::get($url, [
                'geometries'   => 'geojson',
                'overview'     => 'full',
                'access_token' => $this->token,
            ]);

            if ($response->successful() && isset($response->json()['routes'][0])) {
                $route = $response->json()['routes'][0];

                $distance = $route['distance']; // Satuan meter
                $duration = $route['duration']; // Satuan detik
                $geometry = $route['geometry']['coordinates']; // Array GeoJSON [[lng, lat], ...]

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
        } catch (\Exception $e) {
            Log::error("Mapbox API Error: " . $e->getMessage());
        }

        return null;
    }
}