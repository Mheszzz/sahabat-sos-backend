<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\SOS;
use App\Events\SOSCreated;
use App\Events\SOSUpdateStatus;
use Illuminate\Http\Request;
use App\Jobs\EscalateSOSJob;
use App\Models\User;

class SOSController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $lat = (float) $request->latitude;
        $lng = (float) $request->longitude;

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
            'latitude' => $lat,
            'longitude' => $lng,
            'status_sos' => 'aktif',
            'waktu_sos' => now(),
        ]);

        // broadcast
        $nearestVolunteer = User::where('role', 'relawan')
            ->nearby($lat, $lng, 1.0)
            ->first();

        if ($nearestVolunteer) {
            // Kirim broadcast khusus ke relawan tersebut
            Log::info("SOS ID {$sos->id}: Ditemukan 1 relawan (< 1km) -> User ID: {$nearestVolunteer->id}. Memulai delay eskalasi 30 detik.");
            broadcast(new SOSCreated($sos, $nearestVolunteer->id))->toOthers();
            
            // Tunda eskalasi ke radius 3 km selama 30 detik
            EscalateSOSJob::dispatch($sos->id)->delay(now()->addSeconds(30));
        } else {
            // Jika tidak ada relawan di 1 km, langsung broadcast ke radius 3 km saat itu juga
            Log::info("SOS ID {$sos->id}: Tidak ada relawan dalam radius 1km. Langsung eskalasi ke radius 3km.");
            EscalateSOSJob::dispatchSync($sos->id);
        }

        return response()->json([
            'message' => 'Sinyal SOS berhasil dikirim.',
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
            // Jika statusnya diubah ke 'selesai'
            $sos = SOS::where('id', $id)
                ->where('id_relawan', $userId) // Pastikan hanya relawan penanggung jawab yang bisa menyelesaikan
                ->where('status_sos', 'proses')
                ->first();

            if (!$sos) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak untuk menyelesaikan SOS ini atau status SOS tidak valid.'
                ], 403);
            }
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

    public function cancel(Request $request, $id)
    {
        // Validasi opsional: alasan_batal boleh dikirim, boleh tidak
        $request->validate([
            'alasan_batal' => 'nullable|string|max:255',
        ]);

        $userId = $request->user()->id;

        $sos = SOS::where('id', $id)
            ->where('id_pengguna', $userId)
            ->whereIn('status_sos', ['aktif', 'proses'])
            ->first();

        if (!$sos) {
            return response()->json([
                'message' => 'Sinyal SOS tidak ditemukan atau sudah tidak dapat dibatalkan.'
            ], 404);
        }

        // Ambil nilai dari request, jika tidak diisi akan otomatis null
        $sos->status_sos = 'batal';
        $sos->alasan_batal = $request->input('alasan_batal'); 
        $sos->save();

        $sos->load(['pengguna', 'relawan']);

        broadcast(new SOSUpdateStatus($sos))->toOthers();

        return response()->json([
            'message' => 'Sinyal SOS berhasil dibatalkan.',
            'data'    => $sos
        ]);
    }
}