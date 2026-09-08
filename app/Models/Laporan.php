<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Laporan extends Model
{
    protected $table = 'laporans';

    protected $fillable = [
        'id_pengguna',
        'id_relawan',
        'lokasi_laporan',
        'latitude',
        'longitude',
        'kategori_laporan',
        'deskripsi',
        'foto_laporan',
        'status',
        'rekam_suara',
        'waktu_laporan',
    ];

    protected $casts = [
        'latitude'      => 'float',
        'longitude'     => 'float',
        'waktu_laporan' => 'datetime',
    ];

    /**
     * Scope query untuk mencari laporan terdekat berdasarkan koordinat pengguna/relawan.
     */
    public function scopeNearby($query, float $latitude, float $longitude, float $radiusInKm = 5.0)
    {
        $haversine = "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))";

        return $query->selectRaw("*, {$haversine} AS distance", [$latitude, $longitude, $latitude])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereRaw("{$haversine} <= ?", [$latitude, $longitude, $latitude, $radiusInKm])
            ->orderByRaw("{$haversine} ASC", [$latitude, $longitude, $latitude]);
    }

    /**
     * Relasi ke User (Pengguna yang membuat Laporan)
     */
    public function pengguna()
    {
        return $this->belongsTo(User::class, 'id_pengguna');
    }

    /**
     * Relasi ke User (Relawan yang menangani/menyelesaikan Laporan)
     */
    public function relawan()
    {
        return $this->belongsTo(User::class, 'id_relawan');
    }
}
