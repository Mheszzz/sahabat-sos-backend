<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SOS;
use App\Models\Laporan;

class KasusAktifController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Proteksi Hak Akses Admin / Superadmin
        if (!$user->hasPermission('kelola_laporan')) {
            return response()->json([
                'message' => "Akses ditolak. Anda belum memiliki hak akses 'kelola_laporan'. Silakan hubungi Superadmin."
            ], 403);
        }

        $tipe = $request->query('tipe'); // Opsional: ?tipe=laporan atau ?tipe=sos
        $response = [];

        // 1. Ambil Data Laporan
        if (!$tipe || $tipe === 'laporan') {
            $laporan = Laporan::latest('waktu_laporan')
                            ->whereIn('status', ['aktif', 'proses'])
                            ->paginate(10, ['*'], 'page_laporan');

            // Transformasi hanya atribut yang dibutuhkan
            $laporan->getCollection()->transform(function ($item) {
                return [
                    'id'             => $item->id,
                    'status'         => $item->status,
                    'kategori_kasus' => 'laporan',
                    'id_pengguna'    => $item->id_pengguna,
                    'lokasi'         => [
                        'alamat'    => $item->lokasi_laporan,
                        'latitude'  => $item->latitude,
                        'longitude' => $item->longitude,
                    ],
                    'id_relawan'     => $item->id_relawan,
                    'waktu'          => $item->waktu_laporan ?? $item->created_at,
                ];
            });

            $response['laporan'] = $laporan;
        }

        // 2. Ambil Data SOS
        if (!$tipe || $tipe === 'sos') {
            $sos = SOS::latest('waktu_sos')
                        ->whereIn('status_sos', ['aktif', 'proses'])
                        ->paginate(10, ['*'], 'page_sos');

            // Transformasi hanya atribut yang dibutuhkan
            $sos->getCollection()->transform(function ($item) {
                return [
                    'id'             => $item->id,
                    'status'         => $item->status_sos,
                    'kategori_kasus' => 'sos',
                    'id_pengguna'    => $item->id_pengguna,
                    'lokasi'         => [
                        'latitude'  => $item->latitude,
                        'longitude' => $item->longitude,
                    ],
                    'id_relawan'     => $item->id_relawan,
                    'waktu'          => $item->waktu_sos ?? $item->created_at,
                ];
            });

            $response['sos'] = $sos;
        }

        return response()->json([
            'message' => 'Berhasil mengambil daftar tugas aktif',
            'data'    => $response,
        ]);
    }

    public function show($tipe, $id, Request $request)
    {
        $user = $request->user();

        // Proteksi Hak Akses Admin / Superadmin
        if (!$user->hasPermission('kelola_laporan')) {
            return response()->json([
                'message' => "Akses ditolak. Anda belum memiliki hak akses 'kelola_laporan'. Silakan hubungi Superadmin."
            ], 403);
        }

        $data = null;

        if ($tipe === 'laporan') {
            // Eager loading relasi pengguna -> kontakDarurat & relawan
            $data = Laporan::with(['pengguna.kontakDarurat', 'relawan'])->find($id);

            if ($data) {
                $data->tipe = 'laporan';
                if ($data->foto_laporan) {
                    $data->foto_laporan_url = url('storage/' . $data->foto_laporan);
                }
                if ($data->rekam_suara) {
                    $data->rekam_suara_url = url('storage/' . $data->rekam_suara);
                }
            }
        } elseif ($tipe === 'sos') {
            // Eager loading relasi pengguna -> kontakDarurat & relawan
            $data = SOS::with(['pengguna.kontakDarurat', 'relawan'])->find($id);

            if ($data) {
                $data->tipe = 'sos';
                if (isset($data->foto_sos) && $data->foto_sos) {
                    $data->foto_sos_url = url('storage/' . $data->foto_sos);
                }
            }
        } else {
            return response()->json([
                'message' => "Tipe tidak valid. Gunakan 'laporan' atau 'sos'."
            ], 400);
        }

        if (!$data) {
            return response()->json(['message' => ucfirst($tipe) . ' tidak ditemukan'], 404);
        }

        return response()->json([
            'message' => "Detail $tipe berhasil diambil",
            'data'    => $data,
        ]);
    }
}
