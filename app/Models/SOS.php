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
     * Accessor untuk device_info agar selalu mengembalikan status perangkat (HP & Tuya IoT)
     */
    public function getDeviceInfoAttribute($value = null)
    {
        $value = $value ?? ($this->attributes['device_info'] ?? null);
        $raw = !empty($value) ? (is_array($value) ? $value : json_decode($value, true)) : [];

        // Deteksi format terstruktur (punya key 'mobile', 'tuya', 'smartwatch', atau 'smartband') atau legacy flat
        $hasStructured = isset($raw['mobile']) || isset($raw['tuya']) || isset($raw['smartwatch']) || isset($raw['smartband']);
        $mobileRaw = $hasStructured ? ($raw['mobile'] ?? []) : $raw;
        $tuyaRaw = $raw['tuya'] ?? null;
        $smartwatchRaw = $raw['smartwatch'] ?? $raw['smartband'] ?? null;

        // Fallback jika smartwatch belum terisi tetapi terdapat data detak jantung (heart_rate / bpm)
        if (empty($smartwatchRaw)) {
            $rawHeartRate = $raw['heart_rate'] ?? ($mobileRaw['heart_rate'] ?? null);
            if ($rawHeartRate !== null && (int) $rawHeartRate > 0) {
                $smartwatchRaw = [
                    'device_id'    => 'smartband_sensor',
                    'device_name'  => 'Smartband / Health Connect',
                    'device_model' => 'Smartband',
                    'brand'        => 'huawei_health_connect',
                    'heart_rate'   => (int) $rawHeartRate,
                    'bpm'          => (int) $rawHeartRate,
                    'is_online'    => true,
                    'trigger_type' => 'Sensor Detak Jantung',
                ];
            } else {
                // Fallback: periksa user_smartwatches pengguna pemilik SOS jika ada
                try {
                    $pairedWatch = $this->pengguna?->smartwatches()->where('is_active', true)->latest('last_synced_at')->first();
                    if ($pairedWatch && $pairedWatch->last_heart_rate !== null) {
                        $smartwatchRaw = [
                            'device_id'       => $pairedWatch->device_identifier,
                            'device_name'     => $pairedWatch->device_model ?? 'Smartwatch',
                            'brand'           => $pairedWatch->brand ?? 'generic_ble',
                            'device_model'    => $pairedWatch->device_model ?? 'Smartwatch',
                            'is_online'       => true,
                            'battery_level'   => $pairedWatch->last_battery_level,
                            'heart_rate'      => $pairedWatch->last_heart_rate,
                            'bpm'             => $pairedWatch->last_heart_rate,
                            'trigger_type'    => 'Telemetri Smartwatch Tersinkron',
                            'last_seen'       => $pairedWatch->last_synced_at?->toIso8601String() ?? now()->toIso8601String(),
                        ];
                    }
                } catch (\Throwable $e) {
                    // Ignore exception in accessor
                }
            }
        }

        // Telemetri Mobile (HP Pengguna)
        $batteryLevel = $mobileRaw['battery_level'] ?? ($this->attributes['battery_level'] ?? ($raw['battery_level'] ?? 85));
        $batteryLevel = (int) $batteryLevel;
        $isCharging = (bool) ($mobileRaw['is_charging'] ?? false);
        $batteryState = $mobileRaw['battery_state'] ?? ($isCharging ? 'charging' : 'discharging');

        $batteryStatus = 'Baik';
        if ($batteryLevel < 20) {
            $batteryStatus = 'Kritis';
        } elseif ($batteryLevel < 50) {
            $batteryStatus = 'Sedang';
        }

        $signalStrength = $mobileRaw['signal_strength'] ?? ($this->attributes['signal_strength'] ?? ($raw['signal_strength'] ?? '4G / Kuat'));

        $mobile = [
            'brand'           => $mobileRaw['brand'] ?? 'Perangkat Mobile',
            'model'           => $mobileRaw['model'] ?? ($mobileRaw['device_model'] ?? 'Ponsel Pengguna'),
            'os_version'      => $mobileRaw['os_version'] ?? 'Android/iOS',
            'battery_level'   => $batteryLevel,
            'battery_status'  => $batteryStatus,
            'battery_state'   => $batteryState,
            'is_charging'     => $isCharging,
            'is_critical'     => $batteryLevel < 20,
            'signal_strength' => $signalStrength,
            'gps_accuracy'    => $mobileRaw['gps_accuracy'] ?? null,
            'device_id'       => $this->pengguna?->device_id ?? ($mobileRaw['device_id'] ?? null),
            'updated_at'      => $mobileRaw['updated_at'] ?? ($this->waktu_sos ? $this->waktu_sos->toDateTimeString() : null),
        ];

        // Telemetri Perangkat IoT Tuya (jika ada)
        $tuya = null;
        if (!empty($tuyaRaw)) {
            $tuyaBattery = isset($tuyaRaw['battery_level']) && $tuyaRaw['battery_level'] !== null
                ? (int) $tuyaRaw['battery_level']
                : null;
            $tuyaIsOnline = (bool) ($tuyaRaw['is_online'] ?? true);

            $tuya = [
                'device_id'       => $tuyaRaw['device_id'] ?? 'tuya_device',
                'device_name'     => $tuyaRaw['device_name'] ?? ($tuyaRaw['name'] ?? 'Tombol Darurat IoT Tuya'),
                'product_id'      => $tuyaRaw['product_id'] ?? null,
                'is_online'       => $tuyaIsOnline,
                'battery_level'   => $tuyaBattery,
                'battery_status'  => $tuyaBattery !== null ? ($tuyaBattery < 20 ? 'Kritis' : ($tuyaBattery < 50 ? 'Sedang' : 'Baik')) : 'N/A',
                'is_battery_low'  => $tuyaBattery !== null && $tuyaBattery < 20,
                'signal_strength' => $tuyaRaw['signal_strength'] ?? 'Bagus',
                'trigger_type'    => $tuyaRaw['trigger_type'] ?? 'Tombol Fisik Tuya',
                'last_seen'       => $tuyaRaw['last_seen'] ?? ($tuyaRaw['last_event_at'] ?? null),
            ];
        }

        // Telemetri Perangkat Smartwatch (jika ada)
        $smartwatch = null;
        if (!empty($smartwatchRaw)) {
            $watchBattery = isset($smartwatchRaw['battery_level']) && $smartwatchRaw['battery_level'] !== null
                ? (int) $smartwatchRaw['battery_level']
                : null;
            $heartRate = isset($smartwatchRaw['heart_rate']) && $smartwatchRaw['heart_rate'] !== null
                ? (int) $smartwatchRaw['heart_rate']
                : (isset($smartwatchRaw['bpm']) && $smartwatchRaw['bpm'] !== null
                    ? (int) $smartwatchRaw['bpm']
                    : (isset($smartwatchRaw['heart_rate_bpm']) && $smartwatchRaw['heart_rate_bpm'] !== null
                        ? (int) $smartwatchRaw['heart_rate_bpm']
                        : ($raw['heart_rate'] ?? null)));
            $heartRate = $heartRate !== null ? (int) $heartRate : null;
            $fallDetected = (bool) ($smartwatchRaw['fall_detected'] ?? false);

            $smartwatch = [
                'device_id'       => $smartwatchRaw['device_id'] ?? 'smartwatch',
                'device_name'     => $smartwatchRaw['device_name'] ?? ($smartwatchRaw['name'] ?? 'Smartwatch'),
                'brand'           => $smartwatchRaw['brand'] ?? 'smartwatch',
                'device_model'    => $smartwatchRaw['device_model'] ?? ($smartwatchRaw['model'] ?? ($smartwatchRaw['type'] ?? 'Smartwatch')),
                'is_online'       => (bool) ($smartwatchRaw['is_online'] ?? true),
                'battery_level'   => $watchBattery,
                'battery_status'  => $watchBattery !== null ? ($watchBattery < 20 ? 'Kritis' : ($watchBattery < 50 ? 'Sedang' : 'Baik')) : 'N/A',
                'is_battery_low'  => $watchBattery !== null && $watchBattery < 20,
                'heart_rate'      => $heartRate,
                'bpm'             => $heartRate,
                'fall_detected'   => $fallDetected,
                'trigger_type'    => $smartwatchRaw['trigger_type'] ?? ($fallDetected ? 'Deteksi Jatuh Jam' : ($heartRate ? 'Sensor Detak Jantung' : 'Tombol SOS Jam')),
                'last_seen'       => $smartwatchRaw['last_seen'] ?? now()->toIso8601String(),
            ];
        }

        $triggerSource = $raw['trigger_source'] ?? (
            $tuya ? 'tuya_physical_button' : (
                $smartwatch ? ($smartwatch['fall_detected'] ? 'smartwatch_fall_detection' : ($smartwatch['heart_rate'] ? 'smartband_heart_rate' : 'smartwatch_button')) : 'app_button'
            )
        );

        return [
            // Flat alias untuk kompatibilitas kode lama
            'battery_level'   => $batteryLevel,
            'battery_status'  => $batteryStatus,
            'signal_strength' => $signalStrength,
            'device_id'       => $mobile['device_id'],
            'device_model'    => trim(($mobile['brand'] ?? '') . ' ' . ($mobile['model'] ?? '')),
            'os_version'      => $mobile['os_version'],
            'is_online'       => true,
            'is_charging'     => $isCharging,
            'heart_rate'      => $smartwatch['heart_rate'] ?? null,
            'bpm'             => $smartwatch['heart_rate'] ?? null,

            // Struktur lengkap telemetri baru
            'trigger_source'       => $triggerSource,
            'is_warning'           => ($batteryLevel < 20) || ($tuya && $tuya['is_battery_low']) || ($smartwatch && $smartwatch['is_battery_low']),
            'is_gps_fallback'      => (bool) ($raw['is_gps_fallback'] ?? false),
            'gps_status'           => $raw['gps_status'] ?? 'live_gps',
            'gps_fallback_source'  => $raw['gps_fallback_source'] ?? null,
            'gps_note'             => $raw['gps_note'] ?? null,
            'original_coordinates' => $raw['original_coordinates'] ?? null,
            'mobile'               => $mobile,
            'tuya'                 => $tuya,
            'smartwatch'           => $smartwatch,
            'smartband'            => $smartwatch,
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
