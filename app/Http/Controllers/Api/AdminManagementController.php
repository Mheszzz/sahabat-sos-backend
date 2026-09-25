<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminManagementController extends Controller
{
    /**
     * 1. DAFTAR ADMIN BESERTA STATUS HAK AKSESNYA (GET /api/superadmin/admins)
     */
    public function index(Request $request)
    {
        $admins = User::where('role', 'admin')
            ->with(['grantedBy:id,name'])
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Berhasil mengambil daftar Admin',
            'total'   => $admins->count(),
            'data'    => $admins,
            'available_permissions' => [
                [
                    'key'         => 'verifikasi_relawan',
                    'label'       => 'Verifikasi Relawan',
                    'description' => 'Izin melihat daftar relawan pending dan melakukan verifikasi / penolakan.'
                ],
                [
                    'key'         => 'kelola_laporan',
                    'label'       => 'Kelola Laporan Darurat',
                    'description' => 'Izin memantau seluruh laporan dan mengubah status laporan.'
                ],
            ]
        ]);
    }

    /**
     * 2. TAMBAH ADMIN BARU (POST /api/superadmin/admins)
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => ['required', 'email', 'max:255'],
            'password' => 'required|string|min:6',
            'no_telp'  => 'nullable|string|max:20|unique:users,no_telp',
            'alamat'   => 'nullable|string|max:255',
        ]);

        $email = strtolower(trim($request->email));

        $existingAccount = Account::where('provider', 'local')
            ->where('email', $email)
            ->first();

        if ($existingAccount) {
            return response()->json([
                'message' => 'Email sudah terdaftar. Gunakan email lain.'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $admin = User::create([
                'name'                => $request->name,
                'role'                => 'admin',
                'no_telp'             => $request->no_telp ?? null,
                'alamat'              => $request->alamat ?? null,
                'status_verifikasi'   => 'terverifikasi',
                'persetujuan_privasi' => true,
                'waktu_persetujuan'   => now(),
                'permissions'         => [],
            ]);

            Account::create([
                'user_id'  => $admin->id,
                'provider' => 'local',
                'email'    => $email,
                'password' => Hash::make($request->password),
            ]);

            DB::commit();

            return response()->json([
                'message' => "Akun Admin '{$admin->name}' berhasil dibuat. Hak akses default masih kosong sampai Anda mengaktifkannya.",
                'data'    => $admin,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal membuat akun Admin.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 3. ATUR / BERIKAN HAK AKSES ADMIN (PUT /api/superadmin/admins/{id}/permissions)
     */
    public function updatePermissions($id, Request $request)
    {
        $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'string|in:verifikasi_relawan,kelola_laporan',
        ]);

        $admin = User::where('role', 'admin')->find($id);
        if (!$admin) {
            return response()->json(['message' => 'Akun Admin tidak ditemukan.'], 404);
        }

        $admin->update([
            'permissions'            => array_values(array_unique($request->permissions)),
            'permissions_granted_at' => now(),
            'permissions_granted_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => "Hak akses untuk Admin '{$admin->name}' berhasil diperbarui.",
            'admin'   => $admin->fresh()->load('grantedBy:id,name'),
        ]);
    }

    /**
     * 4. CABUT SELURUH HAK AKSES ADMIN (DELETE /api/superadmin/admins/{id}/permissions)
     */
    public function revokePermissions($id, Request $request)
    {
        $admin = User::where('role', 'admin')->find($id);
        if (!$admin) {
            return response()->json(['message' => 'Akun Admin tidak ditemukan.'], 404);
        }

        $admin->update([
            'permissions'            => [],
            'permissions_granted_at' => null,
            'permissions_granted_by' => null,
        ]);

        return response()->json([
            'message' => "Seluruh hak akses untuk Admin '{$admin->name}' telah dicabut. Admin sekarang hanya dapat mengakses Beranda.",
            'admin'   => $admin->fresh(),
        ]);
    }
}
