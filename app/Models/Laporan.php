<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Laporan extends Model
{
    protected $table = 'laporans';

    protected $fillable = [
        'nomor_kasus',
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

    protected $appends = [
        'id_kasus',
        'foto_laporan_url',
        'rekam_suara_url',
    ];

    /**
     * Auto-generate nomor_kasus (contoh: LP-1, LP-2) saat pembuatan record baru
     */
    protected static function booted()
    {
        static::creating(function ($lap) {
            if (empty($lap->nomor_kasus)) {
                $lap->nomor_kasus = static::generateNomorKasus();
            }
        });
    }

    /**
     * Generator nomor kasus transaksi Laporan berikutnya
     */
    public static function generateNomorKasus(): string
    {
        try {
            $lastRecord = static::whereNotNull('nomor_kasus')
                ->orderByRaw("CAST(SUBSTRING(nomor_kasus FROM '[0-9]+') AS INTEGER) DESC NULLS LAST")
                ->first();

            if ($lastRecord && preg_match('/LP-(\d+)/', $lastRecord->nomor_kasus, $matches)) {
                $nextNum = ((int) $matches[1]) + 1;
            } else {
                $nextNum = (static::max('id') ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $nextNum = (static::max('id') ?? 0) + 1;
        }

        while (static::where('nomor_kasus', 'LP-' . $nextNum)->exists()) {
            $nextNum++;
        }

        return 'LP-' . $nextNum;
    }

    /**
     * Accessor untuk id_kasus agar selalu mengembalikan format LP-X
     */
    public function getIdKasusAttribute(): string
    {
        return $this->nomor_kasus ?? ('LP-' . $this->id);
    }

    /**
     * Accessor untuk foto_laporan_url
     */
    public function getFotoLaporanUrlAttribute(): ?string
    {
        return $this->foto_laporan ? url('storage/' . $this->foto_laporan) : null;
    }

    /**
     * Accessor untuk rekam_suara_url
     */
    public function getRekamSuaraUrlAttribute(): ?string
    {
        return $this->rekam_suara ? url('storage/' . $this->rekam_suara) : null;
    }

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

    public function catatanPenanganan()
    {
        return $this->hasMany(CatatanPenanganan::class, 'id_laporan');
    }
}
