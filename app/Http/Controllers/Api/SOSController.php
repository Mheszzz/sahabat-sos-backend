<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SOS;
use App\Events\SOSCreated;
use App\Events\SOSUpdateStatus;
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

        // broadcast
        broadcast(new SOSCreated($sos))->toOthers();

        return response()->json([
            'message' => 'Sinyal SOS berhasil dikirim, mencari relawan terdekat.',
            'data' => $sos
        ], 201);
    }

    // menampilkan SOS yg Aktif untuk Pengguna
    public function getActiveUserSOS(Request $request)
    {
        $sos = SOS::with(['pengguna', 'relawan'])
            ->where('id_pengguna', $request->user()->id)
            ->whereIn('status_sos', ['aktif', 'proses'])
            ->first();

        if (!$sos) {
            return response()->json([
                'message' => 'Tidak ada sinyal SOS aktif.',
                'data' => null
            ]);
        }

        return response()->json([
            'data' => $sos
        ]);
    }

    // menampilkan SOS yg Aktif untuk semua Relawan
    public function getActiveRelawanSOS()
    {
        $sosList = SOS::with(['pengguna', 'relawan'])
            ->where('status_sos', 'aktif')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $sosList
        ]);
    }

    //menampilkan SOS berdasarkan ID 
    public function show($id)
    {
        $sos = SOS::with(['pengguna', 'relawan'])->findOrFail($id);

        return response()->json([
            'data' => $sos
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status_sos' => 'required|in:proses,selesai',
        ]);

        $userId = $request->user()->id;

        //  Jika relawan ingin mengambil tugas (ubah status dari 'aktif' ke 'proses')
        if ($request->status_sos === 'proses') {
            
            // Hanya baris yang masih 'aktif' yang akan ter-update.
            $updated = SOS::where('id', $id)
                ->where('status_sos', 'aktif') // Kunci keamanan race condition
                ->update([
                    'status_sos' => 'proses',
                    'id_relawan' => $userId,
                    'updated_at' => now(),
                ]);

            // Jika tidak ada baris yang ter-update, berarti SOS sudah diambil oleh relawan lain
            if (!$updated) {
                return response()->json([
                    'message' => 'SOS ini sudah diambil oleh relawan lain.'
                ], 400);
            }

            // Ambil data SOS yang sudah berhasil di-update
            $sos = SOS::findOrFail($id);

        } else {
            // 2. Jika statusnya diubah ke 'selesai'
            $sos = SOS::findOrFail($id);
            $sos->status_sos = $request->status_sos;
            $sos->save();
        }

        // Refresh relasi agar data pelapor & relawan dimuat
        $sos->load(['pengguna', 'relawan']);

        // Broadcast update ke Reverb (WebSocket)
        broadcast(new SOSUpdateStatus($sos))->toOthers();

        return response()->json([
            'message' => 'Status SOS berhasil diperbarui.',
            'data'    => $sos
        ]);
    }

    // menampilkan SOS aktif yang sedang ditangani oleh relawan
    public function activeTask(Request $request)
    {
        $sos = SOS::with(['pengguna', 'relawan'])
            ->where('id_relawan', $request->user()->id)
            ->where('status_sos', 'proses')
            ->first();

        return response()->json([
            'data' => $sos
        ]);
    }

    //melihat hostory sos untuk user
    public function getUserSOSHistory(Request $request)
    {
        $sosHistory = SOS::with(['relawan'])
            ->where('id_pengguna', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10); 

        return response()->json([
            'data' => $sosHistory
        ]);
    }
}