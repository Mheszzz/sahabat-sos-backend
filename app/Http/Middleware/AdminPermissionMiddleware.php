<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminPermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $permission
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // Superadmin bypasses all permission checks
        if ($user->role === 'superadmin') {
            return $next($request);
        }

        // If not admin or does not have the required permission
        if ($user->role !== 'admin' || !$user->hasPermission($permission)) {
            return response()->json([
                'message'             => "Akses ditolak. Anda belum memiliki hak akses '{$permission}' dari Superadmin. Silakan hubungi Superadmin untuk mengaktifkan izin ini.",
                'required_permission' => $permission,
                'current_permissions' => $user->permissions ?? [],
            ], 403);
        }

        return $next($request);
    }
}
