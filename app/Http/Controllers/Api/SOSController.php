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
use App\Models\SOSRejection;
use App\Models\SOSActivity;
use App\Services\MapboxService;
use App\HasGeoCalculations;
use Illuminate\Support\Facades\Cache;

class SOSController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'latitude'        => 'required|numeric',
            'longitude'       => 'required|numeric',
            'battery_level'   => 'nullable|integer|between:0,100',
            'signal_strength' => 'nullable|string|max:50',
            'device_info'     => 'nullable',
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
            'id_pengguna'     => $userId,
            'latitude'        => $lat,
            'longitude'       => $lng,
            'status_sos'      => 'aktif',
            'waktu_sos'       => now(),
            'battery_level'   => $request->battery_level ?? 85,
            'signal_strength' => $request->signal_strength ?? '4G',
            'device_info'     => $request->device_info ?? null,
        ]);

        // Catat log aktivitas awal
        SOSActivity::record(
            $sos->id,
            'sos_dipicu',
            "Sinyal darurat SOS diaktifkan oleh {$request->user()->name}",
            $userId,
            [
                'latitude'      => $lat,
                'longitude'     => $lng,
                'battery_level' => $sos->battery_level,
                'signal'        => $sos->signal_strength,
            ]
        );

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
    public function getActiveRelawanSOS(Request $request)
    {
        $userId = $request->user()->id;

        $sos = SOS::with('pengguna')
            ->where('status_sos', 'aktif')
            ->whereNull('id_relawan')
            ->whereDoesntHave('rejections', function ($query) use ($userId) {
                $query->where('id_relawan', $userId);
            })
            ->orderBy('created_at', 'desc')
            ->first();

        return response()->json(['data' => $sos]);
    }

    // Menampilkan SOS berdasarkan ID beserta status perangkat dan activity log
    public function show($id)
    {
        $sos = SOS::with(['pengguna', 'relawan', 'activities.user'])->findOrFail($id);

        $activityLog = $sos->activities->map(function ($act) {
            return [
                'id'          => $act->id,
                'action'      => $act->action,
                'description' => $act->description,
                'actor'       => $act->user ? $act->user->name : 'Sistem',
                'user_id'     => $act->user_id,
                'role'        => $act->user ? $act->user->role : null,
                'extra_data'  => $act->extra_data,
                'created_at'  => $act->created_at ? $act->created_at->toIso8601String() : null,
            ];
        });

        $data = $sos->toArray();
        $data['activity_log'] = $activityLog;

        return response()->json([
            'data' => $data
        ]);
    }

    // Endpoint khusus untuk mengambil list riwayat aktivitas kasus SOS
    public function getActivities($id)
    {
        $sos = SOS::findOrFail($id);
        $activities = $sos->activities()->with('user')->get()->map(function ($act) {
            return [
                'id'          => $act->id,
                'action'      => $act->action,
                'description' => $act->description,
                'actor'       => $act->user ? $act->user->name : 'Sistem',
                'user_id'     => $act->user_id,
                'role'        => $act->user ? $act->user->role : null,
                'extra_data'  => $act->extra_data,
                'created_at'  => $act->created_at ? $act->created_at->toIso8601String() : null,
            ];
        });

        return response()->json([
            'message'      => "Riwayat aktivitas kasus #SOS-{$sos->id}",
            'sos_id'       => $sos->id,
            'total'        => $activities->count(),
            'activity_log' => $activities,
            'activities'   => $activities,
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status_sos' => 'required|in:proses,selesai',
        ]);

        $user = $request->user();
        $userId = $user->id;

        // Jika relawan ingin mengambil tugas (ubah status dari 'aktif' ke 'proses')
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

            SOSActivity::record(
                $sos->id,
                'relawan_menerima',
                "Relawan {$user->name} menerima tugas dan sedang menuju lokasi",
                $userId
            );

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

            SOSActivity::record(
                $sos->id,
                'sos_selesai',
                "Kasus SOS berhasil diselesaikan oleh relawan {$user->name}",
                $userId
            );
        }

        // Refresh relasi agar data pelapor & relawan dimuat
        $sos->load(['pengguna', 'relawan', 'activities.user']);

        // Broadcast update ke Reverb (WebSocket)
        broadcast(new SOSUpdateStatus($sos))->toOthers();

        $data = $sos->toArray();
        $data['activity_log'] = $sos->activities->map(function ($act) {
            return [
                'id'          => $act->id,
                'action'      => $act->action,
                'description' => $act->description,
                'actor'       => $act->user ? $act->user->name : 'Sistem',
                'user_id'     => $act->user_id,
                'role'        => $act->user ? $act->user->role : null,
                'created_at'  => $act->created_at ? $act->created_at->toIso8601String() : null,
            ];
        });

        return response()->json([
            'message' => 'Status SOS berhasil diperbarui.',
            'data'    => $data
        ]);
    }

    // menampilkan SOS aktif yang sedang ditangani oleh relawan
    public function activeTask(Request $request, MapboxService $mapboxService)
    {
        $user = $request->user();

        $sos = SOS::with(['pengguna', 'relawan', 'activities.user'])
            ->where('id_relawan', $user->id)
            ->where('status_sos', 'proses')
            ->first();

        if (!$sos) {
            return response()->json([
                'message' => 'Tidak ada tugas SOS aktif.',
                'data'    => null
            ]);
        }

        // 1. Ambil lokasi relawan dari Redis (Fallback: DB)
        $relawanLocation = Cache::get("relawan:{$user->id}:location");
        $relawanLat = $relawanLocation['latitude'] ?? $user->latitude;
        $relawanLng = $relawanLocation['longitude'] ?? $user->longitude;

        // 2. Minta rute jalan dari Mapbox
        $routeData = null;
        if ($relawanLat && $relawanLng && $sos->latitude && $sos->longitude) {
            $routeData = $mapboxService->getRoute(
                (float) $relawanLat,
                (float) $relawanLng,
                (float) $sos->latitude,
                (float) $sos->longitude
            );

            // 3. Simpan Polyline ke Redis untuk acuan off-route
            if ($routeData && isset($routeData['polyline'])) {
                Cache::put("sos:{$sos->id}:polyline", $routeData['polyline'], 3600);
            }
        }

        $data = $sos->toArray();
        $data['route_info'] = $routeData;

        return response()->json([
            'data' => $data
        ]);
    }

    // melihat history sos untuk user
    public function getUserSOSHistory(Request $request)
    {
        $sosHistory = SOS::with(['relawan', 'activities.user'])
            ->where('id_pengguna', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10); 

        return response()->json([
            'data' => $sosHistory
        ]);
    }

    // pengguna membatalkan SOS yang masih aktif atau dalam proses
    public function cancel(Request $request, $id)
    {
        // Validasi opsional: alasan_batal boleh dikirim, boleh tidak
        $request->validate([
            'alasan_batal' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $userId = $user->id;

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

        SOSActivity::record(
            $sos->id,
            'sos_dibatalkan',
            "Sinyal SOS dibatalkan oleh {$user->name}" . ($sos->alasan_batal ? " (Alasan: {$sos->alasan_batal})" : ''),
            $userId,
            ['alasan_batal' => $sos->alasan_batal]
        );

        $sos->load(['pengguna', 'relawan']);

        broadcast(new SOSUpdateStatus($sos))->toOthers();

        return response()->json([
            'message' => 'Sinyal SOS berhasil dibatalkan.',
            'data'    => $sos
        ]);
    }

    // relawan menolak SOS yang ditawarkan, memunculkan sos baru yang aktif untuk relawan tersebut
    public function rejectSOS(Request $request, $id)
    {
        $user = $request->user();
        $userId = $user->id;

        $sos = SOS::where('id', $id)
            ->where('status_sos', 'aktif')
            ->whereNull('id_relawan')
            ->first();

        if (!$sos) {
            return response()->json([
                'message' => 'SOS sudah diproses relawan lain atau tidak lagi aktif.'
            ], 400);
        }

        // 1. Simpan penolakan ke database
        SOSRejection::firstOrCreate([
            'id_sos'     => $sos->id,
            'id_relawan' => $userId,
        ]);

        // Catat aktivitas penolakan
        SOSActivity::record(
            $sos->id,
            'relawan_menolak',
            "Relawan {$user->name} menolak / melewati panggilan SOS ini",
            $userId
        );

        // 2. Eskalasi otomatis ke radius 3km
        EscalateSOSJob::dispatchSync($sos->id);

        // 3. Panggil ulang fungsi pencarian SOS aktif untuk relawan ini
        $nextSosData = $this->getActiveRelawanSOS($request)->getData()->data;

        return response()->json([
            'message'  => 'SOS berhasil dilewati.',
            'next_sos' => $nextSosData
        ]);
    }
}