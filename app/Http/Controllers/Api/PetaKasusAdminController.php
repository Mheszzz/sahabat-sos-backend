<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SOS;
use App\Models\Laporan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class PetaKasusAdminController extends Controller
{
    /**
     * PETA KASUS AKTIF & RELAWAN (GET /api/admin/dashboard/peta-kasus)
     */
    public function index(Request $request)
    {
        // 1. Sinyal SOS Aktif yang memiliki koordinat GPS (Urgensi Kritis - Merah)
        $activeSosList = SOS::with(['pengguna', 'relawan'])
            ->whereIn('status_sos', ['belum ditangani', 'ditangani', 'aktif', 'proses'])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('created_at', 'desc')
            ->get();

        $titikDaruratSos = $activeSosList->map(function ($sos) {
            $idKasus = $sos->nomor_kasus ?? ("SOS-" . $sos->id);
            return [
                'id'                => $sos->id,
                'id_kasus'          => $idKasus,
                'nomor_kasus'       => $idKasus,
                'latitude'          => (float) $sos->latitude,
                'longitude'         => (float) $sos->longitude,
                'status'            => $sos->status_sos,
                'urgensi'           => 'kritis',
                'korban'            => $sos->pengguna->name ?? 'Pengguna SOS',
                'jenis_disabilitas' => $sos->pengguna->kategori_user ?? 'umum',
                'id_relawan'        => $sos->id_relawan,
                'relawan_penangan'  => $sos->relawan->name ?? null,
                'alamat'            => $sos->pengguna->alamat ?? null,
                'lokasi'            => $sos->pengguna->alamat ?? null,
                'waktu_sos'         => $sos->waktu_sos ? $sos->waktu_sos->toIso8601String() : null,
            ];
        });

        // 2. Laporan Aktif yang memiliki koordinat GPS (Urgensi Sedang - Kuning)
        $laporanAktifList = Laporan::with(['pengguna', 'relawan'])
            ->whereIn('status', ['belum ditangani', 'ditangani', 'menunggu', 'proses', 'aktif'])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('created_at', 'desc')
            ->get();

        $titikLaporanAktif = $laporanAktifList->map(function ($lap) {
            $idKasus = $lap->nomor_kasus ?? ("LP-" . $lap->id);
            return [
                'id'               => $lap->id,
                'id_laporan'       => $idKasus,
                'id_kasus'         => $idKasus,
                'nomor_kasus'      => $idKasus,
                'latitude'         => (float) $lap->latitude,
                'longitude'        => (float) $lap->longitude,
                'kategori'         => $lap->kategori_laporan,
                'status'           => $lap->status,
                'urgensi'          => 'sedang',
                'pelapor'          => $lap->pengguna->name ?? 'Pengguna',
                'id_relawan'       => $lap->id_relawan,
                'relawan_penangan' => $lap->relawan->name ?? null,
                'lokasi_laporan'   => $lap->lokasi_laporan,
                'alamat'           => $lap->lokasi_laporan ?? ($lap->pengguna->alamat ?? null),
                'lokasi'           => $lap->lokasi_laporan ?? ($lap->pengguna->alamat ?? null),
                'waktu_laporan'    => $lap->waktu_laporan ? $lap->waktu_laporan->toIso8601String() : null,
            ];
        });

        // 3. Posisi Relawan Lapangan (Siaga & Sedang Bertugas)
        $posisiRelawan = User::where('role', 'relawan')
            ->where('status_verifikasi', 'terverifikasi')
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

                $activeLaporan = null;
                if (!$activeSOS) {
                    $activeLaporan = Laporan::with('pengguna')->whereIn('status', ['belum ditangani', 'menunggu', 'proses', 'ditangani', 'aktif'])
                        ->where('id_relawan', $relawan->id)
                        ->first();
                }

                $isBusy = ($activeSOS !== null) || ($activeLaporan !== null);
                $isOff = $relawan->status_ketersediaan && !in_array($relawan->status_ketersediaan, ['tersedia', 'siaga']);

                $statusLabel = 'siaga_bebas';
                if ($isBusy) {
                    $statusLabel = 'dalam_misi';
                } elseif ($isOff) {
                    $statusLabel = 'off_istirahat';
                }

                $misiData = null;
                if ($activeSOS) {
                    $misiData = [
                        'id'       => $activeSOS->id,
                        'tipe'     => 'sos',
                        'id_kasus' => "#SOS-{$activeSOS->id}",
                        'pelapor'  => $activeSOS->pengguna->name ?? 'Pengguna',
                        'kategori' => 'Darurat SOS',
                        'tujuan'   => $activeSOS->pengguna->alamat ?? 'Lokasi GPS Korban',
                    ];
                } elseif ($activeLaporan) {
                    $nomorKasus = $activeLaporan->nomor_kasus ?? ("LP-" . $activeLaporan->id);
                    $misiData = [
                        'id'       => $activeLaporan->id,
                        'tipe'     => 'laporan',
                        'id_kasus' => $nomorKasus,
                        'pelapor'  => $activeLaporan->pengguna->name ?? 'Warga Pelapor',
                        'kategori' => $activeLaporan->kategori_laporan ?? 'Laporan Warga',
                        'tujuan'   => $activeLaporan->lokasi_laporan ?? ($activeLaporan->pengguna->alamat ?? 'Lokasi Laporan'),
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

        // 4. Hitung Total Kasus yang Belum Tertangani (Belum Ditangani)
        $sosBelumTertangani = SOS::whereIn('status_sos', ['belum ditangani', 'aktif'])->count();
        $laporanBelumTertangani = Laporan::whereIn('status', ['belum ditangani', 'menunggu', 'aktif'])->count();
        $totalBelumTertangani = $sosBelumTertangani + $laporanBelumTertangani;

        return response()->json([
            'message' => 'Berhasil mengambil data peta kasus aktif & posisi relawan',
            'summary' => [
                'total_sos_aktif'               => $titikDaruratSos->count(),
                'total_laporan_aktif'           => $titikLaporanAktif->count(),
                'total_sos_belum_tertangani'    => $sosBelumTertangani,
                'total_laporan_belum_tertangani'=> $laporanBelumTertangani,
                'total_belum_tertangani'        => $totalBelumTertangani,
                'total_kasus_belum_tertangani'  => $totalBelumTertangani,
                'total_relawan_siaga_bebas'     => $posisiRelawan->where('status', 'siaga_bebas')->count(),
                'total_relawan_dalam_misi'      => $posisiRelawan->where('status', 'dalam_misi')->count(),
                'total_relawan_off_istirahat'   => $posisiRelawan->where('status', 'off_istirahat')->count(),
            ],
            'data' => [
                'titik_darurat_sos'   => $titikDaruratSos,
                'titik_darurat'       => $titikDaruratSos,
                'titik_laporan_aktif' => $titikLaporanAktif,
                'titik_laporan'       => $titikLaporanAktif,
                'posisi_relawan'      => $posisiRelawan,
            ],
        ], 200);
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
}
