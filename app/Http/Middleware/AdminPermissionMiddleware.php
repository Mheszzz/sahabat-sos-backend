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
     * @param  string  ...$permissions
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
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

        // Flatten permissions supporting comma or pipe separation
        $flattened = [];
        foreach ($permissions as $p) {
            foreach (preg_split('/[,|]/', $p) as $sub) {
                $sub = trim($sub);
                if ($sub !== '') {
                    $flattened[] = $sub;
                }
            }
        }

        if (empty($flattened)) {
            return $next($request);
        }

        // Check if admin has at least one of the required permissions
        $hasAny = false;
        if ($user->role === 'admin') {
            foreach ($flattened as $perm) {
                if ($user->hasPermission($perm)) {
                    $hasAny = true;
                    break;
                }
            }
        }

        if (!$hasAny) {
            $requiredLabel = implode(' atau ', $flattened);
            return response()->json([
                'message'             => "Akses ditolak. Anda belum memiliki hak akses '{$requiredLabel}' dari Superadmin. Silakan hubungi Superadmin untuk mengaktifkan izin ini.",
                'required_permission' => $flattened[0],
                'current_permissions' => $user->permissions ?? [],
            ], 403);
        }

        return $next($request);
    }
}
