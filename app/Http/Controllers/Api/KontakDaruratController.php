<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kontak_darurat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class KontakDaruratController extends Controller
{
    /**
     * 1. GET ALL KONTAK DARURAT (GET /api/pengguna/kontak-darurat)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $kontakList = Kontak_darurat::where('id_pengguna', $user->id)
            ->orderByRaw("CASE WHEN tipe = 'utama' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'message' => 'Berhasil mengambil daftar kontak darurat',
            'data'    => $kontakList,
        ], 200);
    }

    /**
     * 2. TAMBAH KONTAK DARURAT (POST /api/pengguna/kontak-darurat)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'nama'         => 'required|string|max:255',
            'no_telp'      => 'required|string|max:20',
            'pesan'        => 'nullable|string',
            'terima_notif' => 'nullable|boolean',
            'tipe'         => 'nullable|in:utama,sekunder',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $tipe = $request->input('tipe', 'sekunder');
        $terimaNotif = $request->has('terima_notif') 
            ? filter_var($request->terima_notif, FILTER_VALIDATE_BOOLEAN) 
            : true;

        // Jika kontak baru diset sebagai 'utama', demote kontak utama sebelumnya menjadi 'sekunder'
        if ($tipe === 'utama') {
            Kontak_darurat::where('id_pengguna', $user->id)
                ->where('tipe', 'utama')
                ->update(['tipe' => 'sekunder']);
        }

        $kontak = Kontak_darurat::create([
            'id_pengguna'  => $user->id,
            'nama'         => $request->nama,
            'no_telp'      => $request->no_telp,
            'pesan'        => $request->pesan,
            'terima_notif' => $terimaNotif,
            'tipe'         => $tipe,
        ]);

        return response()->json([
            'message' => 'Kontak darurat berhasil ditambahkan',
            'data'    => $kontak,
        ], 201);
    }

    /**
     * 3. DETAIL KONTAK DARURAT (GET /api/pengguna/kontak-darurat/{id})
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();

        $kontak = Kontak_darurat::where('id_pengguna', $user->id)
            ->where('id', $id)
            ->first();

        if (!$kontak) {
            return response()->json([
                'message' => 'Kontak darurat tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'message' => 'Berhasil mengambil detail kontak darurat',
            'data'    => $kontak,
        ], 200);
    }

    /**
     * 4. UPDATE KONTAK DARURAT (PUT/PATCH /api/pengguna/kontak-darurat/{id})
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();

        $kontak = Kontak_darurat::where('id_pengguna', $user->id)
            ->where('id', $id)
            ->first();

        if (!$kontak) {
            return response()->json([
                'message' => 'Kontak darurat tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nama'         => 'nullable|string|max:255',
            'no_telp'      => 'nullable|string|max:20',
            'pesan'        => 'nullable|string',
            'terima_notif' => 'nullable|boolean',
            'tipe'         => 'nullable|in:utama,sekunder',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $dataToUpdate = [];

        if ($request->has('nama')) {
            $dataToUpdate['nama'] = $request->nama;
        }
        if ($request->has('no_telp')) {
            $dataToUpdate['no_telp'] = $request->no_telp;
        }
        if ($request->has('pesan')) {
            $dataToUpdate['pesan'] = $request->pesan;
        }
        if ($request->has('terima_notif')) {
            $dataToUpdate['terima_notif'] = filter_var($request->terima_notif, FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->has('tipe')) {
            $newTipe = $request->tipe;
            if ($newTipe === 'utama' && $kontak->tipe !== 'utama') {
                Kontak_darurat::where('id_pengguna', $user->id)
                    ->where('tipe', 'utama')
                    ->where('id', '!=', $kontak->id)
                    ->update(['tipe' => 'sekunder']);
            }
            $dataToUpdate['tipe'] = $newTipe;
        }

        $kontak->update($dataToUpdate);

        return response()->json([
            'message' => 'Kontak darurat berhasil diperbarui',
            'data'    => $kontak->fresh(),
        ], 200);
    }

    /**
     * 5. HAPUS KONTAK DARURAT (DELETE /api/pengguna/kontak-darurat/{id})
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        $kontak = Kontak_darurat::where('id_pengguna', $user->id)
            ->where('id', $id)
            ->first();

        if (!$kontak) {
            return response()->json([
                'message' => 'Kontak darurat tidak ditemukan',
            ], 404);
        }

        $kontak->delete();

        return response()->json([
            'message' => 'Kontak darurat berhasil dihapus',
        ], 200);
    }

    /**
     * 6. TOGGLE NOTIFIKASI SOS (PATCH /api/pengguna/kontak-darurat/{id}/toggle-notif)
     */
    public function toggleNotif(Request $request, $id)
    {
        $user = $request->user();

        $kontak = Kontak_darurat::where('id_pengguna', $user->id)
            ->where('id', $id)
            ->first();

        if (!$kontak) {
            return response()->json([
                'message' => 'Kontak darurat tidak ditemukan',
            ], 404);
        }

        if ($request->has('terima_notif')) {
            $kontak->terima_notif = filter_var($request->terima_notif, FILTER_VALIDATE_BOOLEAN);
        } else {
            $kontak->terima_notif = !$kontak->terima_notif;
        }

        $kontak->save();

        return response()->json([
            'message' => 'Status notifikasi kontak darurat berhasil diperbarui',
            'data'    => $kontak,
        ], 200);
    }
}
