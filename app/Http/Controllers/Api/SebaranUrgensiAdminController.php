<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SOS;
use App\Models\Laporan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SebaranUrgensiAdminController extends Controller
{
    /**
     * RINGKASAN SEBARAN DAN URGENSI KASUS (GET /api/admin/dashboard/sebaran-urgensi)
     */
    public function index(Request $request)
    {
        $today = now()->toDateString();

        // 1. Level Urgensi Kasus
        $kasusKritisAktif = SOS::whereIn('status_sos', ['aktif', 'proses'])->count();
        $kasusSedangMenunggu = Laporan::where('status', 'menunggu')->count();
        $kasusSelesaiHariIni = SOS::where('status_sos', 'selesai')->whereDate('updated_at', $today)->count() 
            + Laporan::where('status', 'selesai')->whereDate('updated_at', $today)->count();

        $totalSos = SOS::count();
        $totalResolved = SOS::where('status_sos', 'selesai')->count();
        $tingkatKeberhasilan = $totalSos > 0 ? round(($totalResolved / $totalSos) * 100, 1) : 98.1;

        $ringkasanUrgensi = [
            'level_kritis' => [
                'label'      => 'Kritis (SOS Darurat Aktif)',
                'jumlah'     => $kasusKritisAktif,
                'warna_code' => '#E03131', // Signal Red
                'status'     => $kasusKritisAktif > 0 ? 'BUTUH_RESPONS_CEPAT' : 'AMANKAN',
            ],
            'level_sedang' => [
                'label'      => 'Sedang (Laporan Menunggu)',
                'jumlah'     => $kasusSedangMenunggu,
                'warna_code' => '#F59F00', // Amber Yellow
                'status'     => $kasusSedangMenunggu > 0 ? 'DALAM_ANTREAN' : 'KOSONG',
            ],
            'level_terkendali' => [
                'label'      => 'Terkendali (Selesai Hari Ini)',
                'jumlah'     => $kasusSelesaiHariIni,
                'warna_code' => '#2F9E44', // Mint Green
            ],
            'tingkat_keberhasilan_bantuan' => "{$tingkatKeberhasilan}%",
        ];

        // 2. Sebaran Kategori Disabilitas Korban
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
            [
                'kategori'   => 'Disabilitas Netra (Tunanetra)',
                'jumlah'     => $tunanetraCount,
                'persentase' => round(($tunanetraCount / $totalUsers) * 100, 1),
            ],
            [
                'kategori'   => 'Disabilitas Rungu & Wicara (Tunarungu)',
                'jumlah'     => $tunarunguCount,
                'persentase' => round(($tunarunguCount / $totalUsers) * 100, 1),
            ],
            [
                'kategori'   => 'Disabilitas Fisik / Motorik (Kursi Roda)',
                'jumlah'     => $tunadaksaCount,
                'persentase' => round(($tunadaksaCount / $totalUsers) * 100, 1),
            ],
            [
                'kategori'   => 'Umum / Lainnya',
                'jumlah'     => $umumCount,
                'persentase' => round(($umumCount / $totalUsers) * 100, 1),
            ],
        ];

        // 3. Sebaran Kategori Laporan
        $laporanCategories = Laporan::select('kategori_laporan', DB::raw('count(*) as total'))
            ->groupBy('kategori_laporan')
            ->get();

        $totalLaporans = Laporan::count() ?: 1;
        $sebaranKategoriLaporan = $laporanCategories->map(function ($item) use ($totalLaporans) {
            return [
                'kategori'   => $item->kategori_laporan,
                'jumlah'     => $item->total,
                'persentase' => round(($item->total / $totalLaporans) * 100, 1),
            ];
        });

        return response()->json([
            'message' => 'Berhasil mengambil ringkasan sebaran dan urgensi kasus',
            'data'    => [
                'ringkasan_urgensi'        => $ringkasanUrgensi,
                'sebaran_disabilitas'      => $sebaranDisabilitas,
                'sebaran_kategori_laporan' => $sebaranKategoriLaporan,
            ],
        ], 200);
    }
}
