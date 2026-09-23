<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SOS;
use App\Models\Laporan;
use App\Models\User;
use Illuminate\Http\Request;

class PetaKasusAdminController extends Controller
{
    /**
     * PETA KASUS AKTIF & RELAWAN (GET /api/admin/dashboard/peta-kasus)
     */
    public function index(Request $request)
    {
        // 1. Sinyal SOS Aktif (Urgensi Kritis - Merah)
        $activeSosList = SOS::with(['pengguna', 'relawan'])
            ->whereIn('status_sos', ['aktif', 'proses'])
            ->orderBy('created_at', 'desc')
            ->get();

        $titikDaruratSos = $activeSosList->map(function ($sos) {
            return [
                'id'                => $sos->id,
                'id_kasus'          => "#SOS-{$sos->id}",
                'latitude'          => (float) $sos->latitude,
                'longitude'         => (float) $sos->longitude,
                'status'            => $sos->status_sos,
                'urgensi'           => 'kritis',
                'korban'            => $sos->pengguna->name ?? 'Pengguna SOS',
                'jenis_disabilitas' => $sos->pengguna->kategori_user ?? 'umum',
                'relawan_penangan'  => $sos->relawan->name ?? null,
                'waktu_sos'         => $sos->waktu_sos ? $sos->waktu_sos->toIso8601String() : null,
            ];
        });

        // 2. Laporan Aktif yang memiliki koordinat GPS (Urgensi Sedang - Kuning)
        $laporanAktifList = Laporan::with(['pengguna', 'relawan'])
            ->whereIn('status', ['menunggu', 'proses'])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('created_at', 'desc')
            ->get();

        $titikLaporanAktif = $laporanAktifList->map(function ($lap) {
            return [
                'id'               => $lap->id,
                'id_laporan'       => "#LAPORAN-{$lap->id}",
                'latitude'         => (float) $lap->latitude,
                'longitude'        => (float) $lap->longitude,
                'kategori'         => $lap->kategori_laporan,
                'status'           => $lap->status,
                'urgensi'          => 'sedang',
                'pelapor'          => $lap->pengguna->name ?? 'Pengguna',
                'relawan_penangan' => $lap->relawan->name ?? null,
                'waktu_laporan'    => $lap->waktu_laporan ? $lap->waktu_laporan->toIso8601String() : null,
            ];
        });

        // 3. Posisi Relawan Lapangan (Siaga & Sedang Bertugas)
        $posisiRelawan = User::where('role', 'relawan')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->map(function ($relawan) {
                $isBusy = SOS::whereIn('status_sos', ['aktif', 'proses'])
                    ->where('id_relawan', $relawan->id)
                    ->exists();

                return [
                    'id'          => $relawan->id,
                    'nama'        => $relawan->name,
                    'no_telp'     => $relawan->no_telp,
                    'latitude'    => (float) $relawan->latitude,
                    'longitude'   => (float) $relawan->longitude,
                    'status'      => $isBusy ? 'sedang_bertugas' : 'siaga',
                    'kompetensi'  => $this->determineVolunteerCompetency($relawan),
                    'lokasi_user' => $relawan->lokasi_user ?? 'Area Siaga',
                ];
            });

        return response()->json([
            'message' => 'Berhasil mengambil data peta kasus aktif & posisi relawan',
            'summary' => [
                'total_sos_aktif'     => $titikDaruratSos->count(),
                'total_laporan_aktif' => $titikLaporanAktif->count(),
                'total_relawan_siaga' => $posisiRelawan->where('status', 'siaga')->count(),
            ],
            'data' => [
                'titik_darurat_sos'   => $titikDaruratSos,
                'titik_laporan_aktif' => $titikLaporanAktif,
                'posisi_relawan'      => $posisiRelawan,
                'posko_sahabat_sos'   => [
                    'nama'      => 'POSKO PUSAT SAHABAT SOS JAKARTA',
                    'latitude'  => -6.2088,
                    'longitude' => 106.8456,
                ],
            ],
        ], 200);
    }

    private function determineVolunteerCompetency($relawan)
    {
        if ($relawan->pekerjaan) {
            return $relawan->pekerjaan;
        }

        $competencies = [
            'Navigasi Tunanetra & P3K',
            'Juru Bahasa Isyarat / JBI',
            'Pendamping Mobilitas & Kursi Roda',
        ];

        return $competencies[$relawan->id % count($competencies)];
    }
}
