<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Laporan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class LaporanController extends Controller
{
    /**
     * 1. GET OPTIONS / TEMPLATE KATEGORI & PESAN CEPAT
     * Digunakan oleh mobile app untuk mengisi opsi di halaman "Kirim Laporan Cepat".
     */
    public function getOptions()
    {
        return response()->json([
            'kategori_laporan' => [
                [
                    'id'       => 'butuh_pendamping',
                    'title'    => 'Butuh Pendamping',
                    'subtitle' => 'Relawan & Petugas',
                ],
                [
                    'id'       => 'kondisi_medis',
                    'title'    => 'Kondisi Medis',
                    'subtitle' => 'Ambulans & Obat',
                ],
                [
                    'id'       => 'ancaman_bahaya',
                    'title'    => 'Ancaman / Bahaya',
                    'subtitle' => 'Keamanan Cepat',
                ],
                [
                    'id'       => 'tersesat',
                    'title'    => 'Tersesat',
                    'subtitle' => 'Panduan Arah',
                ],
                [
                    'id'       => 'aksesibilitas_rusak',
                    'title'    => 'Aksesibilitas Rusak',
                    'subtitle' => 'Bantuan Akses',
                ],
                [
                    'id'       => 'lainnya',
                    'title'    => 'Lainnya',
                    'subtitle' => 'Bantuan Khusus',
                ],
            ],
            'pesan_cepat' => [
                'Saya butuh bantuan di lokasi saya',
                'Saya tidak dapat berbicara / mendengar',
                'Tolong hubungi kontak keluarga saya',
                'Saya butuh bantuan mobilitas / kursi roda',
            ]
        ]);
    }

    /**
     * 2. DAFTAR LAPORAN (GET /api/laporan)
     * - Pengguna: melihat laporan milik sendiri.
     * - Relawan / Admin: melihat seluruh laporan.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Laporan::with(['pengguna', 'relawan'])->latest();

        if ($user->role === 'pengguna') {
            $query->where('id_pengguna', $user->id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $laporans = $query->paginate(15);

        $laporans->getCollection()->transform(function ($item) {
            if ($item->foto_laporan) {
                $item->foto_laporan_url = url('storage/' . $item->foto_laporan);
            }
            if ($item->rekam_suara) {
                $item->rekam_suara_url = url('storage/' . $item->rekam_suara);
            }
            return $item;
        });

        return response()->json([
            'message' => 'Berhasil mengambil daftar laporan',
            'data'    => $laporans,
        ]);
    }

    /**
     * 3. BUAT LAPORAN BARU (POST /api/laporan)
     * Menerima form sesuai UI "Kirim Laporan Cepat"
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kategori_laporan'    => 'required|string|max:255',
            'lokasi_laporan'      => 'required|string|max:255',
            'deskripsi'           => 'nullable|string',
            'pesan_cepat'         => 'nullable',
            'keterangan_tambahan' => 'nullable|string',
            'foto_laporan'        => 'nullable|file|image|mimes:jpeg,png,jpg,webp|max:5120',
            'rekam_suara'         => 'nullable|file|mimes:mp3,wav,m4a,aac,ogg,webm,3gp|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Combine deskripsi, pesan_cepat, & keterangan_tambahan
        $finalDeskripsi = $request->input('deskripsi', '');
        if (empty($finalDeskripsi)) {
            $parts = [];
            $pesanCepat = $request->input('pesan_cepat');
            if (is_array($pesanCepat)) {
                $parts[] = implode('. ', $pesanCepat);
            } elseif (is_string($pesanCepat) && !empty($pesanCepat)) {
                $parts[] = $pesanCepat;
            }

            if ($request->filled('keterangan_tambahan')) {
                $parts[] = $request->keterangan_tambahan;
            }

            $finalDeskripsi = implode("\n\nCatatan Tambahan: ", $parts);
        }

        if (empty($finalDeskripsi)) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors'  => ['deskripsi' => ['Deskripsi, Pesan Cepat, atau Keterangan Tambahan harus diisi.']]
            ], 422);
        }

        $fotoPath = null;
        if ($request->hasFile('foto_laporan')) {
            $fotoPath = $request->file('foto_laporan')->store('laporan/foto', 'public');
        }

        $audioPath = null;
        if ($request->hasFile('rekam_suara')) {
            $audioPath = $request->file('rekam_suara')->store('laporan/audio', 'public');
        }

        $laporan = Laporan::create([
            'id_pengguna'      => $request->user()->id,
            'id_relawan'       => null,
            'lokasi_laporan'   => $request->lokasi_laporan,
            'kategori_laporan' => $request->kategori_laporan,
            'deskripsi'        => $finalDeskripsi,
            'foto_laporan'     => $fotoPath,
            'rekam_suara'      => $audioPath,
            'status'           => 'aktif',
            'waktu_laporan'    => now(),
        ]);

        $laporan->load(['pengguna']);
        if ($laporan->foto_laporan) {
            $laporan->foto_laporan_url = url('storage/' . $laporan->foto_laporan);
        }
        if ($laporan->rekam_suara) {
            $laporan->rekam_suara_url = url('storage/' . $laporan->rekam_suara);
        }

        return response()->json([
            'message' => 'Laporan berhasil terkirim! Tim relawan akan segera merespon.',
            'data'    => $laporan,
        ], 201);
    }

    /**
     * 4. DETAIL LAPORAN (GET /api/laporan/{id})
     */
    public function show($id, Request $request)
    {
        $laporan = Laporan::with(['pengguna', 'relawan'])->find($id);

        if (!$laporan) {
            return response()->json(['message' => 'Laporan tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user->role === 'pengguna' && $laporan->id_pengguna !== $user->id) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        if ($laporan->foto_laporan) {
            $laporan->foto_laporan_url = url('storage/' . $laporan->foto_laporan);
        }
        if ($laporan->rekam_suara) {
            $laporan->rekam_suara_url = url('storage/' . $laporan->rekam_suara);
        }

        return response()->json([
            'message' => 'Detail laporan berhasil diambil',
            'data'    => $laporan,
        ]);
    }

    /**
     * 5. UPDATE STATUS LAPORAN (PUT/PATCH /api/laporan/{id}/status)
     * Khusus Relawan / Admin untuk menanggapi atau menyelesaikan laporan.
     */
    public function updateStatus($id, Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['relawan', 'admin', 'superadmin'])) {
            return response()->json(['message' => 'Akses ditolak. Hanya relawan dan admin yang dapat menanggapi laporan.'], 403);
        }

        $request->validate([
            'status' => 'required|in:aktif,proses,selesai',
        ]);

        $laporan = Laporan::find($id);
        if (!$laporan) {
            return response()->json(['message' => 'Laporan tidak ditemukan'], 404);
        }

        $laporan->status = $request->status;
        if ($user->role === 'relawan' && empty($laporan->id_relawan)) {
            $laporan->id_relawan = $user->id;
        }
        $laporan->save();

        $laporan->load(['pengguna', 'relawan']);

        return response()->json([
            'message' => 'Status laporan berhasil diperbarui',
            'data'    => $laporan,
        ]);
    }
}
