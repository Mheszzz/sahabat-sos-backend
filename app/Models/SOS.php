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
        'alasan_batal',
        'waktu_sos',
        'battery_level',
        'signal_strength',
        'device_info',
    ];

    protected $casts = [
        'latitude'      => 'float',
        'longitude'     => 'float',
        'battery_level' => 'integer',
        'device_info'   => 'array',
        'waktu_sos'     => 'datetime',
    ];

    protected $appends = [
        'device_info',
    ];

    /**
     * Accessor untuk device_info agar selalu mengembalikan status perangkat (baterai, sinyal, dll.)
     */
    public function getDeviceInfoAttribute($value)
    {
        $raw = !empty($value) ? (is_array($value) ? $value : json_decode($value, true)) : [];
        
        $battery = $this->attributes['battery_level'] ?? ($raw['battery_level'] ?? 85);
        $signal = $this->attributes['signal_strength'] ?? ($raw['signal_strength'] ?? '4G / Kuat');

        return [
            'battery_level'   => (int) $battery,
            'battery_status'  => $battery < 20 ? 'Kritis' : ($battery < 50 ? 'Sedang' : 'Baik'),
            'signal_strength' => $signal,
            'device_id'       => $this->pengguna?->device_id ?? ($raw['device_id'] ?? null),
            'device_model'    => $raw['device_model'] ?? ($raw['model'] ?? 'Perangkat Mobile Korban'),
            'os_version'      => $raw['os_version'] ?? 'Android/iOS',
        ];
    }

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

    /**
     * Relasi ke penolakan SOS
     */
    public function rejections()
    {
        return $this->hasMany(SOSRejection::class, 'id_sos');
    }

    /**
     * Relasi ke log aktivitas kasus SOS
     */
    public function activities()
    {
        return $this->hasMany(SOSActivity::class, 'sos_id')->orderBy('created_at', 'asc');
    }
}
