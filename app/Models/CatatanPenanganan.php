<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatatanPenanganan extends Model
{
    protected $table = 'catatan_penanganans';

    protected $fillable = [
        'id_admin',
        'id_laporan',
        'id_sos',
        'catatan',
    ];

    public function admin()
    {
        return $this->belongsTo(User::class, 'id_admin');
    }

    public function laporan()
    {
        return $this->belongsTo(Laporan::class, 'id_laporan');
    }

    public function sos()
    {
        return $this->belongsTo(SOS::class, 'id_sos');
    }
}