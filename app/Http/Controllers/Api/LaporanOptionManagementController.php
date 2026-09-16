<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KategoriLaporan;
use App\Models\PesanCepat;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LaporanOptionManagementController extends Controller
{
    // --- KATEGORI LAPORAN ---

    public function indexKategori()
    {
        return response()->json([
            'message' => 'Berhasil mengambil daftar kategori laporan',
            'data'    => KategoriLaporan::all(),
        ]);
    }

    public function storeKategori(Request $request)
    {
        $request->validate([
            'title'     => 'required|string|max:255',
            'id'        => 'nullable|string|max:100|unique:kategori_laporans,id',
            'subtitle'  => 'nullable|string|max:255',
            'icon'      => 'nullable|string|max:100',
            'color'     => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ]);

        $id = $request->id ? Str::slug($request->id, '_') : Str::slug($request->title, '_');

        $kategori = KategoriLaporan::create([
            'id'        => $id,
            'title'     => $request->title,
            'subtitle'  => $request->subtitle,
            'icon'      => $request->icon ?? 'help_outline_rounded',
            'color'     => $request->color ?? '#546E7A',
            'is_active' => $request->input('is_active', true),
        ]);

        return response()->json([
            'message' => 'Kategori laporan berhasil ditambahkan',
            'data'    => $kategori,
        ], 201);
    }

    public function updateKategori(Request $request, $id)
    {
        $kategori = KategoriLaporan::find($id);
        if (!$kategori) {
            return response()->json(['message' => 'Kategori laporan tidak ditemukan'], 404);
        }

        $request->validate([
            'title'     => 'sometimes|required|string|max:255',
            'subtitle'  => 'nullable|string|max:255',
            'icon'      => 'nullable|string|max:100',
            'color'     => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ]);

        $kategori->update($request->only(['title', 'subtitle', 'icon', 'color', 'is_active']));

        return response()->json([
            'message' => 'Kategori laporan berhasil diperbarui',
            'data'    => $kategori,
        ]);
    }

    public function destroyKategori($id)
    {
        $kategori = KategoriLaporan::find($id);
        if (!$kategori) {
            return response()->json(['message' => 'Kategori laporan tidak ditemukan'], 404);
        }

        $kategori->delete();

        return response()->json(['message' => 'Kategori laporan berhasil dihapus']);
    }


    // --- PESAN CEPAT ---

    public function indexPesan()
    {
        return response()->json([
            'message' => 'Berhasil mengambil daftar pesan cepat',
            'data'    => PesanCepat::all(),
        ]);
    }

    public function storePesan(Request $request)
    {
        $request->validate([
            'pesan'     => 'required|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        $pesanCepat = PesanCepat::create([
            'pesan'     => $request->pesan,
            'is_active' => $request->input('is_active', true),
        ]);

        return response()->json([
            'message' => 'Pesan cepat berhasil ditambahkan',
            'data'    => $pesanCepat,
        ], 201);
    }

    public function updatePesan(Request $request, $id)
    {
        $pesanCepat = PesanCepat::find($id);
        if (!$pesanCepat) {
            return response()->json(['message' => 'Pesan cepat tidak ditemukan'], 404);
        }

        $request->validate([
            'pesan'     => 'sometimes|required|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        $pesanCepat->update($request->only(['pesan', 'is_active']));

        return response()->json([
            'message' => 'Pesan cepat berhasil diperbarui',
            'data'    => $pesanCepat,
        ]);
    }

    public function destroyPesan($id)
    {
        $pesanCepat = PesanCepat::find($id);
        if (!$pesanCepat) {
            return response()->json(['message' => 'Pesan cepat tidak ditemukan'], 404);
        }

        $pesanCepat->delete();

        return response()->json(['message' => 'Pesan cepat berhasil dihapus']);
    }
}
