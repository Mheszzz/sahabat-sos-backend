<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SOS;
use App\Models\Laporan;
use App\Models\User;
use App\Models\SOSActivity;
use App\Events\SOSUpdateStatus;
use App\Events\PoskoSireneTriggered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DashboardAdminController extends Controller
{
    /**
     * 1. GET FULL DASHBOARD DATA (GET /api/admin/dashboard)
     */
    public function index(Request $request)
    {
        // -------------------------------------------------------------
        // A. Kartu Metrik KPI (Top Stat Cards)
        // -------------------------------------------------------------
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $sosHariIni = SOS::whereDate('waktu_sos', $today)->count();
        $sosKemarin = SOS::whereDate('waktu_sos', $yesterday)->count();
        $sosTrenPercentage = $this->calculateTrendPercentage($sosHariIni, $sosKemarin);

        $laporanHariIni = Laporan::whereDate('created_at', $today)->count();
        $laporanKemarin = Laporan::whereDate('created_at', $yesterday)->count();
        $laporanTrenPercentage = $this->calculateTrendPercentage($laporanHariIni, $laporanKemarin);

        $daruratSosAktifCount = SOS::whereIn('status_sos', ['belum ditangani', 'ditangani', 'aktif', 'proses'])->count();

        // Relawan stats
        $totalRelawan = User::where('role', 'relawan')->count();
        $relawanSedangBertugasCount = SOS::whereIn('status_sos', ['belum ditangani', 'ditangani', 'aktif', 'proses'])
            ->whereNotNull('id_relawan')
            ->pluck('id_relawan')
            ->unique()
            ->count();
            
        $relawanOffCount = User::where('role', 'relawan')
            ->whereNotNull('status_ketersediaan')
            ->where('status_ketersediaan', '!=', 'tersedia')
            ->count();
            
        $relawanSiagaCount = max(0, $totalRelawan - $relawanSedangBertugasCount - $relawanOffCount);

        $kpi = [
            'panggilan_sos_hari_ini' => [
                'total' => $sosHariIni,
                'tren'  => $sosTrenPercentage >= 0 ? "+{$sosTrenPercentage}%" : "{$sosTrenPercentage}%",
            ],
            'jumlah_laporan_hari_ini' => [
                'total' => $laporanHariIni,
                'tren'  => $laporanTrenPercentage >= 0 ? "+{$laporanTrenPercentage}%" : "{$laporanTrenPercentage}%",
            ],
            'darurat_sos_aktif' => [
                'total' => $daruratSosAktifCount,
                'is_kritis' => $daruratSosAktifCount > 0,
            ],
            'relawan_siaga_aktif' => [
                'total_personel'  => $totalRelawan,
                'siaga_bebas'     => $relawanSiagaCount,
                'dalam_misi'      => $relawanSedangBertugasCount,
                'off_istirahat'   => $relawanOffCount,
            ],
        ];

        // -------------------------------------------------------------
        // B. Antrean Kasus Darurat (Incident Live Queue)
        // -------------------------------------------------------------
        $activeSosList = SOS::with(['pengguna.kontakDarurat', 'relawan', 'activities.user'])
            ->whereIn('status_sos', ['belum ditangani', 'ditangani', 'aktif', 'proses'])
            ->orderBy('created_at', 'desc')
            ->get();

        $antreanKasus = $activeSosList->map(function ($sos) {
            $pengguna = $sos->pengguna;
            $relawan = $sos->relawan;

            // Tipe disabilitas & kontak darurat
            $disabilitas = $pengguna->kategori_user ?? 'umum';
            $kontakDaruratList = $pengguna ? $pengguna->kontakDarurat->map(function ($k) {
                return [
                    'id'           => $k->id,
                    'nama'         => $k->nama,
                    'no_telp'      => $k->no_telp,
                    'tipe'         => $k->tipe ?? 'sekunder',
                    'terima_notif' => (bool) $k->terima_notif,
                ];
            }) : [];

            $activityLog = $sos->activities->map(function ($act) {
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

            return [
                'id_kasus'      => "#SOS-{$sos->id}",
                'raw_id'        => $sos->id,
                'tipe_kasus'    => 'SOS',
                'asal_sinyal'   => 'Tombol Hardware SOS Aktif / Mobile App',
                'waktu_relatif' => $sos->created_at ? $sos->created_at->diffForHumans() : 'Baru saja',
                'created_at'    => $sos->created_at ? $sos->created_at->toIso8601String() : null,
                'judul_insiden' => 'Sinyal Darurat SOS ' . ucfirst($disabilitas),
                'device_info'   => $sos->device_info,
                'lokasi'        => [
                    'latitude'  => (float) $sos->latitude,
                    'longitude' => (float) $sos->longitude,
                    'alamat'    => $pengguna->alamat ?? 'Lokasi GPS Korban',
                ],
                'profil_korban' => [
                    'id'                     => $pengguna->id ?? null,
                    'nama'                   => $pengguna->name ?? 'Anonim',
                    'no_telp'                => $pengguna->no_telp ?? '-',
                    'jenis_disabilitas'      => $disabilitas,
                    'catatan_medis'          => $pengguna->catatan_medis ?? '-',
                    'kontak_darurat_keluarga'=> $kontakDaruratList,
                ],
                'alokasi_relawan' => $relawan ? [
                    'id'               => $relawan->id,
                    'nama'             => $relawan->name,
                    'no_telp'          => $relawan->no_telp,
                    'pekerjaan'        => $relawan->pekerjaan ?? 'Relawan Pendamping',
                    'eta'              => '~4 menit',
                    'status_penanganan'=> 'Sedang Menuju TKP',
                ] : null,
                'status'        => $sos->status_sos,
                'activity_log'  => $activityLog,
            ];
        });

        // -------------------------------------------------------------
        // C. Peta Pantauan GIS Data
        // -------------------------------------------------------------
        $titikDarurat = $activeSosList->map(function ($sos) {
            return [
                'id'          => $sos->id,
                'id_kasus'    => "#SOS-{$sos->id}",
                'latitude'    => (float) $sos->latitude,
                'longitude'   => (float) $sos->longitude,
                'status'      => $sos->status_sos,
                'korban'      => $sos->pengguna->name ?? 'Pengguna SOS',
                'relawan'     => $sos->relawan->name ?? null,
                'device_info' => $sos->device_info,
            ];
        });

        $posisiRelawan = User::where('role', 'relawan')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->map(function ($relawan) {
                $activeSOS = SOS::with('pengguna')->whereIn('status_sos', ['belum ditangani', 'ditangani', 'aktif', 'proses'])
                    ->where('id_relawan', $relawan->id)
                    ->first();
                $isBusy = $activeSOS !== null;
                $isOff = $relawan->status_ketersediaan && $relawan->status_ketersediaan !== 'tersedia';

                $statusLabel = 'siaga_bebas';
                if ($isBusy) {
                    $statusLabel = 'dalam_misi';
                } elseif ($isOff) {
                    $statusLabel = 'off_istirahat';
                }

                $misiData = null;
                if ($isBusy) {
                    $misiData = [
                        'id_kasus' => "#SOS-{$activeSOS->id}",
                        'tujuan'   => $activeSOS->pengguna->alamat ?? 'Lokasi GPS Korban',
                    ];
                }

                return [
                    'id'           => $relawan->id,
                    'nama'         => $relawan->name,
                    'no_telp'      => $relawan->no_telp,
                    'latitude'     => (float) $relawan->latitude,
                    'longitude'    => (float) $relawan->longitude,
                    'status'       => $statusLabel,
                    'kompetensi'   => $this->determineVolunteerCompetency($relawan),
                    'lokasi_user'  => $relawan->lokasi_user ?? 'Area Siaga',
                    'misi'         => $misiData,
                    'telemetri'    => [
                        'baterai' => '94%',
                        'akurasi' => '±3m',
                    ]
                ];
            });

        $gisMap = [
            'titik_darurat'     => $titikDarurat,
            'posisi_relawan'    => $posisiRelawan,
        ];

        // -------------------------------------------------------------
        // D. Modul Statistik & Analisis Kebutuhan
        // -------------------------------------------------------------
        $totalSosCount = SOS::count();
        $totalResolvedSos = SOS::where('status_sos', 'selesai')->count();
        $tingkatKeberhasilan = $totalSosCount > 0 
            ? round(($totalResolvedSos / $totalSosCount) * 100, 1) 
            : 98.1; // Default benchmark jika belum ada data historis

        // Sebaran kategori kebutuhan difabel
        $userCounts = User::where('role', 'pengguna')
            ->select('kategori_user', DB::raw('count(*) as total'))
            ->groupBy('kategori_user')
            ->pluck('total', 'kategori_user')
            ->toArray();

        $totalUsers = array_sum($userCounts) ?: 1;

        $tunanetraCount = $userCounts['tunanetra'] ?? 0;
        $tunarunguCount = ($userCounts['tunarungu'] ?? 0) + ($userCounts['tunawicara'] ?? 0);
        $tunadaksaCount = $userCounts['tunadaksa'] ?? 0;
        $umumCount = $userCounts['umum'] ?? 0;

        $sebaranDisabilitas = [
            'tunanetra' => [
                'label'      => 'Disabilitas Netra (Tunanetra)',
                'jumlah'     => $tunanetraCount,
                'persentase' => round(($tunanetraCount / $totalUsers) * 100, 1),
            ],
            'tunarungu_wicara' => [
                'label'      => 'Disabilitas Rungu & Wicara (Tunarungu)',
                'jumlah'     => $tunarunguCount,
                'persentase' => round(($tunarunguCount / $totalUsers) * 100, 1),
            ],
            'disabilitas_fisik' => [
                'label'      => 'Disabilitas Fisik / Motorik (Kursi Roda)',
                'jumlah'     => $tunadaksaCount,
                'persentase' => round(($tunadaksaCount / $totalUsers) * 100, 1),
            ],
            'umum' => [
                'label'      => 'Umum / Lainnya',
                'jumlah'     => $umumCount,
                'persentase' => round(($umumCount / $totalUsers) * 100, 1),
            ],
            'tingkat_keberhasilan_bantuan' => "{$tingkatKeberhasilan}%",
        ];

        // Persentase Kategori Laporan
        $laporanCategories = Laporan::select('kategori_laporan', DB::raw('count(*) as total'))
            ->groupBy('kategori_laporan')
            ->get();

        $totalLaporans = Laporan::count() ?: 1;
        $persentaseKategoriLaporan = $laporanCategories->map(function ($item) use ($totalLaporans) {
            return [
                'kategori'   => $item->kategori_laporan,
                'jumlah'     => $item->total,
                'persentase' => round(($item->total / $totalLaporans) * 100, 1),
            ];
        });

        $analisis = [
            'sebaran_disabilitas'         => $sebaranDisabilitas,
            'persentase_kategori_laporan' => $persentaseKategoriLaporan,
        ];

        return response()->json([
            'message' => 'Berhasil mengambil data Dashboard Admin Sahabat SOS',
            'data'    => [
                'kpi'           => $kpi,
                'antrean_kasus' => $antreanKasus,
                'gis_map'       => $gisMap,
                'analisis'      => $analisis,
            ],
        ], 200);
    }

    /**
     * 2. QUICK DISPATCH RELAWAN TERDEKAT (GET /api/admin/dashboard/quick-dispatch)
     */
    public function getQuickDispatchRelawan(Request $request)
    {
        $lat = $request->query('latitude');
        $lng = $request->query('longitude');
        $sosId = $request->query('sos_id');
        $laporanId = $request->query('laporan_id') ?? $request->query('id_laporan');

        if ($sosId && (!$lat || !$lng)) {
            $sos = SOS::find($sosId);
            if ($sos) {
                $lat = $sos->latitude;
                $lng = $sos->longitude;
            }
        } elseif ($laporanId && (!$lat || !$lng)) {
            $laporan = Laporan::find($laporanId);
            if ($laporan) {
                $lat = $laporan->latitude;
                $lng = $laporan->longitude;
            }
        }

        if (!$lat || !$lng) {
            return response()->json([
                'message' => 'Parameter latitude dan longitude atau sos_id / laporan_id diperlukan',
            ], 422);
        }

        $relawans = User::where('role', 'relawan')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->nearby((float)$lat, (float)$lng, 10.0)
            ->get();

        $data = $relawans->map(function ($relawan) {
            $isBusy = SOS::whereIn('status_sos', ['belum ditangani', 'ditangani', 'aktif', 'proses'])
                ->where('id_relawan', $relawan->id)
                ->exists() || Laporan::whereIn('status', ['belum ditangani', 'ditangani', 'aktif', 'proses'])
                ->where('id_relawan', $relawan->id)
                ->exists();

            $distanceKm = isset($relawan->distance) ? round((float)$relawan->distance, 2) : 0;
            $distanceText = $distanceKm < 1 ? round($distanceKm * 1000) . ' m' : "{$distanceKm} km";

            return [
                'id'            => $relawan->id,
                'nama'          => $relawan->name,
                'no_telp'       => $relawan->no_telp,
                'kompetensi'    => $this->determineVolunteerCompetency($relawan),
                'jarak_km'      => $distanceKm,
                'jarak_text'    => $distanceText,
                'status'        => $isBusy ? 'sedang_bertugas' : 'siaga',
                'latitude'      => (float)$relawan->latitude,
                'longitude'     => (float)$relawan->longitude,
            ];
        });

        return response()->json([
            'message' => 'Berhasil mengambil daftar relawan terdekat',
            'total'   => $data->count(),
            'data'    => $data,
        ], 200);
    }

    /**
     * 3. DISPATCH / TUGASKAN RELAWAN KE KASUS (SOS ATAU LAPORAN) (POST /api/admin/dashboard/dispatch)
     */
    public function dispatchRelawan(Request $request)
    {
        $tipe = strtolower($request->input('tipe', ''));
        $sosId = $request->input('sos_id');
        $laporanId = $request->input('laporan_id') ?? $request->input('id_laporan');
        $kasusId = $request->input('kasus_id') ?? $request->input('id');

        if (!$tipe) {
            $tipe = ($laporanId || ($kasusId && !$sosId && Laporan::where('id', $kasusId)->exists())) ? 'laporan' : 'sos';
        }

        $targetId = $tipe === 'laporan' ? ($laporanId ?? $kasusId) : ($sosId ?? $kasusId);

        $validator = Validator::make([
            'tipe'       => $tipe,
            'target_id'  => $targetId,
            'relawan_id' => $request->relawan_id,
        ], [
            'tipe'       => 'required|in:sos,laporan',
            'target_id'  => 'required',
            'relawan_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $relawan = User::where('role', 'relawan')->find($request->relawan_id);
        if (!$relawan) {
            return response()->json(['message' => 'User yang dipilih bukan relawan.'], 422);
        }

        $admin = $request->user();

        if ($tipe === 'laporan') {
            $laporan = Laporan::find($targetId);
            if (!$laporan) {
                return response()->json(['message' => 'Laporan tidak ditemukan.'], 404);
            }
            if ($laporan->status === 'selesai') {
                return response()->json(['message' => 'Laporan ini sudah berstatus selesai.'], 422);
            }

            $laporan->update([
                'id_relawan' => $relawan->id,
                'status'     => 'ditangani',
            ]);

            return response()->json([
                'message' => "Berhasil menugaskan relawan {$relawan->name} ke Laporan #{$laporan->id}",
                'data'    => KasusAktifController::transformItem($laporan->fresh(['pengguna', 'relawan']), 'laporan', true),
            ], 200);
        }

        // Default: SOS
        $sos = SOS::find($targetId);
        if (!$sos) {
            return response()->json(['message' => 'Sinyal SOS tidak ditemukan.'], 404);
        }
        if ($sos->status_sos === 'selesai' || $sos->status_sos === 'batal') {
            return response()->json(['message' => 'Sinyal SOS ini sudah tidak aktif.'], 422);
        }

        $sos->update([
            'id_relawan' => $relawan->id,
            'status_sos' => 'ditangani',
        ]);

        // Catat aktivitas dispatch relawan oleh admin
        SOSActivity::record(
            $sos->id,
            'admin_dispatch',
            "Admin {$admin->name} menugaskan relawan {$relawan->name} ke lokasi kasus SOS",
            $admin->id,
            [
                'relawan_id'   => $relawan->id,
                'relawan_nama' => $relawan->name,
            ]
        );

        // Broadcast event pembaruan status SOS
        broadcast(new SOSUpdateStatus($sos))->toOthers();

        return response()->json([
            'message' => "Berhasil menugaskan relawan {$relawan->name} ke SOS #{$sos->id}",
            'data'    => $sos->fresh(['pengguna', 'relawan', 'activities.user']),
        ], 200);
    }

    /**
     * 4. MARK SOS AS SELESAI (PUT /api/admin/dashboard/sos/{id}/selesai)
     */
    public function selesaiSOS(Request $request, $id)
    {
        $sos = SOS::find($id);

        if (!$sos) {
            return response()->json(['message' => 'Sinyal SOS tidak ditemukan'], 404);
        }

        $sos->update([
            'status_sos' => 'selesai',
        ]);

        $admin = $request->user();
        SOSActivity::record(
            $sos->id,
            'sos_selesai',
            "Kasus SOS #{$sos->id} ditandai selesai oleh Admin {$admin->name}",
            $admin->id
        );

        broadcast(new SOSUpdateStatus($sos))->toOthers();

        return response()->json([
            'message' => "Kasus SOS #{$sos->id} berhasil ditandai selesai.",
            'data'    => $sos->fresh(['pengguna', 'relawan', 'activities.user']),
        ], 200);
    }

    /**
     * 5. LOG RIWAYAT AKTIVITAS KASUS (GET /api/admin/sos/{id}/activities)
     */
    public function getSosActivities(Request $request, $id)
    {
        $sos = SOS::with(['pengguna', 'relawan'])->findOrFail($id);

        $activities = SOSActivity::where('sos_id', $sos->id)
            ->with('user')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($act) {
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
            'status'       => $sos->status_sos,
            'device_info'  => $sos->device_info,
            'total'        => $activities->count(),
            'activity_log' => $activities,
            'activities'   => $activities,
        ], 200);
    }

    /**
     * 6. BUNYIKAN SIRENE POSKO (ALARM MASSAL) (POST /api/admin/posko/sirene)
     */
    public function triggerSirenePosko(Request $request)
    {
        $request->validate([
            'action'    => 'nullable|string|in:on,off,trigger',
            'pesan'     => 'nullable|string|max:255',
            'sos_id'    => 'nullable|exists:s_o_s,id',
            'radius_km' => 'nullable|numeric',
        ]);

        $user = $request->user();
        $action = $request->input('action', 'trigger');
        $pesan = $request->input('pesan', 'Peringatan Darurat: Sirene Posko Pusat Diaktifkan!');
        $sosId = $request->input('sos_id');

        $payload = [
            'sirene_active' => in_array($action, ['on', 'trigger']),
            'action'        => $action,
            'pesan'         => $pesan,
            'sos_id'        => $sosId,
            'posko'         => [
                'nama'      => 'POSKO PUSAT SAHABAT SOS JAKARTA',
                'latitude'  => -6.2088,
                'longitude' => 106.8456,
            ],
            'radius_km'     => (float) $request->input('radius_km', 5.0),
            'triggered_by'  => [
                'id'        => $user->id,
                'name'      => $user->name,
                'role'      => $user->role,
            ],
            'timestamp'     => now()->toIso8601String(),
        ];

        // Broadcast event ke Reverb WebSocket
        broadcast(new PoskoSireneTriggered($payload))->toOthers();

        // Jika terhubung ke kasus SOS spesifik, catat ke log aktivitas SOS
        if ($sosId) {
            SOSActivity::record(
                $sosId,
                'sirene_posko',
                "Sirene darurat posko dibunyikan oleh {$user->name} untuk kasus #SOS-{$sosId}",
                $user->id,
                ['action' => $action, 'pesan' => $pesan]
            );
        }

        return response()->json([
            'message' => 'Sirene Posko berhasil dioperasikan dan dibroadcast ke seluruh posko/relawan.',
            'data'    => $payload,
        ], 200);
    }

    /**
     * 7. EKSPOR LAPORAN KE CSV (GET /api/admin/laporan/export)
     */
    public function exportLaporanCsv(Request $request)
    {
        $query = Laporan::with(['pengguna', 'relawan'])->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('kategori_laporan')) {
            $query->where('kategori_laporan', $request->kategori_laporan);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $laporans = $query->get();

        $filename = 'laporan_sahabat_sos_' . now()->format('Ymd_His') . '.csv';

        $callback = function () use ($laporans) {
            $handle = fopen('php://output', 'w');

            // Tambahkan UTF-8 BOM agar terbaca sempurna di Microsoft Excel
            fputs($handle, "\xEF\xBB\xBF");

            // Header Kolom CSV
            fputcsv($handle, [
                'ID Laporan',
                'Nomor Kasus',
                'Tanggal & Waktu',
                'Kategori Laporan',
                'Deskripsi / Pesan Kejadian',
                'Nama Pelapor',
                'No. Telp Pelapor',
                'Disabilitas Pelapor',
                'Latitude',
                'Longitude',
                'Alamat / Patokan Lokasi',
                'Status Laporan',
                'Ditangani Oleh (Relawan)',
                'No. Telp Relawan',
            ]);

            foreach ($laporans as $lap) {
                $pelapor = $lap->pengguna;
                $relawan = $lap->relawan;

                fputcsv($handle, [
                    $lap->id,
                    '#LAP-' . $lap->id,
                    $lap->created_at ? $lap->created_at->format('Y-m-d H:i:s') : '-',
                    $lap->kategori_laporan ?? '-',
                    $lap->deskripsi ?? ($lap->pesan_cepat ?? '-'),
                    $pelapor ? $pelapor->name : 'Anonim',
                    $pelapor ? $pelapor->no_telp : '-',
                    $pelapor ? ($pelapor->kategori_user ?? 'umum') : 'umum',
                    $lap->latitude ?? '-',
                    $lap->longitude ?? '-',
                    $lap->lokasi_laporan ?? ($lap->alamat ?? '-'),
                    $lap->status ?? 'aktif',
                    $relawan ? $relawan->name : 'Belum Ditugaskan',
                    $relawan ? $relawan->no_telp : '-',
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ]);
    }

    /**
     * 5. GLOBAL SEARCH (⌘K) (GET /api/admin/dashboard/search)
     */
    public function globalSearch(Request $request)
    {
        $query = trim($request->query('q', ''));

        if (empty($query)) {
            return response()->json([
                'message' => 'Kata kunci pencarian kosong',
                'data'    => [
                    'kasus_sos' => [],
                    'pengguna'  => [],
                    'relawan'   => [],
                ],
            ], 200);
        }

        // Search SOS by ID or raw number
        $cleanId = preg_replace('/[^0-9]/', '', $query);
        $sosQuery = SOS::with(['pengguna', 'relawan']);
        if (!empty($cleanId)) {
            $sosQuery->where('id', $cleanId);
        } else {
            $sosQuery->whereHas('pengguna', function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%");
            });
        }
        $kasusSos = $sosQuery->take(5)->get();

        // Search Pengguna
        $penggunaList = User::where('role', 'pengguna')
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('no_telp', 'like', "%{$query}%");
            })
            ->take(5)
            ->get();

        // Search Relawan
        $relawanList = User::where('role', 'relawan')
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('no_telp', 'like', "%{$query}%");
            })
            ->take(5)
            ->get();

        return response()->json([
            'message' => "Hasil pencarian untuk '{$query}'",
            'data'    => [
                'kasus_sos' => $kasusSos,
                'pengguna'  => $penggunaList,
                'relawan'   => $relawanList,
            ],
        ], 200);
    }

    // -------------------------------------------------------------
    // Private Helper Functions
    // -------------------------------------------------------------

    private function calculateTrendPercentage($todayCount, $yesterdayCount)
    {
        if ($yesterdayCount == 0) {
            return $todayCount > 0 ? 100 : 0;
        }

        return round((($todayCount - $yesterdayCount) / $yesterdayCount) * 100);
    }

    private function determineVolunteerCompetency($relawan)
    {
        if ($relawan->pekerjaan) {
            return $relawan->pekerjaan;
        }

        // Custom default mapping based on ID parity / mock competencies for demo
        $competencies = [
            'Navigasi Tunanetra & P3K',
            'Juru Bahasa Isyarat / JBI',
            'Pendamping Mobilitas & Kursi Roda',
        ];

        return $competencies[$relawan->id % count($competencies)];
    }
}
