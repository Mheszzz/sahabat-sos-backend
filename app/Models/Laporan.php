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
        'kategori_laporan',
        'deskripsi',
        'foto_laporan',
        'status',
        'rekam_suara',
        'waktu_laporan',
    ];

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
