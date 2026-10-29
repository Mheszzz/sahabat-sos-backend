<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kontak_darurat extends Model
{
    protected $table = 'kontak_darurats';

    protected $fillable = [
        'id_pengguna',
        'nama',
        'no_telp',
        'pesan',
        'terima_notif',
        'tipe',
    ];

    protected $casts = [
        'terima_notif' => 'boolean',
    ];

    protected $appends = [
        'no_telp_wa',
        'is_utama',
        'whatsapp_url',
    ];

    /**
     * Accessor untuk nomor telepon terformat internasional WhatsApp (628xxx)
     */
    public function getNoTelpWaAttribute(): ?string
    {
        return \App\Http\Controllers\Api\HubungiWhatsAppController::formatWhatsAppNumber($this->no_telp);
    }

    /**
     * Accessor untuk status apakah kontak utama
     */
    public function getIsUtamaAttribute(): bool
    {
        return $this->tipe === 'utama';
    }

    /**
     * Accessor untuk URL direct WhatsApp wa.me beserta draft pesan bantuan darurat
     */
    public function getWhatsappUrlAttribute(): ?string
    {
        $wa = $this->no_telp_wa;
        if (!$wa) {
            return null;
        }

        $namaPenerima = $this->nama ?? 'Keluarga';
        $namaPengguna = $this->relationLoaded('pengguna') && $this->pengguna ? $this->pengguna->name : null;

        $lines = [];
        $lines[] = "🚨 *PANGGILAN DARURAT - SAHABAT SOS* 🚨";
        $lines[] = "";
        $lines[] = "Halo Bapak/Ibu *{$namaPenerima}*,";
        if ($namaPengguna) {
            $lines[] = "Saya *{$namaPengguna}* membutuhkan bantuan darurat segera.";
        } else {
            $lines[] = "Saya membutuhkan bantuan darurat segera.";
        }
        $lines[] = "Mohon segera hubungi saya atau cek kondisi saya.";

        if (!empty($this->pesan)) {
            $lines[] = "";
            $lines[] = "📝 *Pesan Khusus*: \"" . $this->pesan . "\"";
        }

        $lines[] = "";
        $lines[] = "Terima kasih.";

        return "https://wa.me/{$wa}?text=" . rawurlencode(implode("\n", $lines));
    }

    /**
     * Relasi ke User (Pengguna pemilik kontak darurat)
     */
    public function pengguna()
    {
        return $this->belongsTo(User::class, 'id_pengguna');
    }
}
