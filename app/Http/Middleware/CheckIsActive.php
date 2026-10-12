<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // Jika user terautentikasi tetapi statusnya tidak aktif
        if ($user && !$user->is_active) {
            // Relawan yang dinonaktifkan tetap boleh mengakses profil dirinya untuk melihat keterangan status
            if ($user->role === 'relawan' && (
                $request->is('api/relawan/profile') || 
                $request->is('relawan/profile') || 
                $request->is('api/user/me') || 
                $request->is('user/me') || 
                $request->is('api/me') || 
                $request->is('me')
            )) {
                return $next($request);
            }

            // Untuk relawan di rute lain, tolak dengan 403 tanpa menghapus token agar sesi profil tidak hilang
            if ($user->role === 'relawan') {
                return response()->json([
                    'status'    => 'error',
                    'message'   => 'Akun relawan Anda telah dinonaktifkan oleh administrator.',
                    'is_active' => false,
                ], 403);
            }

            // Hapus token yang sedang digunakan untuk role pengguna biasa
            $user->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Akun kamu telah dinonaktifkan. Akses ditolak.'
            ], 403);
        }

        return $next($request);
    }
}
