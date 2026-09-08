<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /**
     * 1. LOGIN GOOGLE UNTUK MOBILE / POSTMAN (Kirim Token via API JSON)
     */
    public function loginGoogleMobile(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'role'  => 'nullable|in:pengguna,relawan',
        ]);

        $requestedRole = $request->input('role', 'pengguna');

        try {
            // Verifikasi token Google yang dikirim dari Flutter/Postman
            $googleUser = Socialite::driver('google')->userFromToken($request->token);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Token Google tidak valid atau sudah expired',
                'error'   => $e->getMessage()
            ], 401);
        }

        try {
            DB::beginTransaction();

            // 1. Cek apakah akun Google ini sudah ada di tabel accounts
            $account = Account::where('provider', 'google')
                ->where('provider_id', $googleUser->getId())
                ->first();

            if ($account) {
                $user = $account->user;

                // Proteksi: Tolak jika role bukan pengguna atau relawan
                if (!in_array($user->role, ['pengguna', 'relawan'])) {
                    return response()->json([
                        'message' => 'Akses ditolak. Jalur login ini hanya untuk Pengguna dan Relawan.'
                    ], 403);
                }

                // Proteksi: Tolak jika role terdaftar berbeda dengan role aplikasi yang digunakan
                if ($user->role !== $requestedRole) {
                    return response()->json([
                        'message' => "Akses ditolak. Akun Anda terdaftar sebagai '{$user->role}', tidak bisa login di aplikasi '{$requestedRole}'."
                    ], 403);
                }
            } else {
                // 2. Jika belum ada di accounts untuk provider google, cek apakah email sudah ada di tabel accounts
                $existingAccount = Account::where('email', $googleUser->getEmail())->first();

                if ($existingAccount) {
                    $user = $existingAccount->user;

                    if (!in_array($user->role, ['pengguna', 'relawan'])) {
                        return response()->json([
                            'message' => 'Akses ditolak. Email ini terdaftar sebagai Admin/Superadmin.'
                        ], 403);
                    }

                    if ($user->role !== $requestedRole) {
                        return response()->json([
                            'message' => "Akses ditolak. Akun Anda terdaftar sebagai '{$user->role}', tidak bisa login di aplikasi '{$requestedRole}'."
                        ], 403);
                    }
                } else {
                    // 3. Buat User baru jika belum terdaftar
                    $user = User::create([
                        'name'         => $googleUser->getName(),
                        'foto_profile' => $googleUser->getAvatar(),
                        'role'         => $requestedRole,
                    ]);
                }

                // 4. Tautkan Akun Google ke tabel accounts
                Account::create([
                    'user_id'     => $user->id,
                    'provider'    => 'google',
                    'provider_id' => $googleUser->getId(),
                    'email'       => $googleUser->getEmail(),
                    'password'    => null,
                ]);
            }

            DB::commit();

            // Cek apakah profil pengguna sudah lengkap
            $isProfileComplete = $user->isProfileComplete();

            // Generate Token Sanctum
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message'             => $isProfileComplete ? 'Login Google berhasil' : 'Login Google berhasil, silakan lengkapi profil Anda.',
                'access_token'        => $token,
                'token_type'          => 'Bearer',
                'is_profile_complete' => $isProfileComplete,
                'user'                => $user,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal memproses data user',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 2. LENGKAPI PROFIL (Pengguna & Relawan Baru)
     */
    public function completeProfile(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['pengguna', 'relawan'])) {
            return response()->json([
                'message' => 'Layanan ini hanya untuk Pengguna dan Relawan.'
            ], 403);
        }

        $validated = $request->validate([
            'alamat'              => 'required|string|max:255',
            'no_telp'             => 'required|string|max:20|unique:users,no_telp,' . $user->id,
            'kategori_user'       => 'nullable|in:umum,tunarungu,tunanetra,tunawicara',
            'catatan_medis'       => 'nullable|string',
            'getaran'             => 'nullable|boolean',
            'talkback'            => 'nullable|boolean',
            'panduan_suara'       => 'nullable|boolean',
            'text_besar'          => 'nullable|boolean',
            'status_ketersediaan' => 'nullable|string',
            'device_id'           => 'nullable|string',
            'lokasi_user'         => 'nullable|string',
        ]);

        $user->update($validated);

        return response()->json([
            'message'             => 'Profil berhasil diperbarui.',
            'is_profile_complete' => $user->isProfileComplete(),
            'user'                => $user->refresh(),
        ]);
    }

    /**
     * 3. LOGIN ADMIN & SUPERADMIN (Web React Dashboard via Username/Email & Password)
     */
    public function adminLogin(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // Cari kredensial lokal di tabel accounts berdasarkan email
        $account = Account::where('email', $request->email)
            ->where('provider', 'local')
            ->first();

        if (!$account || !Hash::check($request->password, $account->password)) {
            return response()->json([
                'message' => 'Email atau password salah.'
            ], 401);
        }

        $user = $account->user;

        if (!$user || !in_array($user->role, ['admin', 'superadmin'])) {
            return response()->json([
                'message' => 'Kredensial salah atau Anda tidak memiliki akses admin.'
            ], 401);
        }

        // Generate Sanctum Token
        $token = $user->createToken('admin_auth_token')->plainTextToken;

        return response()->json([
            'message'      => 'Login Admin berhasil',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
        ]);
    }

    /**
     * 4. ME / GET CURRENT USER PROFILE
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user'                => $user,
            'is_profile_complete' => $user->isProfileComplete(),
        ]);
    }

    /**
     * 5. URL REDIRECT GOOGLE (Khusus Pengujian via Web Browser)
     */
    public function redirectToGoogle(Request $request)
    {
        $role = $request->query('role', 'pengguna');
        
        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => $role])
            ->redirect();
    }

    /**
     * 6. CALLBACK GOOGLE (Khusus Pengujian via Web Browser)
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            $requestedRole = $request->input('state', 'pengguna');

            if (!in_array($requestedRole, ['pengguna', 'relawan'])) {
                $requestedRole = 'pengguna';
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal verifikasi akun Google'], 401);
        }

        try {
            DB::beginTransaction();

            $account = Account::where('provider', 'google')
                ->where('provider_id', $googleUser->getId())
                ->first();

            if ($account) {
                $user = $account->user;

                if (!in_array($user->role, ['pengguna', 'relawan'])) {
                    return response()->json([
                        'message' => 'Akses ditolak. Jalur login ini hanya untuk Pengguna dan Relawan.'
                    ], 403);
                }

                if ($user->role !== $requestedRole) {
                    return response()->json([
                        'message' => "Akses ditolak. Akun Anda terdaftar sebagai '{$user->role}', tidak bisa login di aplikasi '{$requestedRole}'."
                    ], 403);
                }
            } else {
                $existingAccount = Account::where('email', $googleUser->getEmail())->first();

                if ($existingAccount) {
                    $user = $existingAccount->user;

                    if (!in_array($user->role, ['pengguna', 'relawan'])) {
                        return response()->json([
                            'message' => 'Akses ditolak. Email ini terdaftar sebagai Admin.'
                        ], 403);
                    }

                    if ($user->role !== $requestedRole) {
                        return response()->json([
                            'message' => "Akses ditolak. Akun Anda terdaftar sebagai '{$user->role}', tidak bisa login di aplikasi '{$requestedRole}'."
                        ], 403);
                    }
                } else {
                    $user = User::create([
                        'name'         => $googleUser->getName(),
                        'foto_profile' => $googleUser->getAvatar(),
                        'role'         => $requestedRole,
                    ]);
                }

                Account::create([
                    'user_id'     => $user->id,
                    'provider'    => 'google',
                    'provider_id' => $googleUser->getId(),
                    'email'       => $googleUser->getEmail(),
                    'password'    => null,
                ]);
            }

            DB::commit();

            $isProfileComplete = $user->isProfileComplete();
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message'             => 'Login Google berhasil',
                'access_token'        => $token,
                'token_type'          => 'Bearer',
                'is_profile_complete' => $isProfileComplete,
                'user'                => $user,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal memproses data user',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * REGISTRASI PENGGUNA & RELAWAN (Email & Password)
     */
    public function register(Request $request)
    {
        $request->validate([
            'name'                => 'required|string|max:255',
            'email'               => 'required|email',
            'password'            => 'required|string|min:6',
            'role'                => 'required|in:pengguna,relawan',
            'persetujuan_privasi' => 'required|accepted',
            'no_telp'             => 'nullable|string|max:20|unique:users,no_telp',
            'alamat'              => 'nullable|string|max:255',
        ], [
            'persetujuan_privasi.required' => 'Anda harus menyetujui syarat & ketentuan penggunaan data pribadi dan medis untuk mendaftar.',
            'persetujuan_privasi.accepted' => 'Anda harus menyetujui syarat & ketentuan penggunaan data pribadi dan medis untuk mendaftar.',
        ]);

        $existingAccount = Account::where('email', $request->email)->first();
        if ($existingAccount) {
            return response()->json([
                'message' => 'Email sudah terdaftar. Silakan gunakan email lain atau login.'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $user = User::create([
                'name'                => $request->name,
                'role'                => $request->role,
                'no_telp'             => $request->no_telp ?? null,
                'alamat'              => $request->alamat ?? null,
                'persetujuan_privasi' => true,
                'waktu_persetujuan'   => now(),
            ]);

            Account::create([
                'user_id'  => $user->id,
                'provider' => 'local',
                'email'    => $request->email,
                'password' => $request->password,
            ]);

            DB::commit();

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message'             => 'Registrasi berhasil',
                'access_token'        => $token,
                'token_type'          => 'Bearer',
                'is_profile_complete' => $user->isProfileComplete(),
                'user'                => $user,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal melakukan registrasi',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * LOGIN PENGGUNA & RELAWAN (Email & Password)
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $account = Account::where('email', $request->email)
            ->where('provider', 'local')
            ->first();

        if (!$account || !Hash::check($request->password, $account->password)) {
            return response()->json([
                'message' => 'Email atau password salah.'
            ], 401);
        }

        $user = $account->user;

        if (!$user || !in_array($user->role, ['pengguna', 'relawan'])) {
            return response()->json([
                'message' => 'Akses ditolak. Endpoint login ini khusus untuk Pengguna dan Relawan.'
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'             => 'Login berhasil',
            'access_token'        => $token,
            'token_type'          => 'Bearer',
            'is_profile_complete' => $user->isProfileComplete(),
            'user'                => $user,
        ]);
    }

    /**
     * BERANDA PENGGUNA & RELAWAN
     */
    public function beranda(Request $request)
    {
        $user = $request->user();

        $activeSosCount = \App\Models\SOS::where('status_sos', 'aktif')->count();
        $totalLaporanCount = \App\Models\Laporan::count();

        return response()->json([
            'message'             => 'Selamat datang di Beranda Sahabat SOS',
            'user'                => $user,
            'is_profile_complete' => $user->isProfileComplete(),
            'summary'             => [
                'active_sos'    => $activeSosCount,
                'total_laporan' => $totalLaporanCount,
            ]
        ]);
    }

    /**
     * BERANDA ADMIN & SUPERADMIN
     */
    public function berandaAdmin(Request $request)
    {
        $user = $request->user();

        $totalUsers = User::where('role', 'pengguna')->count();
        $totalRelawan = User::where('role', 'relawan')->count();
        $totalAdmins = User::whereIn('role', ['admin', 'superadmin'])->count();
        $activeSosCount = \App\Models\SOS::where('status_sos', 'aktif')->count();
        $totalLaporanCount = \App\Models\Laporan::count();

        return response()->json([
            'message' => 'Selamat datang di Beranda Admin Sahabat SOS',
            'user'    => $user,
            'stats'   => [
                'total_pengguna' => $totalUsers,
                'total_relawan'  => $totalRelawan,
                'total_admin'    => $totalAdmins,
                'active_sos'     => $activeSosCount,
                'total_laporan'  => $totalLaporanCount,
            ]
        ]);
    }

    /**
     * 7. LOGOUT
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logout berhasil']);
    }
}