<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SOS extends Model
{
    protected $table = 's_o_s';

    protected $fillable = [
        'id_pengguna',
        'id_relawan',
        'latitude',
        'longitude',
        'status_sos',
        'waktu_sos',
    ];

    /**
     * Relasi ke User (Pengguna yang membuat SOS)
     */
    public function pengguna()
    {
        return $this->belongsTo(User::class, 'id_pengguna');
    }

    /**
     * Relasi ke User (Relawan yang menangani/menyelesaikan SOS)
     */
    public function relawan()
    {
        return $this->belongsTo(User::class, 'id_relawan');
    }

    public function rejections()
    {
        return $this->hasMany(SOSRejection::class, 'id_sos');
    }
}
