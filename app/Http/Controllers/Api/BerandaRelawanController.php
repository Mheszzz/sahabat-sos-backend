<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Laporan;
use App\Models\SOS;

class BerandaRelawanController extends Controller
{
    /**
     * 1. RIWAYAT BERANDA RELAWAN (GET /api/relawan/beranda)
     * Mengembalikan riwayat penanganan tugas relawan (SOS dan Laporan) yang sudah selesai,
     * mendukung pagination untuk mobile infinite-scroll.
     */
    public function index(Request $request) {
        $relawanId = $request->user()->id;
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);
        $offset = ($page - 1) * $perPage;

        // 1. Ambil Laporan selesai milik relawan ini
        $laporans = Laporan::with('pengguna:id,name,foto_profile,no_telp')
            ->where('id_relawan', $relawanId)
            ->whereIn('status', ['selesai', 'Selesai'])
            ->latest('waktu_laporan')
            ->get()
            ->map(function ($item) {
                $subJudul = KasusAktifController::getJenisLaporan('laporan', $item->kategori_laporan);
                return [
                    'id'            => $item->id,
                    'tipe'          => 'laporan',
                    'kategori'      => 'Laporan',
                    'jenis_laporan' => $subJudul,
                    'sub_judul'     => $subJudul,
                    'id_pengguna'   => $item->id_pengguna,
                    'pengguna'      => $item->pengguna,
                    'lokasi'        => $item->lokasi_laporan,
                    'latitude'      => (float) $item->latitude,
                    'longitude'     => (float) $item->longitude,
                    'deskripsi'     => $item->deskripsi,
                    'foto'          => $item->foto_laporan,
                    'foto_url'      => $item->foto_laporan ? url('storage/' . $item->foto_laporan) : null,
                    'rekam_suara'   => $item->rekam_suara,
                    'status'        => 'Selesai',
                    'status_raw'    => $item->status,
                    'waktu'         => ($item->waktu_laporan ?? $item->created_at)?->toDateTimeString(),
                ];
            });

        // 2. Ambil SOS selesai milik relawan ini
        $sosList = SOS::with('pengguna:id,name,foto_profile,no_telp')
            ->where('id_relawan', $relawanId)
            ->whereIn('status_sos', ['selesai', 'Selesai'])
            ->latest('waktu_sos')
            ->get()
            ->map(function ($item) {
                return [
                    'id'            => $item->id,
                    'tipe'          => 'sos',
                    'kategori'      => 'SOS',
                    'jenis_laporan' => 'Darurat',
                    'sub_judul'     => 'Darurat',
                    'id_pengguna'   => $item->id_pengguna,
                    'pengguna'      => $item->pengguna,
                    'lokasi'        => 'Lokasi Panggilan Darurat SOS',
                    'latitude'      => (float) $item->latitude,
                    'longitude'     => (float) $item->longitude,
                    'deskripsi'     => 'Permintaan bantuan darurat SOS',
                    'foto'          => null,
                    'foto_url'      => null,
                    'rekam_suara'   => null,
                    'status'        => 'Selesai',
                    'status_raw'    => $item->status_sos,
                    'waktu'         => ($item->waktu_sos ?? $item->created_at)?->toDateTimeString(),
                ];
            });

        // 3. Gabungkan kedua collection, urutkan berdasarkan waktu terbaru
        $all = $laporans->concat($sosList)
            ->sortByDesc('waktu')
            ->values();

        $total = $all->count();
        $paged = $all->slice($offset, $perPage)->values();

        return response()->json([
            'success'      => true,
            'message'      => 'Berhasil mengambil riwayat penanganan',
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'last_page'    => ceil($total / $perPage),
            'has_more'     => ($offset + $perPage) < $total,
            'data'         => $paged,
        ], 200);
    }

    /**
     * 2. DAFTAR TUGAS AKTIF RELAWAN (GET /api/relawan/tugas)
     * Mengambil tugas yang sedang ditugaskan ke relawan ini (status 'proses' / 'Ditangani')
     */
    public function tugasRelawan(Request $request)
    {
        $relawanId = $request->user()->id;
        $tipe = $request->query('tipe'); // 'sos' | 'laporan' | null

        $data = collect();

        // 1. Laporan yang ditugaskan ke relawan ini (status 'proses' / aktif)
        if (!$tipe || $tipe === 'laporan') {
            $laporan = Laporan::with(['pengguna.kontakDarurat', 'relawan'])
                ->where('id_relawan', $relawanId)
                ->whereIn('status', ['proses', 'aktif'])
                ->latest('waktu_laporan')
                ->get()
                ->map(fn($item) => KasusAktifController::transformItem($item, 'laporan'));
            $data = $data->concat($laporan);
        }

        // 2. SOS yang ditugaskan ke relawan ini (status_sos 'proses')
        if (!$tipe || $tipe === 'sos') {
            $sos = SOS::with(['pengguna.kontakDarurat', 'relawan'])
                ->where('id_relawan', $relawanId)
                ->whereIn('status_sos', ['proses', 'aktif'])
                ->latest('waktu_sos')
                ->get()
                ->map(fn($item) => KasusAktifController::transformItem($item, 'sos'));
            $data = $data->concat($sos);
        }

        $sorted = $data->sortByDesc('waktu')->values();

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil daftar tugas aktif relawan',
            'total'   => $sorted->count(),
            'data'    => $sorted,
        ], 200);
    }

    /**
     * 3. RIWAYAT TUGAS RELAWAN (GET /api/relawan/riwayat)
     */
    public function riwayatRelawan(Request $request)
    {
        return $this->index($request);
    }

    /**
     * 4. RIWAYAT PENGGUNA (GET /api/pengguna/riwayat)
     * Mengambil riwayat kasus (SOS & Laporan) milik akun pengguna yang sedang login
     */
    public function riwayatPengguna(Request $request)
    {
        $userId = $request->user()->id;
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);
        $offset = ($page - 1) * $perPage;

        $laporans = Laporan::with(['relawan'])
            ->where('id_pengguna', $userId)
            ->latest('waktu_laporan')
            ->get()
            ->map(fn($item) => KasusAktifController::transformItem($item, 'laporan'));

        $sosList = SOS::with(['relawan'])
            ->where('id_pengguna', $userId)
            ->latest('waktu_sos')
            ->get()
            ->map(fn($item) => KasusAktifController::transformItem($item, 'sos'));

        $all = $laporans->concat($sosList)->sortByDesc('waktu')->values();
        $total = $all->count();
        $paged = $all->slice($offset, $perPage)->values();

        return response()->json([
            'success'      => true,
            'message'      => 'Berhasil mengambil riwayat pengguna',
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'last_page'    => ceil($total / $perPage),
            'has_more'     => ($offset + $perPage) < $total,
            'data'         => $paged,
        ], 200);
    }

    /**
     * 5. UPDATE STATUS KETERSEDIAAN RELAWAN (PUT /api/relawan/status-ketersediaan)
     */
    public function updateStatusKetersediaan(Request $request)
    {
        $request->validate([
            'status_ketersediaan' => 'required|string|in:tersedia,istirahat,off,tidak_tersedia'
        ]);

        $user = $request->user();
        $user->update([
            'status_ketersediaan' => $request->status_ketersediaan
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status ketersediaan berhasil diperbarui',
            'data'    => [
                'status_ketersediaan' => $user->status_ketersediaan
            ],
        ], 200);
    }
}

