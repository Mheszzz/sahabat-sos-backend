<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Laporan;
use App\Models\SOS;

class BerandaRelawanController extends Controller
{
    public function index(Request $request) {
        $relawanId = $request->user()->id; // Mengambil ID relawan yang sedang login (Sanctum/Auth)

        // 1. Ambil maksimal 10 Laporan selesai milik relawan ini
        $laporans = Laporan::with('pengguna:id,name,foto_profile,no_telp')
            ->where('id_relawan', $relawanId)
            ->whereIn('status', ['selesai', 'Selesai']) // Menyesuaikan variasi penulisan status
            ->latest('waktu_laporan') // Diurutkan berdasarkan waktu laporan
            ->take(10)
            ->get()
            ->map(function ($item) {
                return [
                    'id'            => $item->id,
                    'tipe'          => 'laporan', // Penanda tipe data untuk Flutter/Mobile
                    'id_pengguna'   => $item->id_pengguna,
                    'pengguna'      => $item->pengguna,
                    'lokasi'        => $item->lokasi_laporan,
                    'latitude'      => $item->latitude,
                    'longitude'     => $item->longitude,
                    'kategori'      => $item->kategori_laporan,
                    'deskripsi'     => $item->deskripsi,
                    'foto'          => $item->foto_laporan,
                    'rekam_suara'   => $item->rekam_suara,
                    'status'        => $item->status,
                    'waktu'         => $item->waktu_laporan ?? $item->created_at,
                ];
            });

        // 2. Ambil maksimal 10 SOS selesai milik relawan ini
        $sosList = SOS::with('pengguna:id,name,foto_profile,no_telp')
            ->where('id_relawan', $relawanId)
            ->whereIn('status_sos', ['selesai', 'Selesai']) // Menyesuaikan variasi penulisan status
            ->latest('waktu_sos') // Diurutkan berdasarkan waktu SOS
            ->take(10)
            ->get()
            ->map(function ($item) {
                return [
                    'id'            => $item->id,
                    'tipe'          => 'sos', // Penanda tipe data untuk Flutter/Mobile
                    'id_pengguna'   => $item->id_pengguna,
                    'pengguna'      => $item->pengguna,
                    'lokasi'        => null, // SOS tidak memiliki nama lokasi string di DB
                    'latitude'      => $item->latitude,
                    'longitude'     => $item->longitude,
                    'kategori'      => 'DARURAT (SOS)',
                    'deskripsi'     => 'Permintaan bantuan darurat SOS',
                    'foto'          => null,
                    'rekam_suara'   => null,
                    'status'        => $item->status_sos,
                    'waktu'         => $item->waktu_sos ?? $item->created_at,
                ];
            });

        // 3. Gabungkan kedua collection, urutkan berdasarkan waktu terbisa/terbaru, lalu ambil 10 teratas
        $riwayatGabungan = $laporans->concat($sosList)
            ->sortByDesc('waktu')
            ->values() // Re-index array
            ->take(10);

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil 10 riwayat penanganan terakhir',
            'data'    => $riwayatGabungan,
        ], 200);
    }
}
