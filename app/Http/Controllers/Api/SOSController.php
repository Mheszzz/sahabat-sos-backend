<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SOS;
use App\Events\SOSCreated;
use Illuminate\Http\Request;

class SOSController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $userId = $request->user()->id;

        // Cek apakah pengguna sudah punya laporan SOS yang masih aktif
        $activeSos = SOS::where('id_pengguna', $userId)
            ->whereIn('status_sos', ['aktif', 'proses'])
            ->first();

        if ($activeSos) {
            return response()->json([
                'message' => 'Anda masih memiliki sinyal SOS aktif yang sedang diproses.'
            ], 422);
        }

        // Simpan data SOS ke database
        $sos = SOS::create([
            'id_pengguna' => $userId,
            'lokasi_sos' => $request->latitude . ',' . $request->longitude,
            'status_sos' => 'aktif',
            'waktu_sos' => now(),
        ]);

        // Triggers event untuk menyiarkan pesan via Reverb & Push Notification
        broadcast(new SOSCreated($sos))->toOthers();

        return response()->json([
            'message' => 'Sinyal SOS berhasil dikirim, mencari relawan terdekat.',
            'data' => $sos
        ], 201);
    }
}