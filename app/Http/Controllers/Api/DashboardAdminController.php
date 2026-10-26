<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SOS;
use App\Models\Laporan;
use App\Models\User;
use App\Models\SOSActivity;
use App\Events\SOSUpdateStatus;
use App\Events\LaporanCreated;
use App\Events\PoskoSireneTriggered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Carbon\CarbonInterface;

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

        $sosHariIni = SOS::where(function ($q) use ($today) {
            $q->whereDate('waktu_sos', $today)
              ->orWhere(function ($sub) use ($today) {
                  $sub->whereNull('waktu_sos')->whereDate('created_at', $today);
              });
        })->count();

        $sosKemarin = SOS::where(function ($q) use ($yesterday) {
            $q->whereDate('waktu_sos', $yesterday)
              ->orWhere(function ($sub) use ($yesterday) {
                  $sub->whereNull('waktu_sos')->whereDate('created_at', $yesterday);
              });
        })->count();
        $sosTrenPercentage = $this->calculateTrendPercentage($sosHariIni, $sosKemarin);

        $laporanHariIni = Laporan::where(function ($q) use ($today) {
            $q->whereDate('waktu_laporan', $today)
              ->orWhere(function ($sub) use ($today) {
                  $sub->whereNull('waktu_laporan')->whereDate('created_at', $today);
              });
        })->count();

        $laporanKemarin = Laporan::where(function ($q) use ($yesterday) {
            $q->whereDate('waktu_laporan', $yesterday)
              ->orWhere(function ($sub) use ($yesterday) {
                  $sub->whereNull('waktu_laporan')->whereDate('created_at', $yesterday);
              });
        })->count();
        $laporanTrenPercentage = $this->calculateTrendPercentage($laporanHariIni, $laporanKemarin);

        // Kasus Periode (Hari Ini, Minggu Lalu, Bulan Lalu)
        $startLastWeek = now()->subWeek()->startOfWeek(CarbonInterface::SUNDAY)->startOfDay();
        $endLastWeek = now()->subWeek()->endOfWeek(CarbonInterface::SATURDAY)->endOfDay();
        $sosMingguLalu = SOS::where(function ($q) use ($startLastWeek, $endLastWeek) {
            $q->whereBetween('waktu_sos', [$startLastWeek, $endLastWeek])
              ->orWhere(function ($sub) use ($startLastWeek, $endLastWeek) {
                  $sub->whereNull('waktu_sos')->whereBetween('created_at', [$startLastWeek, $endLastWeek]);
              });
        })->count();
        $laporanMingguLalu = Laporan::whereBetween('created_at', [$startLastWeek, $endLastWeek])->count();
        $totalMingguLalu = $sosMingguLalu + $laporanMingguLalu;

        $startLastMonth = now()->subMonth()->startOfMonth()->startOfDay();
        $endLastMonth = now()->subMonth()->endOfMonth()->endOfDay();
        $sosBulanLalu = SOS::where(function ($q) use ($startLastMonth, $endLastMonth) {
            $q->whereBetween('waktu_sos', [$startLastMonth, $endLastMonth])
              ->orWhere(function ($sub) use ($startLastMonth, $endLastMonth) {
                  $sub->whereNull('waktu_sos')->whereBetween('created_at', [$startLastMonth, $endLastMonth]);
              });
        })->count();
        $laporanBulanLalu = Laporan::whereBetween('created_at', [$startLastMonth, $endLastMonth])->count();
        $totalBulanLalu = $sosBulanLalu + $laporanBulanLalu;

        $daruratSosAktifCount = SOS::whereIn('status_sos', ['belum ditangani', 'ditangani', 'aktif', 'proses'])->count();

        // Relawan stats
        $totalRelawan = User::where('role', 'relawan')->where('is_active', true)->count();
        $relawanSedangBertugasCount = SOS::whereIn('status_sos', ['belum ditangani', 'ditangani', 'aktif', 'proses'])
            ->whereNotNull('id_relawan')
            ->pluck('id_relawan')
            ->unique()
            ->count();
            
        $relawanOffCount = User::where('role', 'relawan')
            ->where('is_active', true)
            ->whereNotNull('status_ketersediaan')
            ->whereNotIn('status_ketersediaan', ['tersedia', 'siaga'])
            ->count();
            
        $relawanSiagaCount = max(0, $totalRelawan - $relawanSedangBertugasCount - $relawanOffCount);

        $kpi = [
            'total_kasus_hari_ini' => $sosHariIni + $laporanHariIni,
            'total_kasus_minggu_lalu' => $totalMingguLalu,
            'total_kasus_bulan_lalu' => $totalBulanLalu,
            'kasus_periode' => [
                'hari_ini'    => $sosHariIni + $laporanHariIni,
                'minggu_lalu' => $totalMingguLalu,
                'bulan_lalu'  => $totalBulanLalu,
            ],
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
                'id_kasus'      => $sos->nomor_kasus ?? ("SOS-" . $sos->id),
                'nomor_kasus'   => $sos->nomor_kasus ?? ("SOS-" . $sos->id),
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
                    'eta'              => (function() use ($sos, $relawan) {
                        $cachedVolLoc = Cache::get("relawan:{$relawan->id}:location");
                        $volLat = $cachedVolLoc['latitude'] ?? $relawan->latitude;
                        $volLng = $cachedVolLoc['longitude'] ?? $relawan->longitude;
                        if ($sos->latitude && $sos->longitude && $volLat && $volLng) {
                            $distKm = $this->calculateHaversineDistance((float)$sos->latitude, (float)$sos->longitude, (float)$volLat, (float)$volLng);
                            $travelMins = max(2, (int) round(($distKm / 30) * 60 + 2));
                            return "~{$travelMins} menit";
                        }
                        return '~4 menit';
                    })(),
                    'status_penanganan'=> 'Sedang Menuju TKP',
                ] : null,
                'status'        => $sos->status_sos,
                'activity_log'  => $activityLog,
            ];
        });

        // -------------------------------------------------------------
        // C. Peta Pantauan GIS Data
        // -------------------------------------------------------------
        $titikDarurat = $activeSosList
            ->filter(function ($sos) {
                return !is_null($sos->latitude) && !is_null($sos->longitude) && (float) $sos->latitude != 0.0 && (float) $sos->longitude != 0.0;
            })
            ->map(function ($sos) {
                $idKasus = $sos->nomor_kasus ?? ("SOS-" . $sos->id);
                return [
                    'id'          => $sos->id,
                    'id_kasus'    => $idKasus,
                    'nomor_kasus' => $idKasus,
                    'latitude'    => (float) $sos->latitude,
                    'longitude'   => (float) $sos->longitude,
                    'status'      => $sos->status_sos,
                    'korban'      => $sos->pengguna->name ?? 'Pengguna SOS',
                    'relawan'     => $sos->relawan->name ?? null,
                    'device_info' => $sos->device_info,
                ];
            })
            ->values();

        $posisiRelawan = User::where('role', 'relawan')
            ->where('is_active', true)
            ->get()
            ->map(function ($relawan) {
                // Ambil koordinat GPS real-time dari cache redis jika baru di-broadcast oleh mobile app relawan
                $cachedLoc = Cache::get("relawan:{$relawan->id}:location");
                $lat = isset($cachedLoc['latitude']) ? (float) $cachedLoc['latitude'] : ($relawan->latitude !== null ? (float) $relawan->latitude : null);
                $lng = isset($cachedLoc['longitude']) ? (float) $cachedLoc['longitude'] : ($relawan->longitude !== null ? (float) $relawan->longitude : null);

                // Lewati jika koordinat belum tersedia sama sekali di cache maupun database
                if ($lat === null || $lng === null) {
                    return null;
                }

                $activeSOS = SOS::with('pengguna')->whereIn('status_sos', ['belum ditangani', 'ditangani', 'aktif', 'proses'])
                    ->where('id_relawan', $relawan->id)
                    ->first();
                $isBusy = $activeSOS !== null;
                $isOff = $relawan->status_ketersediaan && !in_array($relawan->status_ketersediaan, ['tersedia', 'siaga']);

                $statusLabel = 'siaga_bebas';
                if ($isBusy) {
                    $statusLabel = 'dalam_misi';
                } elseif ($isOff) {
                    $statusLabel = 'off_istirahat';
                }

                $misiData = null;
                if ($isBusy) {
                    $idKasus = $activeSOS->nomor_kasus ?? ("SOS-" . $activeSOS->id);
                    $misiData = [
                        'id_kasus'    => $idKasus,
                        'nomor_kasus' => $idKasus,
                        'tujuan'      => $activeSOS->pengguna->alamat ?? 'Lokasi GPS Korban',
                    ];
                }

                return [
                    'id'           => $relawan->id,
                    'nama'         => $relawan->name,
                    'no_telp'      => $relawan->no_telp,
                    'latitude'     => (float) $lat,
                    'longitude'    => (float) $lng,
                    'status'       => $statusLabel,
                    'kompetensi'   => $this->determineVolunteerCompetency($relawan),
                    'lokasi_user'  => $relawan->lokasi_user ?? 'Area Siaga',
                    'misi'         => $misiData,
                    'telemetri'    => [
                        'baterai' => isset($cachedLoc['baterai']) ? "{$cachedLoc['baterai']}%" : (isset($cachedLoc['battery']) ? "{$cachedLoc['battery']}%" : '92%'),
                        'akurasi' => isset($cachedLoc['akurasi']) ? "±{$cachedLoc['akurasi']}m" : (isset($cachedLoc['accuracy']) ? "±{$cachedLoc['accuracy']}m" : '±5m'),
                    ]
                ];
            })
            ->filter()
            ->values();

        $laporanAktifList = Laporan::with(['pengguna', 'relawan'])
            ->whereIn('status', ['belum ditangani', 'ditangani', 'menunggu', 'proses', 'aktif'])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('created_at', 'desc')
            ->get();

        $titikLaporan = $laporanAktifList->map(function ($lap) {
            $idKasus = $lap->nomor_kasus ?? ("LP-" . $lap->id);
            return [
                'id'          => $lap->id,
                'id_kasus'    => $idKasus,
                'id_laporan'  => $idKasus,
                'nomor_kasus' => $idKasus,
                'latitude'    => (float) $lap->latitude,
                'longitude'   => (float) $lap->longitude,
                'kategori'    => $lap->kategori_laporan,
                'status'      => $lap->status,
                'pelapor'     => $lap->pengguna->name ?? 'Pengguna',
                'relawan'     => $lap->relawan->name ?? null,
                'lokasi'      => $lap->lokasi_laporan ?? ($lap->pengguna->alamat ?? 'Lokasi Laporan'),
            ];
        });

        $gisMap = [
            'titik_darurat'      => $titikDarurat,
            'titik_darurat_sos'  => $titikDarurat,
            'titik_laporan'      => $titikLaporan,
            'titik_laporan_aktif'=> $titikLaporan,
            'posisi_relawan'     => $posisiRelawan,
        ];

        // -------------------------------------------------------------
        // D. Modul Statistik & Analisis Kebutuhan
        // -------------------------------------------------------------
        $totalSosCount = SOS::count();
        $totalResolvedSos = SOS::where('status_sos', 'selesai')->count();
        $tingkatKeberhasilan = $totalSosCount > 0
            ? round(($totalResolvedSos / $totalSosCount) * 100, 1)
            : 100.0;

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
        $lat = $request->query('latitude') ?? $request->query('lat');
        $lng = $request->query('longitude') ?? $request->query('lng');
        $sosId = $request->query('sos_id') ?? $request->query('id_sos');
        $laporanId = $request->query('laporan_id') ?? $request->query('id_laporan');
        $kasusId = $request->query('kasus_id') ?? $request->query('id');
        $tipe = strtolower($request->query('tipe', $request->query('type', '')));

        if (!$lat || !$lng) {
            if ($sosId) {
                $sos = SOS::find($sosId);
                if ($sos && $sos->latitude && $sos->longitude) {
                    $lat = $sos->latitude;
                    $lng = $sos->longitude;
                }
            } elseif ($laporanId) {
                $laporan = Laporan::find($laporanId);
                if ($laporan && $laporan->latitude && $laporan->longitude) {
                    $lat = $laporan->latitude;
                    $lng = $laporan->longitude;
                }
            } elseif ($kasusId) {
                if ($tipe === 'laporan' || Laporan::where('id', $kasusId)->exists()) {
                    $laporan = Laporan::find($kasusId);
                    if ($laporan && $laporan->latitude && $laporan->longitude) {
                        $lat = $laporan->latitude;
                        $lng = $laporan->longitude;
                    }
                } else {
                    $sos = SOS::find($kasusId);
                    if ($sos && $sos->latitude && $sos->longitude) {
                        $lat = $sos->latitude;
                        $lng = $sos->longitude;
                    }
                }
            }
        }

        $hasCoordinates = !empty($lat) && !empty($lng);
        $allActiveRelawans = User::where('role', 'relawan')
            ->where('is_active', true)
            ->get();

        $relawanList = $allActiveRelawans->map(function ($relawan) use ($hasCoordinates, $lat, $lng) {
            $isBusy = SOS::whereIn('status_sos', ['belum ditangani', 'ditangani', 'aktif', 'proses'])
                ->where('id_relawan', $relawan->id)
                ->exists() || Laporan::whereIn('status', ['belum ditangani', 'ditangani', 'aktif', 'proses'])
                ->where('id_relawan', $relawan->id)
                ->exists();

            $cachedLoc = Cache::get("relawan:{$relawan->id}:location");
            $rLat = isset($cachedLoc['latitude']) ? (float) $cachedLoc['latitude'] : ($relawan->latitude !== null ? (float) $relawan->latitude : null);
            $rLng = isset($cachedLoc['longitude']) ? (float) $cachedLoc['longitude'] : ($relawan->longitude !== null ? (float) $relawan->longitude : null);

            $distanceKm = null;
            $distanceText = '-';

            if ($hasCoordinates && $rLat !== null && $rLng !== null) {
                $distanceKm = round($this->calculateHaversineDistance((float)$lat, (float)$lng, $rLat, $rLng), 2);
                $distanceText = $distanceKm < 1 ? round($distanceKm * 1000) . ' m' : "{$distanceKm} km";
            }

            return [
                'id'            => $relawan->id,
                'nama'          => $relawan->name,
                'no_telp'       => $relawan->no_telp,
                'kompetensi'    => $this->determineVolunteerCompetency($relawan),
                'jarak_km'      => $distanceKm,
                'jarak_text'    => $distanceText,
                'status'        => $isBusy ? 'sedang_bertugas' : 'siaga',
                'status_ketersediaan' => $relawan->status_ketersediaan ?? 'tersedia',
                'latitude'      => $rLat,
                'longitude'     => $rLng,
            ];
        });

        // Filter relawan terdekat dalam radius 10 km jika koordinat tersedia
        $inRadius = $relawanList->filter(function ($r) {
            return $r['jarak_km'] !== null && $r['jarak_km'] <= 10.0;
        })->sortBy('jarak_km')->values();

        $isFallback = false;
        if ($hasCoordinates && $inRadius->isNotEmpty()) {
            $data = $inRadius;
        } else {
            $isFallback = true;
            // Fallback: Urutkan siaga terlebih dahulu, kemudian jarak jika ada
            $data = $relawanList->sortBy([
                ['status', 'asc'],
                ['jarak_km', 'asc'],
            ])->values();
        }

        return response()->json([
            'message' => ($hasCoordinates && !$isFallback)
                ? 'Berhasil mengambil daftar relawan terdekat'
                : 'Berhasil mengambil daftar relawan',
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
            if ($laporan->status === 'ditangani' || !empty($laporan->id_relawan)) {
                return response()->json(['message' => 'Laporan ini sudah ditangani oleh relawan. Untuk mengganti relawan, silakan masuk ke Detail Kasus.'], 422);
            }

            $laporan->update([
                'id_relawan' => $relawan->id,
                'status'     => 'ditangani',
            ]);

            try {
                broadcast(new LaporanCreated($laporan));
            } catch (\Throwable $e) {
                Log::warning("Gagal broadcast LaporanCreated pada dispatchRelawan: " . $e->getMessage());
            }

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
        if ($sos->status_sos === 'ditangani' || !empty($sos->id_relawan)) {
            return response()->json(['message' => 'Kasus SOS ini sudah ditangani oleh relawan. Untuk mengganti relawan, silakan masuk ke Detail Kasus.'], 422);
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
        try {
            broadcast(new SOSUpdateStatus($sos));
        } catch (\Throwable $e) {
            Log::warning("Gagal broadcast SOSUpdateStatus pada dispatchRelawan: " . $e->getMessage());
        }

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

        try {
            broadcast(new SOSUpdateStatus($sos))->toOthers();
        } catch (\Throwable $e) {
            Log::warning("Gagal broadcast SOSUpdateStatus pada selesaiSOS: " . $e->getMessage());
        }

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
            'data'         => $activities,
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
                'nama'      => 'POSKO PUSAT SAHABAT SOS D.I. YOGYAKARTA',
                'latitude'  => -7.7956,
                'longitude' => 110.3695,
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
        try {
            broadcast(new PoskoSireneTriggered($payload));
        } catch (\Throwable $e) {
            Log::warning("Gagal broadcast PoskoSireneTriggered: " . $e->getMessage());
        }

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
     * Sanitasi input teks CSV untuk mencegah kerentanan CSV Formula Injection (CWE-1236).
     */
    private function sanitizeCsvValue($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        $str = (string) $value;
        if (preg_match('/^[=+\-@\t\r]/', $str)) {
            return "'" . $str;
        }
        return $str;
    }

    /**
     * Hitung durasi penanganan kasus dari dibuat hingga selesai.
     */
    private function calculateDurasiPenanganan($createdAt, $updatedAt, $status): string
    {
        $statusNormalized = strtolower(trim((string) $status));
        if (in_array($statusNormalized, ['selesai', 'resolved', 'ditangani', 'dibatalkan'])) {
            if ($createdAt && $updatedAt) {
                $seconds = Carbon::parse($createdAt)->diffInSeconds(Carbon::parse($updatedAt));
                $minutes = floor($seconds / 60);
                $remainingSeconds = $seconds % 60;
                if ($minutes > 0) {
                    return "{$minutes} menit {$remainingSeconds} detik";
                }
                return "{$remainingSeconds} detik";
            }
            return '-';
        }
        return 'Sedang Berlangsung';
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
                'Durasi Penanganan',
            ]);

            foreach ($laporans as $lap) {
                $pelapor = $lap->pengguna;
                $relawan = $lap->relawan;
                $durasi = $this->calculateDurasiPenanganan($lap->created_at, $lap->updated_at, $lap->status);

                fputcsv($handle, [
                    $this->sanitizeCsvValue($lap->id),
                    $this->sanitizeCsvValue($lap->nomor_kasus ?? ('#LAP-' . $lap->id)),
                    $this->sanitizeCsvValue($lap->created_at ? $lap->created_at->format('Y-m-d H:i:s') : '-'),
                    $this->sanitizeCsvValue($lap->kategori_laporan ?? '-'),
                    $this->sanitizeCsvValue($lap->deskripsi ?? ($lap->pesan_cepat ?? '-')),
                    $this->sanitizeCsvValue($pelapor ? $pelapor->name : 'Anonim'),
                    $this->sanitizeCsvValue($pelapor ? $pelapor->no_telp : '-'),
                    $this->sanitizeCsvValue($pelapor ? ($pelapor->kategori_user ?? 'umum') : 'umum'),
                    $this->sanitizeCsvValue($lap->latitude ?? '-'),
                    $this->sanitizeCsvValue($lap->longitude ?? '-'),
                    $this->sanitizeCsvValue($lap->lokasi_laporan ?? ($lap->alamat ?? '-')),
                    $this->sanitizeCsvValue($lap->status ?? 'aktif'),
                    $this->sanitizeCsvValue($relawan ? $relawan->name : 'Belum Ditugaskan'),
                    $this->sanitizeCsvValue($relawan ? $relawan->no_telp : '-'),
                    $this->sanitizeCsvValue($durasi),
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
     * 7b. EKSPOR KASUS SOS KE CSV (GET /api/admin/sos/export)
     */
    public function exportSosCsv(Request $request)
    {
        $query = SOS::with(['pengguna', 'relawan'])->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status_sos', $request->status);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $sosList = $query->get();

        $filename = 'kasus_sos_sahabat_sos_' . now()->format('Ymd_His') . '.csv';

        $callback = function () use ($sosList) {
            $handle = fopen('php://output', 'w');

            // Tambahkan UTF-8 BOM agar terbaca sempurna di Microsoft Excel
            fputs($handle, "\xEF\xBB\xBF");

            // Header Kolom CSV Kasus Darurat SOS
            fputcsv($handle, [
                'ID SOS',
                'Nomor Kasus',
                'Tanggal & Waktu Kejadian',
                'Tingkat Urgensi',
                'Nama Korban / Pengguna',
                'No. Telp Korban',
                'Disabilitas Korban',
                'Latitude',
                'Longitude',
                'Alamat / Lokasi Darurat',
                'Status Kasus SOS',
                'Ditangani Oleh (Relawan)',
                'No. Telp Relawan',
                'Durasi Penanganan',
            ]);

            foreach ($sosList as $sos) {
                $pengguna = $sos->pengguna;
                $relawan = $sos->relawan;
                $durasi = $this->calculateDurasiPenanganan($sos->created_at, $sos->updated_at, $sos->status_sos);

                fputcsv($handle, [
                    $this->sanitizeCsvValue($sos->id),
                    $this->sanitizeCsvValue($sos->nomor_kasus ?? ('#SOS-' . $sos->id)),
                    $this->sanitizeCsvValue($sos->created_at ? $sos->created_at->format('Y-m-d H:i:s') : '-'),
                    $this->sanitizeCsvValue('Kritis / Darurat'),
                    $this->sanitizeCsvValue($pengguna ? $pengguna->name : 'Pengguna SOS'),
                    $this->sanitizeCsvValue($pengguna ? $pengguna->no_telp : '-'),
                    $this->sanitizeCsvValue($pengguna ? ($pengguna->kategori_user ?? 'umum') : 'umum'),
                    $this->sanitizeCsvValue($sos->latitude ?? '-'),
                    $this->sanitizeCsvValue($sos->longitude ?? '-'),
                    $this->sanitizeCsvValue($pengguna ? ($pengguna->alamat ?? '-') : '-'),
                    $this->sanitizeCsvValue($sos->status_sos ?? 'aktif'),
                    $this->sanitizeCsvValue($relawan ? $relawan->name : 'Belum Ditugaskan'),
                    $this->sanitizeCsvValue($relawan ? $relawan->no_telp : '-'),
                    $this->sanitizeCsvValue($durasi),
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
     * 7c. EKSPOR SEMUA KASUS (SOS & LAPORAN) TERPADU KE CSV (GET /api/admin/kasus/export)
     */
    public function exportKasusCsv(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $sosQuery = SOS::with(['pengguna', 'relawan'])->orderBy('created_at', 'desc');
        $lapQuery = Laporan::with(['pengguna', 'relawan'])->orderBy('created_at', 'desc');

        if ($startDate) {
            $sosQuery->whereDate('created_at', '>=', $startDate);
            $lapQuery->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $sosQuery->whereDate('created_at', '<=', $endDate);
            $lapQuery->whereDate('created_at', '<=', $endDate);
        }

        $sosList = $sosQuery->get();
        $lapList = $lapQuery->get();

        $filename = 'rekap_semua_kasus_sahabat_sos_' . now()->format('Ymd_His') . '.csv';

        $callback = function () use ($sosList, $lapList) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Tipe Kasus',
                'ID Kasus',
                'Nomor Kasus',
                'Tanggal & Waktu Kejadian',
                'Kategori / Urgensi',
                'Keterangan / Deskripsi',
                'Nama Korban / Pelapor',
                'No. Telp Korban / Pelapor',
                'Disabilitas',
                'Latitude',
                'Longitude',
                'Lokasi',
                'Status Kasus',
                'Relawan Penolong',
                'No. Telp Relawan',
                'Durasi Penanganan',
            ]);

            foreach ($sosList as $sos) {
                $p = $sos->pengguna;
                $r = $sos->relawan;
                $durasi = $this->calculateDurasiPenanganan($sos->created_at, $sos->updated_at, $sos->status_sos);

                fputcsv($handle, [
                    'SOS (Darurat)',
                    $this->sanitizeCsvValue($sos->id),
                    $this->sanitizeCsvValue($sos->nomor_kasus ?? ('#SOS-' . $sos->id)),
                    $this->sanitizeCsvValue($sos->created_at ? $sos->created_at->format('Y-m-d H:i:s') : '-'),
                    'Kritis / Darurat',
                    'Sinyal Darurat SOS',
                    $this->sanitizeCsvValue($p ? $p->name : 'Pengguna SOS'),
                    $this->sanitizeCsvValue($p ? $p->no_telp : '-'),
                    $this->sanitizeCsvValue($p ? ($p->kategori_user ?? 'umum') : 'umum'),
                    $this->sanitizeCsvValue($sos->latitude ?? '-'),
                    $this->sanitizeCsvValue($sos->longitude ?? '-'),
                    $this->sanitizeCsvValue($p ? ($p->alamat ?? '-') : '-'),
                    $this->sanitizeCsvValue($sos->status_sos ?? 'aktif'),
                    $this->sanitizeCsvValue($r ? $r->name : 'Belum Ditugaskan'),
                    $this->sanitizeCsvValue($r ? $r->no_telp : '-'),
                    $this->sanitizeCsvValue($durasi),
                ]);
            }

            foreach ($lapList as $lap) {
                $p = $lap->pengguna;
                $r = $lap->relawan;
                $durasi = $this->calculateDurasiPenanganan($lap->created_at, $lap->updated_at, $lap->status);

                fputcsv($handle, [
                    'Laporan Cepat',
                    $this->sanitizeCsvValue($lap->id),
                    $this->sanitizeCsvValue($lap->nomor_kasus ?? ('#LAP-' . $lap->id)),
                    $this->sanitizeCsvValue($lap->created_at ? $lap->created_at->format('Y-m-d H:i:s') : '-'),
                    $this->sanitizeCsvValue($lap->kategori_laporan ?? 'Umum'),
                    $this->sanitizeCsvValue($lap->deskripsi ?? ($lap->pesan_cepat ?? '-')),
                    $this->sanitizeCsvValue($p ? $p->name : 'Anonim'),
                    $this->sanitizeCsvValue($p ? $p->no_telp : '-'),
                    $this->sanitizeCsvValue($p ? ($p->kategori_user ?? 'umum') : 'umum'),
                    $this->sanitizeCsvValue($lap->latitude ?? '-'),
                    $this->sanitizeCsvValue($lap->longitude ?? '-'),
                    $this->sanitizeCsvValue($lap->lokasi_laporan ?? ($lap->alamat ?? '-')),
                    $this->sanitizeCsvValue($lap->status ?? 'aktif'),
                    $this->sanitizeCsvValue($r ? $r->name : 'Belum Ditugaskan'),
                    $this->sanitizeCsvValue($r ? $r->no_telp : '-'),
                    $this->sanitizeCsvValue($durasi),
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
                    'laporan'   => [],
                    'pengguna'  => [],
                    'relawan'   => [],
                ],
            ], 200);
        }

        // Search SOS by ID, nomor_kasus, pengguna, or relawan
        $sosQuery = SOS::with(['pengguna', 'relawan'])
            ->where(function ($q) use ($query) {
                if (is_numeric($query)) {
                    $q->where('id', (int) $query);
                }
                $q->orWhere('nomor_kasus', 'like', "%{$query}%")
                  ->orWhereHas('pengguna', function ($sub) use ($query) {
                      $sub->where('name', 'like', "%{$query}%")
                          ->orWhere('no_telp', 'like', "%{$query}%");
                  })
                  ->orWhereHas('relawan', function ($sub) use ($query) {
                      $sub->where('name', 'like', "%{$query}%");
                  });
            });
        $kasusSos = $sosQuery->take(5)->get();

        // Search Laporan by ID, nomor_kasus, kategori, pengguna, or relawan
        $laporanQuery = Laporan::with(['pengguna', 'relawan'])
            ->where(function ($q) use ($query) {
                if (is_numeric($query)) {
                    $q->where('id', (int) $query);
                }
                $q->orWhere('nomor_kasus', 'like', "%{$query}%")
                  ->orWhere('kategori_laporan', 'like', "%{$query}%")
                  ->orWhereHas('pengguna', function ($sub) use ($query) {
                      $sub->where('name', 'like', "%{$query}%")
                          ->orWhere('no_telp', 'like', "%{$query}%");
                  })
                  ->orWhereHas('relawan', function ($sub) use ($query) {
                      $sub->where('name', 'like', "%{$query}%");
                  });
            });
        $laporanList = $laporanQuery->take(5)->get();

        // Search Pengguna
        $penggunaList = User::where('role', 'pengguna')
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%")
                  ->orWhere('no_telp', 'like', "%{$query}%");
            })
            ->take(5)
            ->get();

        // Search Relawan
        $relawanList = User::where('role', 'relawan')
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%")
                  ->orWhere('no_telp', 'like', "%{$query}%");
            })
            ->take(5)
            ->get();

        return response()->json([
            'message' => "Hasil pencarian untuk '{$query}'",
            'data'    => [
                'kasus_sos' => $kasusSos->map(function ($sos) {
                    return [
                        'id'          => $sos->id,
                        'nomor_kasus' => $sos->nomor_kasus ?? ("SOS-" . $sos->id),
                        'status'      => $sos->status_sos,
                        'pelapor'     => $sos->pengguna?->name ?? 'Pengguna SOS',
                        'relawan'     => $sos->relawan?->name ?? null,
                        'disabilitas' => $sos->pengguna?->kategori_user ?? 'umum',
                        'lokasi'      => $sos->pengguna?->alamat ?? 'Lokasi GPS Korban',
                        'waktu'       => $sos->waktu_sos ? $sos->waktu_sos->toIso8601String() : $sos->created_at?->toIso8601String(),
                    ];
                }),
                'laporan'   => $laporanList->map(function ($lap) {
                    return [
                        'id'          => $lap->id,
                        'nomor_kasus' => $lap->nomor_kasus ?? ("LP-" . $lap->id),
                        'kategori'    => $lap->kategori_laporan,
                        'status'      => $lap->status,
                        'pelapor'     => $lap->pengguna?->name ?? 'Pengguna',
                        'relawan'     => $lap->relawan?->name ?? null,
                        'lokasi'      => $lap->lokasi_laporan ?? ($lap->pengguna?->alamat ?? 'Lokasi Laporan'),
                        'waktu'       => $lap->waktu_laporan ? $lap->waktu_laporan->toIso8601String() : $lap->created_at?->toIso8601String(),
                    ];
                }),
                'pengguna'  => $penggunaList->map(function ($user) {
                    return [
                        'id'        => $user->id,
                        'nama'      => $user->name,
                        'email'     => $user->email,
                        'no_telp'   => $user->no_telp,
                        'kategori'  => $user->kategori_user ?? 'umum',
                        'alamat'    => $user->alamat ?? '-',
                    ];
                }),
                'relawan'   => $relawanList->map(function ($user) {
                    return [
                        'id'        => $user->id,
                        'nama'      => $user->name,
                        'email'     => $user->email,
                        'no_telp'   => $user->no_telp,
                        'pekerjaan' => $user->pekerjaan ?? 'Relawan Pendamping',
                        'status'    => $user->status_ketersediaan ?? 'tersedia',
                    ];
                }),
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
        if (!empty($relawan->pekerjaan)) {
            return $relawan->pekerjaan;
        }

        if (!empty($relawan->keahlian)) {
            return $relawan->keahlian;
        }

        return $relawan->kategori_relawan ?? 'Relawan Pendamping Siaga';
    }

    private function calculateHaversineDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // Radius bumi dalam kilometer
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}
