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
use App\Models\CatatanPenanganan;
use App\Http\Controllers\Api\KasusAktifController;

class SOSController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        $userId = $user ? $user->id : null;

        // 1. Cek prioritas: Jika pengguna sudah punya SOS aktif, segera kembalikan data kasus aktif
        if ($userId) {
            $activeSos = SOS::where('id_pengguna', $userId)
                ->whereIn('status_sos', ['belum ditangani', 'ditangani'])
                ->first();

            if ($activeSos) {
                return response()->json([
                    'message' => 'Anda masih memiliki sinyal SOS aktif yang sedang diproses.',
                    'data'    => $activeSos,
                ], 422);
            }
        }

        // 2. Sanitasi level baterai agar kegagalan sensor (misal -1) tidak pernah membatalkan panggilan darurat
        if ($request->has('battery_level')) {
            $battery = $request->input('battery_level');
            if ($battery !== null && (!is_numeric($battery) || (int) $battery < 0 || (int) $battery > 100)) {
                $request->merge(['battery_level' => null]);
            }
        }

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
        if (!is_numeric($batteryLevel) || (int) $batteryLevel < 0 || (int) $batteryLevel > 100) {
            $batteryLevel = 85;
        }

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

        // Siapkan deskripsi aktivitas berdasarkan sumber pemicu
        $activityDesc = "Sinyal darurat SOS diaktifkan oleh {$request->user()->name}" . ($isFallback ? " (Menggunakan lokasi {$fallbackLabel})" : "");
        $triggerSrc = $sos->device_info['trigger_source'] ?? 'app_button';
        if ($triggerSrc === 'tuya_physical_button') {
            $activityDesc = "Sinyal darurat SOS diaktifkan melalui Tombol Fisik Darurat Tuya" . ($isFallback ? " (Lokasi: {$fallbackLabel})" : "");
        } elseif (in_array($triggerSrc, ['smartwatch_button', 'smartwatch_fall_detection'])) {
            $watchModel = $sos->device_info['smartwatch']['device_model'] ?? 'Smartwatch';
            $bpm = $sos->device_info['smartwatch']['heart_rate'] ?? null;
            $bpmText = $bpm ? " (Detak jantung: {$bpm} BPM)" : '';
            if ($triggerSrc === 'smartwatch_fall_detection') {
                $activityDesc = "Sinyal darurat SOS dipicu otomatis oleh Deteksi Jatuh pada {$watchModel}{$bpmText}" . ($isFallback ? " (Lokasi: {$fallbackLabel})" : "");
            } else {
                $activityDesc = "Sinyal darurat SOS diaktifkan melalui Tombol Darurat pada {$watchModel}{$bpmText}" . ($isFallback ? " (Lokasi: {$fallbackLabel})" : "");
            }
        }

        // Catat log aktivitas awal
        SOSActivity::record(
            $sos->id,
            'sos_dipicu',
            $activityDesc,
            $userId,
            [
                'latitude'        => $lat,
                'longitude'       => $lng,
                'is_gps_fallback' => $isFallback,
                'fallback_source' => $fallbackSource,
                'battery_level'   => $sos->battery_level,
                'signal'          => $sos->signal_strength,
                'trigger_source'  => $triggerSrc,
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

        // 1. Tahap 1: Broadcast ke semua relawan tersedia dalam radius awal (default 2 km)
        $initialRadius = (float) config('sos.initial_radius', 2.0);
        $escalationDelay = (int) config('sos.escalation_delay_seconds', 30);

        $volunteers2km = User::where('role', 'relawan')
            ->where('status_verifikasi', 'terverifikasi')
            ->where('is_active', true)
            ->where('status_ketersediaan', 'tersedia')
            ->nearby($lat, $lng, $initialRadius)
            ->get();

        $volunteerIds = $volunteers2km->pluck('id')->toArray();

        Log::info("SOS ID {$sos->id}: Memulai broadcast tahap 1 (radius {$initialRadius} km). Ditemukan {$volunteers2km->count()} relawan tersedia.");

        if ($volunteers2km->isNotEmpty()) {
            try {
                broadcast(new SOSCreated($sos, $volunteerIds, $initialRadius));
            } catch (\Throwable $e) {
                Log::warning("Gagal broadcast SOSCreated pada store: " . $e->getMessage());
            }
        } else {
            Log::info("SOS ID {$sos->id}: Belum ada relawan tersedia dalam radius {$initialRadius} km. Menyiarkan ke Posko Command Center dan menunggu eskalasi bertingkat ke 3.0 km.");
            try {
                // Sinyal SOS tetap dipancarkan ke Posko Command Center, tanpa memicu broadcast umum ke relawan
                broadcast(new SOSCreated($sos, [], $initialRadius));
            } catch (\Throwable $e) {
                Log::warning("Gagal broadcast awal SOSCreated ke Command Center pada store: " . $e->getMessage());
            }
        }

        // 2. Tahap 2: Tunggu jeda eskalasi (default 30 detik), jika belum ada yang menerima, eskalasi bertingkat ke radius 3 km
        EscalateSOSJob::dispatch($sos->id, 3.0)->delay(now()->addSeconds($escalationDelay));

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
    public function show($id, Request $request)
    {
        $sos = SOS::with(['pengguna', 'relawan', 'activities.user'])->findOrFail($id);
        $user = $request->user();

        // Proteksi IDOR/BOLA:
        // - Pengguna hanya boleh melihat SOS miliknya sendiri.
        // - Relawan harus sudah terverifikasi dan aktif.
        if ($user) {
            if ($user->role === 'pengguna' && (int)$sos->id_pengguna !== (int)$user->id) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat kasus SOS ini.'
                ], 403);
            }

            if ($user->role === 'relawan' && ($user->status_verifikasi !== 'terverifikasi' || !$user->is_active)) {
                return response()->json([
                    'message' => 'Akses ditolak. Akun relawan Anda belum diverifikasi oleh administrator.'
                ], 403);
            }
        }

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
    public function getActivities($id, Request $request)
    {
        $sos = SOS::findOrFail($id);
        $user = $request->user();

        // Proteksi IDOR/BOLA:
        if ($user) {
            if ($user->role === 'pengguna' && (int)$sos->id_pengguna !== (int)$user->id) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat riwayat aktivitas kasus SOS ini.'
                ], 403);
            }

            if ($user->role === 'relawan' && ($user->status_verifikasi !== 'terverifikasi' || !$user->is_active)) {
                return response()->json([
                    'message' => 'Akses ditolak. Akun relawan Anda belum diverifikasi oleh administrator.'
                ], 403);
            }
        }

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
            'status_sos'         => 'required|in:belum ditangani,ditangani,selesai,batal',
            'alasan_batal'       => 'nullable|string|max:255',
            'alasan'             => 'nullable|string|max:255',
            'reason'             => 'nullable|string|max:255',
            'catatan'            => 'nullable|string|max:1000',
            'notes'              => 'nullable|string|max:1000',
            'catatan_penanganan' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        $userId = $user->id;

        // 1. Cabang: Relawan mengambil tugas (ubah status ke 'ditangani')
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

            // Jika tidak ada baris yang ter-update, periksa status riil kasus
            if (!$updated) {
                $currentSos = SOS::find($id);
                if ($currentSos) {
                    if ($currentSos->status_sos === 'batal') {
                        return response()->json([
                            'message' => 'Sinyal SOS telah dibatalkan oleh pengguna.'
                        ], 400);
                    }
                    if ($currentSos->status_sos === 'selesai') {
                        return response()->json([
                            'message' => 'Sinyal SOS telah selesai ditangani.'
                        ], 400);
                    }
                }
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

        // 2. Cabang: Menyelesaikan tugas SOS (status 'selesai')
        } elseif ($request->status_sos === 'selesai') {
            $sos = SOS::find($id);

            if (!$sos) {
                return response()->json([
                    'message' => 'Sinyal SOS tidak ditemukan.'
                ], 404);
            }

            // Pastikan relawan penanggung jawab (atau admin/superadmin) yang bisa menyelesaikan
            $isAuthorized = ($sos->id_relawan == $userId) || in_array($user->role, ['admin', 'superadmin']);
            if (!$isAuthorized) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak untuk menyelesaikan SOS ini atau status SOS tidak valid.'
                ], 403);
            }

            if (!in_array($sos->status_sos, ['ditangani', 'proses'])) {
                return response()->json([
                    'message' => 'Status SOS tidak valid untuk diselesaikan (status saat ini: ' . $sos->status_sos . ').'
                ], 400);
            }

            $sos->status_sos = 'selesai';
            $sos->save();

            // Simpan Catatan Medis / Tindakan Lapangan jika disertakan
            $catatanText = $request->input('catatan') ?? $request->input('notes') ?? $request->input('catatan_penanganan');
            if (!empty($catatanText)) {
                CatatanPenanganan::create([
                    'id_admin'   => $userId,
                    'id_sos'     => $sos->id,
                    'id_laporan' => null,
                    'catatan'    => $catatanText,
                ]);
            }

            $catatanDeskripsi = $catatanText ? " (Catatan Lapangan/Medis: {$catatanText})" : "";

            SOSActivity::record(
                $sos->id,
                'sos_selesai',
                "Kasus SOS berhasil diselesaikan oleh relawan {$user->name}{$catatanDeskripsi}",
                $userId,
                $catatanText ? ['catatan' => $catatanText] : null
            );

            // Kembalikan status ketersediaan relawan menjadi 'tersedia'
            if ($user->role === 'relawan') {
                $user->update(['status_ketersediaan' => 'tersedia']);
            }

            // Bersihkan cache rute & cooldown reroute polyline
            Cache::forget("sos:{$sos->id}:polyline");
            Cache::forget("sos:{$sos->id}:last_reroute");

        // 3. Cabang: Membatalkan sinyal/tugas SOS (status 'batal')
        } elseif ($request->status_sos === 'batal') {
            $sos = SOS::find($id);

            if (!$sos) {
                return response()->json([
                    'message' => 'Sinyal SOS tidak ditemukan.'
                ], 404);
            }

            // Pengguna pemilik sinyal SOS, relawan penanggung jawab, atau admin/superadmin berhak membatalkan
            $canCancel = ($sos->id_pengguna == $userId) ||
                         ($sos->id_relawan == $userId) ||
                         in_array($user->role, ['admin', 'superadmin']);

            if (!$canCancel) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak untuk membatalkan sinyal SOS ini.'
                ], 403);
            }

            if (in_array($sos->status_sos, ['selesai', 'batal'])) {
                return response()->json([
                    'message' => 'Sinyal SOS sudah berstatus ' . $sos->status_sos . ' dan tidak dapat dibatalkan.'
                ], 400);
            }

            $alasan = $request->input('alasan_batal') ?? $request->input('alasan') ?? $request->input('reason');
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

            // Kembalikan status relawan menjadi tersedia jika ada relawan yang ditugaskan
            if ($sos->id_relawan) {
                User::where('id', $sos->id_relawan)->where('role', 'relawan')->update(['status_ketersediaan' => 'tersedia']);
            }

            // Bersihkan cache rute
            Cache::forget("sos:{$sos->id}:polyline");
            Cache::forget("sos:{$sos->id}:last_reroute");

        } else {
            return response()->json([
                'message' => 'Perubahan status SOS ke \'' . $request->status_sos . '\' tidak didukung.'
            ], 422);
        }

        // Refresh relasi agar data pelapor & relawan dimuat
        $sos->load(['pengguna', 'relawan', 'activities.user', 'catatanPenanganan.admin']);

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

        // 1. Ambil lokasi relawan dari Redis (Fallback: DB)
        $relawanLocation = Cache::get("relawan:{$user->id}:location");
        $relawanLat = $relawanLocation['latitude'] ?? $user->latitude;
        $relawanLng = $relawanLocation['longitude'] ?? $user->longitude;

        if (!$sos) {
            // Fallback: cek apakah ada tugas laporan aktif yang sedang ditangani relawan ini
            $laporan = Laporan::with(['pengguna', 'relawan'])
                ->where('id_relawan', $user->id)
                ->whereIn('status', ['ditangani', 'proses'])
                ->latest('updated_at')
                ->first();

            if ($laporan) {
                $routeData = null;
                if ($relawanLat && $relawanLng && $laporan->latitude && $laporan->longitude) {
                    $routeData = $mapboxService->getRoute(
                        (float) $relawanLat,
                        (float) $relawanLng,
                        (float) $laporan->latitude,
                        (float) $laporan->longitude
                    );

                    if ($routeData && isset($routeData['polyline'])) {
                        Cache::put("laporan:{$laporan->id}:polyline", $routeData['polyline'], 3600);
                    }
                }

                $laporanData = KasusAktifController::transformItem($laporan, 'laporan', true);
                $laporanData['route_info'] = $routeData;
                $laporanData['polyline'] = $routeData['polyline'] ?? null;
                $laporanData['duration_formatted'] = $routeData['duration_formatted'] ?? null;
                $laporanData['distance_formatted'] = $routeData['distance_formatted'] ?? null;

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

        // 2. Minta rute jalan dari Mapbox / OSRM
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
        $data['polyline'] = $routeData['polyline'] ?? null;
        $data['duration_formatted'] = $routeData['duration_formatted'] ?? null;
        $data['distance_formatted'] = $routeData['distance_formatted'] ?? null;

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

        // Kembalikan status relawan menjadi tersedia jika ada relawan yang ditugaskan
        if ($sos->id_relawan) {
            User::where('id', $sos->id_relawan)->where('role', 'relawan')->update(['status_ketersediaan' => 'tersedia']);
        }

        // Bersihkan cache rute
        Cache::forget("sos:{$sos->id}:polyline");
        Cache::forget("sos:{$sos->id}:last_reroute");

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
        $request->validate([
            'alasan' => 'nullable|string|max:255',
        ]);
        $alasan = $request->input('alasan');

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

        // 1. Simpan penolakan ke database beserta alasan
        $rejection = SOSRejection::firstOrCreate(
            [
                'id_sos'     => $sos->id,
                'id_relawan' => $userId,
            ],
            [
                'alasan'     => $alasan,
            ]
        );
        if ($alasan && $rejection->alasan !== $alasan) {
            $rejection->update(['alasan' => $alasan]);
        }

        // Catat aktivitas penolakan dengan alasan
        $activityText = "Relawan {$user->name} menolak / melewati panggilan SOS ini" . ($alasan ? " (Alasan: {$alasan})" : '');
        SOSActivity::record(
            $sos->id,
            'relawan_menolak',
            $activityText,
            $userId,
            ['alasan' => $alasan]
        );

        // 2. Broadcast ulang ke relawan lain yang tersedia dalam radius 3 km tanpa menduplikasi antrean job delayed
        $rejectedRelawanIds = SOSRejection::where('id_sos', $sos->id)->pluck('id_relawan')->toArray();
        $otherVolunteers = User::where('role', 'relawan')
            ->where('status_verifikasi', 'terverifikasi')
            ->where('is_active', true)
            ->where('status_ketersediaan', 'tersedia')
            ->nearby((float) $sos->latitude, (float) $sos->longitude, 3.0)
            ->whereNotIn('id', $rejectedRelawanIds)
            ->get();

        if ($otherVolunteers->isNotEmpty()) {
            try {
                broadcast(new SOSCreated($sos, $otherVolunteers->pluck('id')->toArray(), 3.0));
            } catch (\Throwable $e) {
                Log::warning("Gagal broadcast ulang SOSCreated saat penolakan: " . $e->getMessage());
            }
        }

        // 3. Panggil ulang fungsi pencarian SOS aktif untuk relawan ini
        $nextSosData = $this->getActiveRelawanSOS($request)->getData()->data;

        return response()->json([
            'message'  => 'SOS berhasil dilewati.',
            'next_sos' => $nextSosData
        ]);
    }
}