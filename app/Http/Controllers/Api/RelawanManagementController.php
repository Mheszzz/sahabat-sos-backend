<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class RelawanManagementController extends Controller
{
    public function index()
    {
        $relawan = User::where('role', 'relawan')
                ->select('id', 'name', 'alamat', 'no_telp', 'foto_profile', 'is_active')
                ->paginate(10);
        return response()->json([
            'status' => 'success',
            'data' => $relawan
        ], 200);
    }

    public function updateStatus(Request $request, $id)
    {
        // Validasi input Wajib boolean (true / false)
        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $relawan = User::where('role', 'relawan')->findOrFail($id);

        $relawan->update([
            'is_active' => $request->is_active
        ]);

        // Jika status diubah menjadi FALSE (nonaktif), langsung cabut token loginnya
        if (!$request->is_active) {
            $relawan->tokens()->delete();
        }

        $statusPesan = $request->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return response()->json([
            'status' => 'success',
            'message' => "Akun relawan berhasil {$statusPesan}.",
            'data' => [
                'id' => $relawan->id,
                'name' => $relawan->name,
                'is_active' => (bool) $relawan->is_active
            ]
        ], 200);
    }
}
