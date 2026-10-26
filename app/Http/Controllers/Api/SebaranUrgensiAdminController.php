<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SOS;
use App\Models\Laporan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class SebaranUrgensiAdminController extends Controller
{
    /**
     * 1. RINGKASAN SEBARAN DAN URGENSI KASUS (GET /api/admin/dashboard/sebaran-urgensi)
     * Mendukung filter rentang waktu: ?rentang=semua|hari_ini|minggu_ini|bulan_ini
     */
    public function index(Request $request)
    {
        $today = now()->toDateString();
        $rentang = strtolower($request->query('rentang', $request->query('waktu', 'semua')));

        // 1. Level Urgensi Kasus
        $kasusKritisAktif = SOS::whereIn('status_sos', ['belum ditangani', 'ditangani', 'aktif', 'proses'])->count();
        $kasusSedangMenunggu = Laporan::whereIn('status', ['belum ditangani', 'menunggu', 'aktif', 'ditangani', 'proses'])->count();
        $kasusSelesaiHariIni = SOS::where('status_sos', 'selesai')->whereDate('updated_at', $today)->count()
            + Laporan::where('status', 'selesai')->whereDate('updated_at', $today)->count();

        $totalSos = SOS::count();
        $totalResolved = SOS::where('status_sos', 'selesai')->count();
        $tingkatKeberhasilan = $totalSos > 0 ? round(($totalResolved / $totalSos) * 100, 1) : 100.0;

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

        // 3. Sebaran Kategori Laporan (Dukungan filter rentang waktu: semua|hari_ini|minggu_ini|bulan_ini)
        $laporanQuery = Laporan::query();

        match ($rentang) {
            'hari_ini', 'today' => $laporanQuery->whereDate('created_at', now()->toDateString()),
            'minggu_ini', 'week', 'this_week' => $laporanQuery->whereBetween('created_at', [
                now()->startOfWeek(CarbonInterface::SUNDAY)->startOfDay(),
                now()->endOfWeek(CarbonInterface::SATURDAY)->endOfDay(),
            ]),
            'bulan_ini', 'month', 'this_month' => $laporanQuery->whereBetween('created_at', [
                now()->startOfMonth()->startOfDay(),
                now()->endOfMonth()->endOfDay(),
            ]),
            default => null, // 'semua'
        };

        $laporanCategories = (clone $laporanQuery)
            ->select('kategori_laporan', DB::raw('count(*) as total'))
            ->groupBy('kategori_laporan')
            ->get();

        $totalLaporans = (clone $laporanQuery)->count() ?: 1;
        $sebaranKategoriLaporan = $laporanCategories->map(function ($item) use ($totalLaporans) {
            return [
                'kategori'   => $item->kategori_laporan,
                'label'      => ucwords(str_replace(['_', '-'], ' ', $item->kategori_laporan)),
                'jumlah'     => $item->total,
                'persentase' => round(($item->total / $totalLaporans) * 100, 1),
            ];
        });

        return response()->json([
            'message' => 'Berhasil mengambil ringkasan sebaran dan urgensi kasus',
            'data'    => [
                'rentang_waktu'            => $rentang,
                'total_laporan_terfilter'  => (clone $laporanQuery)->count(),
                'ringkasan_urgensi'        => $ringkasanUrgensi,
                'sebaran_disabilitas'      => $sebaranDisabilitas,
                'sebaran_kategori_laporan' => $sebaranKategoriLaporan,
            ],
        ], 200);
    }

    /**
     * 2. TREN GRAFIK SOS MINGGUAN (GET /api/admin/dashboard/grafik-sos)
     * Mengembalikan data statistik tren SOS per hari (Minggu s/d Sabtu)
     */
    public function grafikSos(Request $request)
    {
        // Tanggal acuan (minggu yang ditampilkan)
        $refDate = now();
        if ($request->filled('tanggal')) {
            try {
                $refDate = Carbon::parse($request->query('tanggal'));
            } catch (\Exception $e) {
                $refDate = now();
            }
        } else {
            // Cek apakah minggu berjalan memiliki data SOS
            $currStart = now()->startOfWeek(CarbonInterface::SUNDAY)->startOfDay();
            $currEnd = now()->endOfWeek(CarbonInterface::SATURDAY)->endOfDay();
            $hasData = SOS::where(function ($q) use ($currStart, $currEnd) {
                $q->whereBetween('waktu_sos', [$currStart, $currEnd])
                  ->orWhereBetween('created_at', [$currStart, $currEnd]);
            })->exists();

            // Jika minggu berjalan kosong, arahkan ke tanggal data SOS terbaru agar grafik informatif
            if (!$hasData) {
                $latestDate = SOS::max('waktu_sos') ?? SOS::max('created_at');
                if ($latestDate) {
                    $refDate = Carbon::parse($latestDate);
                }
            }
        }

        $startOfWeek = $refDate->copy()->startOfWeek(CarbonInterface::SUNDAY)->startOfDay();
        $dayNames = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
        $fullDayNames = [
            'Min' => 'Minggu',
            'Sen' => 'Senin',
            'Sel' => 'Selasa',
            'Rab' => 'Rabu',
            'Kam' => 'Kamis',
            'Jum' => 'Jumat',
            'Sab' => 'Sabtu',
        ];

        $harian = [];
        $totalKasus = 0;
        $maxTotal = 0;
        $peakDay = 'Sabtu';

        for ($i = 0; $i < 7; $i++) {
            $curr = $startOfWeek->copy()->addDays($i);
            $dateStr = $curr->toDateString();
            $label = $dayNames[$i];

            $count = SOS::where(function ($q) use ($dateStr) {
                $q->whereDate('waktu_sos', $dateStr)
                  ->orWhere(function ($sub) use ($dateStr) {
                      $sub->whereNull('waktu_sos')->whereDate('created_at', $dateStr);
                  });
            })->count();

            $totalKasus += $count;
            if ($count > $maxTotal) {
                $maxTotal = $count;
                $peakDay = $fullDayNames[$label] ?? $label;
            }

            $harian[] = [
                'hari'     => $label,
                'tanggal'  => $dateStr,
                'total'    => $count,
                'is_puncak'=> false,
            ];
        }

        // Tandai hari yang merupakan puncak insiden
        if ($maxTotal > 0) {
            foreach ($harian as &$item) {
                if ($item['total'] === $maxTotal) {
                    $item['is_puncak'] = true;
                }
            }
            unset($item);
        }

        $rataRata = round($totalKasus / 7, 1);

        return response()->json([
            'message' => 'Berhasil mengambil data tren SOS harian mingguan',
            'data'    => [
                'total_kasus'      => $totalKasus,
                'rata_rata_harian' => $rataRata,
                'puncak_insiden'   => [
                    'hari'  => $maxTotal > 0 ? $peakDay : 'Tidak ada insiden',
                    'total' => $maxTotal,
                ],
                'harian'           => $harian,
            ],
        ], 200);
    }
}
