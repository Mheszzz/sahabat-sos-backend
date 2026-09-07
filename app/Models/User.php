<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'getaran' => 'boolean',
            'talkback' => 'boolean',
            'panduan_suara' => 'boolean',
            'text_besar' => 'boolean',
        ];
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
