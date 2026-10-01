<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SOSActivity extends Model
{
    protected $table = 's_o_s_activities';

    protected $fillable = [
        'sos_id',
        'user_id',
        'action',
        'description',
        'extra_data',
    ];

    protected $casts = [
        'extra_data' => 'array',
    ];

    /**
     * Relasi ke Kasus SOS
     */
    public function sos()
    {
        return $this->belongsTo(SOS::class, 'sos_id');
    }

    /**
     * Relasi ke User Pelaku Aktivitas (Korban, Relawan, atau Admin)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Helper statis untuk mencatat log aktivitas SOS dengan mudah
     */
    public static function record($sosId, $action, $description, $userId = null, array $extraData = [])
    {
        return self::create([
            'sos_id'      => $sosId,
            'user_id'     => $userId,
            'action'      => $action,
            'description' => $description,
            'extra_data'  => !empty($extraData) ? $extraData : null,
        ]);
    }
}
