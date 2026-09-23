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
            // Hapus token yang sedang digunakan
            $user->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Akun kamu telah dinonaktifkan. Akses ditolak.'
            ], 403);
        }

        return $next($request);
    }
}
