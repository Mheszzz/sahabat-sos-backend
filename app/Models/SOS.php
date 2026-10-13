<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SOS extends Model
{
    protected $table = 's_o_s';

    protected $fillable = [
        'nomor_kasus',
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
        'id_kasus',
    ];

    /**
     * Auto-generate nomor_kasus (contoh: SOS-1, SOS-2) saat pembuatan record baru
     */
    protected static function booted()
    {
        static::creating(function ($sos) {
            if (empty($sos->nomor_kasus)) {
                $sos->nomor_kasus = static::generateNomorKasus();
            }
        });
    }

    /**
     * Generator nomor kasus transaksi SOS berikutnya
     */
    public static function generateNomorKasus(): string
    {
        try {
            $lastRecord = static::whereNotNull('nomor_kasus')
                ->orderByRaw("CAST(SUBSTRING(nomor_kasus FROM '[0-9]+') AS INTEGER) DESC NULLS LAST")
                ->first();

            if ($lastRecord && preg_match('/SOS-(\d+)/', $lastRecord->nomor_kasus, $matches)) {
                $nextNum = ((int) $matches[1]) + 1;
            } else {
                $nextNum = (static::max('id') ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $nextNum = (static::max('id') ?? 0) + 1;
        }

        while (static::where('nomor_kasus', 'SOS-' . $nextNum)->exists()) {
            $nextNum++;
        }

        return 'SOS-' . $nextNum;
    }

    /**
     * Accessor untuk id_kasus agar selalu mengembalikan format SOS-X
     */
    public function getIdKasusAttribute(): string
    {
        return $this->nomor_kasus ?? ('SOS-' . $this->id);
    }

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

    public function catatanPenanganan()
    {
        return $this->hasMany(CatatanPenanganan::class, 'id_sos');
    }
    /**
     * Relasi ke log aktivitas kasus SOS
     */
    public function activities()
    {
        return $this->hasMany(SOSActivity::class, 'sos_id')->orderBy('created_at', 'asc');
    }
}
