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
use App\Models\Laporan;
use App\Http\Controllers\Api\KasusAktifController;

class SOSController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        $latInput = $request->input('latitude');
        $lngInput = $request->input('longitude');

        // Deteksi koordinat kosong / tidak valid / gagal lock GPS (0, 0) / izin lokasi mati (null / '')
        $isEmptyCoords = (
            $latInput === null ||
            $latInput === '' ||
            $lngInput === null ||
            $lngInput === '' ||
            !is_numeric($latInput) ||
            !is_numeric($lngInput) ||
            ((float) $latInput == 0.0 && (float) $lngInput == 0.0) ||
            (float) $latInput < -90 || (float) $latInput > 90 ||
            (float) $lngInput < -180 || (float) $lngInput > 180
        );

        $isFallback = false;
        $fallbackSource = null;
        $fallbackLabel = null;
        $fallbackLat = null;
        $fallbackLng = null;

        if ($isEmptyCoords) {
            // 1. Hierarki Fallback 1: Profil Pengguna
            if ($user && $user->latitude && $user->longitude && (float) $user->latitude != 0.0 && (float) $user->longitude != 0.0) {
                $fallbackLat = (float) $user->latitude;
                $fallbackLng = (float) $user->longitude;
                $fallbackSource = 'profile';
                $fallbackLabel = 'Profil Pengguna';
                $isFallback = true;
            }
            // 2. Hierarki Fallback 2: Riwayat Laporan Terakhir Pengguna
            elseif ($user) {
                $lastLaporan = Laporan::where('id_pengguna', $user->id)
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->where('latitude', '!=', 0)
                    ->where('longitude', '!=', 0)
                    ->latest('id')
                    ->first();

                if ($lastLaporan) {
                    $fallbackLat = (float) $lastLaporan->latitude;
                    $fallbackLng = (float) $lastLaporan->longitude;
                    $fallbackSource = 'riwayat_laporan';
                    $fallbackLabel = 'Riwayat Laporan Terakhir';
                    $isFallback = true;
                }
            }

            // 3. Hierarki Fallback 3: Riwayat Kasus SOS Terakhir Pengguna
            if (!$isFallback && $user) {
                $lastSos = SOS::where('id_pengguna', $user->id)
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->where('latitude', '!=', 0)
                    ->where('longitude', '!=', 0)
                    ->latest('id')
                    ->first();

                if ($lastSos) {
                    $fallbackLat = (float) $lastSos->latitude;
                    $fallbackLng = (float) $lastSos->longitude;
                    $fallbackSource = 'riwayat_sos';
                    $fallbackLabel = 'Riwayat Kasus SOS Terakhir';
                    $isFallback = true;
                }
            }

            if ($isFallback) {
                $request->merge([
                    'latitude'  => $fallbackLat,
                    'longitude' => $fallbackLng,
                ]);
                Log::info("SOS latitude/longitude fallback to {$fallbackLabel} for user #{$user->id}: lat={$fallbackLat}, lng={$fallbackLng}");
            } else {
                // Cegah penyimpanan titik (0, 0) Null Island jika koordinat tidak valid dan profil kosong
                return response()->json([
                    'message' => 'Satelit GPS belum terkunci (koordinat 0.0) atau izin lokasi dinonaktifkan, dan profil Anda belum memiliki koordinat tersimpan. Mohon aktifkan GPS atau lengkapi alamat di profil.',
                    'errors'  => [
                        'latitude'  => ['Koordinat GPS tidak valid atau satelit belum terkunci.'],
                        'longitude' => ['Koordinat GPS tidak valid atau satelit belum terkunci.'],
                    ],
                ], 422);
            }
        }

        $request->validate([
            'latitude'        => 'required|numeric|between:-90,90',
            'longitude'       => 'required|numeric|between:-180,180',
            'battery_level'   => 'nullable|integer|between:0,100',
            'signal_strength' => 'nullable|string|max:50',
            'trigger_source'  => 'nullable|string|max:50',
            'device_info'     => 'nullable',
        ]);

        $lat = (float) $request->latitude;
        $lng = (float) $request->longitude;

        $userId = $request->user()->id;

        // Cek apakah pengguna sudah punya laporan SOS yang masih aktif
        $activeSos = SOS::where('id_pengguna', $userId)
            ->whereIn('status_sos', ['belum ditangani', 'ditangani'])
            ->first();

        if ($activeSos) {
            return response()->json([
                'message' => 'Anda masih memiliki sinyal SOS aktif yang sedang diproses.'
            ], 422);
        }

        // Siapkan device_info
        $deviceInfo = $request->device_info;
        if (is_string($deviceInfo)) {
            $deviceInfo = json_decode($deviceInfo, true) ?? [];
        }
        if (!is_array($deviceInfo)) {
            $deviceInfo = [];
        }

        if ($request->filled('trigger_source')) {
            $deviceInfo['trigger_source'] = $request->trigger_source;
        }

        if ($isFallback) {
            $deviceInfo['is_gps_fallback'] = true;
            $deviceInfo['gps_status'] = 'fallback_' . $fallbackSource;
            $deviceInfo['gps_fallback_source'] = $fallbackLabel;
            $deviceInfo['gps_note'] = "Koordinat darurat menggunakan {$fallbackLabel} (GPS perangkat 0,0 atau izin lokasi dinonaktifkan).";
            $deviceInfo['original_coordinates'] = [
                'latitude'  => $latInput,
                'longitude' => $lngInput,
            ];
        }

        $batteryLevel = $request->battery_level
            ?? ($deviceInfo['mobile']['battery_level'] ?? ($deviceInfo['battery_level'] ?? 85));

        $signalStrength = $request->signal_strength
            ?? ($deviceInfo['mobile']['signal_strength'] ?? ($deviceInfo['signal_strength'] ?? '4G'));

        // Simpan data SOS ke database
        $sos = SOS::create([
            'id_pengguna'     => $userId,
            'latitude'        => $lat,
            'longitude'       => $lng,
            'status_sos'      => 'belum ditangani',
            'waktu_sos'       => now(),
            'battery_level'   => (int) $batteryLevel,
            'signal_strength' => (string) $signalStrength,
            'device_info'     => $deviceInfo,
        ]);

        // Catat log aktivitas awal
        SOSActivity::record(
            $sos->id,
            'sos_dipicu',
            "Sinyal darurat SOS diaktifkan oleh {$request->user()->name}" . ($isFallback ? " (Menggunakan lokasi {$fallbackLabel})" : ""),
            $userId,
            [
                'latitude'        => $lat,
                'longitude'       => $lng,
                'is_gps_fallback' => $isFallback,
                'fallback_source' => $fallbackSource,
                'battery_level'   => $sos->battery_level,
                'signal'          => $sos->signal_strength,
            ]
        );

        if ($isFallback) {
            SOSActivity::record(
                $sos->id,
                'gps_fallback',
                "GPS perangkat tidak terkunci (0,0 atau izin lokasi mati). Titik darurat otomatis dialihkan ke {$fallbackLabel} (Lat: {$lat}, Lng: {$lng}).",
                $userId,
                [
                    'fallback_source'      => $fallbackSource,
                    'fallback_label'       => $fallbackLabel,
                    'latitude'             => $lat,
                    'longitude'            => $lng,
                    'original_coordinates' => [
                        'latitude'  => $latInput,
                        'longitude' => $lngInput,
                    ],
                ]
            );
        }

        // 1. Tahap 1: Broadcast ke semua relawan tersedia dalam radius 2 km
        $volunteers2km = User::where('role', 'relawan')
            ->where('status_ketersediaan', 'tersedia')
            ->nearby($lat, $lng, 2.0)
            ->get();

        $volunteerIds = $volunteers2km->pluck('id')->toArray();

        Log::info("SOS ID {$sos->id}: Memulai broadcast tahap 1 (radius 2 km). Ditemukan {$volunteers2km->count()} relawan tersedia.");

        if ($volunteers2km->isNotEmpty()) {
            try {
                broadcast(new SOSCreated($sos, $volunteerIds, 2.0));
            } catch (\Throwable $e) {
                Log::warning("Gagal broadcast SOSCreated pada store: " . $e->getMessage());
            }
        } else {
            Log::info("SOS ID {$sos->id}: Belum ada relawan tersedia dalam radius 2 km. Melakukan broadcast darurat ke seluruh relawan.");
            try {
                broadcast(new SOSCreated($sos, null, 2.0));
            } catch (\Throwable $e) {
                Log::warning("Gagal broadcast darurat SOSCreated pada store: " . $e->getMessage());
            }
        }

        // 2. Tahap 2: Tunggu 30 detik, jika belum ada yang menerima, eskalasi bertingkat ke radius 3 km
        EscalateSOSJob::dispatch($sos->id, 3.0)->delay(now()->addSeconds(30));

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
            ->whereIn('status_sos', ['belum ditangani', 'ditangani'])
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
            ->whereIn('status_sos', ['belum ditangani', 'aktif'])
            ->whereNull('id_relawan')
            ->whereDoesntHave('rejections', function ($query) use ($userId) {
                $query->where('id_relawan', $userId);
            })
            ->orderBy('created_at', 'desc')
            ->get();

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
            'data'         => $activities,
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status_sos' => 'required|in:belum ditangani,ditangani,selesai,batal',
        ]);

        $user = $request->user();
        $userId = $user->id;

        // Jika relawan ingin mengambil tugas (ubah status ke 'ditangani')
        if ($request->status_sos === 'ditangani') {
            
            // Baris yang masih 'belum ditangani', 'aktif', atau SUDAH ditugaskan ke relawan ini
            $updated = SOS::where('id', $id)
                ->where(function ($q) use ($userId) {
                    $q->whereIn('status_sos', ['belum ditangani', 'aktif'])
                      ->orWhere(function ($q2) use ($userId) {
                          $q2->whereIn('status_sos', ['ditangani', 'proses'])
                             ->where('id_relawan', $userId);
                      });
                })
                ->update([
                    'status_sos' => 'ditangani',
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
                ->where('status_sos', 'ditangani')
                ->first();

            if (!$sos) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak untuk menyelesaikan SOS ini atau status SOS tidak valid.'
                ], 403);
            }
            $sos->status_sos = 'selesai';
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
        try {
            broadcast(new SOSUpdateStatus($sos));
        } catch (\Throwable $e) {
            Log::warning("Gagal broadcast SOSUpdateStatus pada updateStatus: " . $e->getMessage());
        }

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
            ->whereIn('status_sos', ['ditangani', 'proses', 'aktif'])
            ->latest('updated_at')
            ->first();

        if (!$sos) {
            // Fallback: cek apakah ada tugas laporan aktif yang sedang ditangani relawan ini
            $laporan = Laporan::with(['pengguna', 'relawan'])
                ->where('id_relawan', $user->id)
                ->whereIn('status', ['ditangani', 'proses'])
                ->latest('updated_at')
                ->first();

            if ($laporan) {
                $laporanData = KasusAktifController::transformItem($laporan, 'laporan', true);
                return response()->json([
                    'message' => 'Tugas laporan aktif.',
                    'data'    => $laporanData,
                ]);
            }

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
        // Validasi opsional: alasan_batal boleh dikirim, boleh tidak (mendukung alias alasan / reason)
        $request->validate([
            'alasan_batal' => 'nullable|string|max:255',
            'alasan'       => 'nullable|string|max:255',
            'reason'       => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $userId = $user->id;

        $sos = SOS::where('id', $id)
            ->where('id_pengguna', $userId)
            ->whereIn('status_sos', ['belum ditangani', 'ditangani', 'aktif', 'proses'])
            ->first();

        if (!$sos) {
            return response()->json([
                'message' => 'Sinyal SOS tidak ditemukan atau sudah tidak dapat dibatalkan.'
            ], 404);
        }

        $alasan = $request->input('alasan_batal') ?? $request->input('alasan') ?? $request->input('reason');

        // Ambil nilai dari request, jika tidak diisi akan otomatis null
        $sos->status_sos = 'batal';
        $sos->alasan_batal = $alasan;
        $sos->save();

        SOSActivity::record(
            $sos->id,
            'sos_dibatalkan',
            "Sinyal SOS dibatalkan oleh {$user->name}" . ($sos->alasan_batal ? " (Alasan: {$sos->alasan_batal})" : ''),
            $userId,
            ['alasan_batal' => $sos->alasan_batal]
        );

        $sos->load(['pengguna', 'relawan']);

        try {
            broadcast(new SOSUpdateStatus($sos));
        } catch (\Throwable $e) {
            Log::warning("Gagal broadcast SOSUpdateStatus pada cancelSOS: " . $e->getMessage());
        }

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
            ->whereIn('status_sos', ['belum ditangani', 'aktif'])
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