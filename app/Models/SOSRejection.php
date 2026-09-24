<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SOSRejection extends Model
{
    use HasFactory;

    protected $table = 's_o_s_rejections';

    protected $fillable = [
        'id_sos',
        'id_relawan',
    ];

    /**
     * Relasi balik ke SOS
     */
    public function sos()
    {
        return $this->belongsTo(SOS::class, 'id_sos');
    }

    /**
     * Relasi ke User (Relawan yang menolak)
     */
    public function relawan()
    {
        return $this->belongsTo(User::class, 'id_relawan');
    }
}