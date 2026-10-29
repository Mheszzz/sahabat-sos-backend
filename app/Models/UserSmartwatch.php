<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSmartwatch extends Model
{
    use HasFactory;

    protected $table = 'user_smartwatches';

    protected $fillable = [
        'id_pengguna',
        'device_identifier',
        'brand',
        'device_model',
        'mac_address',
        'is_active',
        'last_battery_level',
        'last_heart_rate',
        'last_synced_at',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'last_battery_level' => 'integer',
        'last_heart_rate'    => 'integer',
        'last_synced_at'     => 'datetime',
    ];

    /**
     * Relasi ke User pemilik smartwatch
     */
    public function pengguna()
    {
        return $this->belongsTo(User::class, 'id_pengguna');
    }
}
