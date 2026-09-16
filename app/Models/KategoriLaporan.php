<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KategoriLaporan extends Model
{
    use HasFactory;

    protected $table = 'kategori_laporans';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'title',
        'subtitle',
        'icon',
        'color',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
