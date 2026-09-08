<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens,HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'alamat',
        'no_telp',
        'role',
        'device_id',
        'foto_profile',
        'getaran',
        'talkback',
        'panduan_suara',
        'text_besar',
        'lokasi_user',
        'kategori_user',
        'status_ketersediaan',
        'catatan_medis',
        'latitude',
        'longitude',
        'last_located_at',
        'status_verifikasi',
        'persetujuan_privasi',
        'waktu_persetujuan',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'getaran'             => 'boolean',
            'talkback'            => 'boolean',
            'panduan_suara'       => 'boolean',
            'text_besar'          => 'boolean',
            'latitude'            => 'float',
            'longitude'           => 'float',
            'last_located_at'     => 'datetime',
            'persetujuan_privasi' => 'boolean',
            'waktu_persetujuan'   => 'datetime',
        ];
    }

    /**
     * Scope query untuk mencari user/relawan terdekat berdasarkan latitude & longitude (Formula Haversine).
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
     * Cek apakah data profil pengguna/relawan sudah lengkap
     */
    public function isProfileComplete(): bool
    {
        return !empty($this->no_telp) && !empty($this->alamat);
    }

    /**
     * Accessor untuk is_profile_complete
     */
    public function getIsProfileCompleteAttribute(): bool
    {
        return $this->isProfileComplete();
    }

    /**
     * Relasi ke Akun Autentikasi (Pengguna hasMany Account)
     */
    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Relasi ke Kontak Darurat (Pengguna hasMany Kontak_darurat)
     */
    public function kontakDarurat()
    {
        return $this->hasMany(Kontak_darurat::class, 'id_pengguna');
    }

    /**
     * Relasi ke SOS yang dibuat oleh Pengguna (Pengguna hasMany SOS)
     */
    public function sosCreated()
    {
        return $this->hasMany(SOS::class, 'id_pengguna');
    }

    /**
     * Relasi ke SOS yang ditangani/diselesaikan oleh Relawan (Relawan hasMany SOS)
     */
    public function sosHandled()
    {
        return $this->hasMany(SOS::class, 'id_relawan');
    }

    /**
     * Relasi ke Laporan yang dibuat oleh Pengguna (Pengguna hasMany Laporan)
     */
    public function laporanCreated()
    {
        return $this->hasMany(Laporan::class, 'id_pengguna');
    }

    /**
     * Relasi ke Laporan yang ditangani/diselesaikan oleh Relawan (Relawan hasMany Laporan)
     */
    public function laporanHandled()
    {
        return $this->hasMany(Laporan::class, 'id_relawan');
    }
}
