<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Geolocation & Distance Constants
    |--------------------------------------------------------------------------
    | Earth radius constants in kilometers and meters for Haversine formulas.
    */
    'earth_radius_km' => (float) env('SOS_EARTH_RADIUS_KM', 6371.0),
    'earth_radius_m' => (float) env('SOS_EARTH_RADIUS_M', 6371000.0),

    /*
    |--------------------------------------------------------------------------
    | Auto-Escalation Configuration
    |--------------------------------------------------------------------------
    | Initial search radius (km) and auto-escalation interval delay in seconds.
    */
    'initial_radius' => (float) env('SOS_INITIAL_RADIUS', 2.0),
    'escalation_delay_seconds' => (int) env('SOS_ESCALATION_DELAY', 30),

    /*
    |--------------------------------------------------------------------------
    | Multi-Tier Escalation Radii (km)
    |--------------------------------------------------------------------------
    | Next escalation radius mapping: 3.0km -> 5.0km -> 10.0km -> 0.0 (General Broadcast).
    */
    'escalation_tiers' => [
        3.0 => 5.0,
        5.0 => 10.0,
        10.0 => 0.0,
    ],
];
