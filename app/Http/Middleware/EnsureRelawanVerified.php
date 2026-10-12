<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRelawanVerified
{
    /**
     * Handle an incoming request.
     * Memastikan jika user adalah relawan, akunnya harus berstatus 'terverifikasi'.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->role === 'relawan') {
            if (!$user->is_active) {
                return response()->json([
                    'status'            => 'error',
                    'message'           => 'Akun relawan Anda telah dinonaktifkan oleh administrator.',
                    'is_active'         => false,
                    'status_verifikasi' => $user->status_verifikasi ?? 'terverifikasi',
                ], 403);
            }

            if ($user->status_verifikasi !== 'terverifikasi') {
                return response()->json([
                    'status'            => 'error',
                    'message'           => 'Akun relawan Anda belum diverifikasi oleh admin.',
                    'is_active'         => true,
                    'status_verifikasi' => $user->status_verifikasi ?? 'pending',
                ], 403);
            }
        }

        return $next($request);
    }
}
