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
        $admins = User::whereIn('role', ['admin', 'superadmin'])
            ->with(['grantedBy:id,name', 'accounts'])
            ->orderByRaw("CASE WHEN role = 'superadmin' THEN 0 ELSE 1 END")
            ->latest()
            ->get()
            ->map(function ($admin) {
                $email = $admin->accounts->firstWhere('provider', 'local')?->email
                    ?? $admin->accounts->first()?->email;
                $admin->email = $email;
                return $admin;
            });

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
                    'description' => 'Izin memantau seluruh laporan dan mengubah status penanganan laporan.'
                ],
                [
                    'key'         => 'pantau_peta',
                    'label'       => 'Pantau Peta Kasus',
                    'description' => 'Izin mengakses peta pemantauan sebaran kasus SOS dan relawan.'
                ],
                [
                    'key'         => 'kelola_relawan',
                    'label'       => 'Kelola Relawan',
                    'description' => 'Izin melihat daftar dan mengelola status keaktifan relawan.'
                ],
                [
                    'key'         => 'broadcast_sirene',
                    'label'       => 'Broadcast Sirene Posko',
                    'description' => 'Izin membunyikan sirene alarm darurat di seluruh posko siaga.'
                ],
                [
                    'key'         => 'kelola_admin',
                    'label'       => 'Kelola Administrator',
                    'description' => 'Izin membuat akun admin baru dan mengatur hak akses admin.'
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
            'permissions'   => 'present|array',
            'permissions.*' => 'string|in:verifikasi_relawan,kelola_laporan,pantau_peta,kelola_relawan,broadcast_sirene,kelola_admin',
        ]);

        $admin = User::where('role', 'admin')->find($id);
        if (!$admin) {
            return response()->json(['message' => 'Akun Admin tidak ditemukan.'], 404);
        }

        // Cegah admin non-superadmin mengubah hak aksesnya sendiri
        if ($admin->id === $request->user()->id && $request->user()->role !== 'superadmin') {
            return response()->json([
                'message' => 'Anda tidak dapat mengubah hak akses untuk akun Anda sendiri.'
            ], 403);
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

        // Cegah admin non-superadmin mencabut hak aksesnya sendiri
        if ($admin->id === $request->user()->id && $request->user()->role !== 'superadmin') {
            return response()->json([
                'message' => 'Anda tidak dapat mencabut hak akses untuk akun Anda sendiri.'
            ], 403);
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

    /**
     * 5. DETAIL LENGKAP ADMIN (GET /api/superadmin/admins/{id})
     */
    public function show($id, Request $request)
    {
        $admin = User::whereIn('role', ['admin', 'superadmin'])
            ->with(['grantedBy:id,name', 'accounts'])
            ->find($id);

        if (!$admin) {
            return response()->json(['message' => 'Akun Admin tidak ditemukan.'], 404);
        }

        $email = $admin->accounts->firstWhere('provider', 'local')?->email
            ?? $admin->accounts->first()?->email;
        $admin->email = $email;

        return response()->json([
            'message' => 'Berhasil mengambil detail Admin',
            'data'    => $admin,
        ]);
    }

    /**
     * 6. UPDATE DATA AKUN ADMIN (PUT /api/superadmin/admins/{id})
     */
    public function update($id, Request $request)
    {
        $admin = User::where('role', 'admin')->find($id);
        if (!$admin) {
            return response()->json(['message' => 'Akun Admin tidak ditemukan atau tidak dapat diubah.'], 404);
        }

        $request->validate([
            'name'     => 'sometimes|required|string|max:255',
            'email'    => 'sometimes|required|email|max:255',
            'password' => 'nullable|string|min:6',
            'no_telp'  => 'nullable|string|max:20|unique:users,no_telp,' . $admin->id,
            'alamat'   => 'nullable|string|max:255',
        ]);

        $account = $admin->accounts()->where('provider', 'local')->first();

        if ($request->has('email')) {
            $email = strtolower(trim($request->email));
            $existingAccount = Account::where('email', $email)
                ->where('user_id', '!=', $admin->id)
                ->first();

            if ($existingAccount) {
                return response()->json([
                    'message' => 'Email sudah terdaftar untuk pengguna lain.'
                ], 422);
            }
        }

        try {
            DB::beginTransaction();

            $adminData = [];
            if ($request->has('name')) $adminData['name'] = $request->name;
            if ($request->has('no_telp')) $adminData['no_telp'] = $request->no_telp;
            if ($request->has('alamat')) $adminData['alamat'] = $request->alamat;

            if (!empty($adminData)) {
                $admin->update($adminData);
            }

            if ($account) {
                $accountData = [];
                if (isset($email)) $accountData['email'] = $email;
                if ($request->filled('password')) $accountData['password'] = Hash::make($request->password);

                if (!empty($accountData)) {
                    $account->update($accountData);
                }
            }

            DB::commit();

            $updatedAdmin = $admin->fresh()->load('grantedBy:id,name');
            $updatedAdmin->email = $account ? $account->email : ($request->email ?? null);

            return response()->json([
                'message' => "Data Admin '{$admin->name}' berhasil diperbarui.",
                'data'    => $updatedAdmin,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal memperbarui akun Admin.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 7. AKTIFKAN / NONAKTIFKAN AKUN ADMIN (PUT /api/superadmin/admins/{id}/status)
     */
    public function updateStatus($id, Request $request)
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $admin = User::whereIn('role', ['admin', 'superadmin'])->find($id);
        if (!$admin) {
            return response()->json(['message' => 'Akun Admin tidak ditemukan.'], 404);
        }

        $isActive = filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN);

        // Pencegahan self-lockout
        if ($admin->id === $request->user()->id && !$isActive) {
            return response()->json([
                'message' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.'
            ], 422);
        }

        // Cegah penonaktifan Superadmin
        if ($admin->role === 'superadmin' && !$isActive) {
            return response()->json([
                'message' => 'Akun Superadmin tidak dapat dinonaktifkan.'
            ], 422);
        }

        $admin->is_active = $isActive;
        $admin->save();

        // Jika dinonaktifkan, cabut seluruh token aktif admin tersebut
        if (!$isActive) {
            $admin->tokens()->delete();
        }

        $statusText = $isActive ? 'diaktifkan' : 'dinonaktifkan';

        return response()->json([
            'message' => "Akun Admin '{$admin->name}' berhasil {$statusText}.",
            'data'    => [
                'id'        => $admin->id,
                'name'      => $admin->name,
                'is_active' => (bool) $admin->is_active,
            ]
        ]);
    }
}
