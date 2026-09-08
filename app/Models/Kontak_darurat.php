<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kontak_darurat extends Model
{
    protected $table = 'kontak_darurats';

    protected $fillable = [
        'id_pengguna',
        'nama',
        'no_telp',
        'pesan',
        'terima_notif',
    ];

    protected $casts = [
        'terima_notif' => 'boolean',
    ];

    /**
     * Relasi ke User (Pengguna pemilik kontak darurat)
     */
    public function pengguna()
    {
        return $this->belongsTo(User::class, 'id_pengguna');
    }
}
