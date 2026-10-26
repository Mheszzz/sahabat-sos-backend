<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /**
     * 1. LOGIN GOOGLE UNTUK MOBILE / POSTMAN (Kirim Token via API JSON)
     */
    public function loginGoogleMobile(Request $request)
    {
        $request->validate([
            'id_token' => 'nullable|string',
            'token'    => 'nullable|string',
            'role'     => 'nullable|in:pengguna,relawan',
        ]);

        $token = $request->input('id_token') ?? $request->input('token');

        if (!$token) {
            return response()->json([
                'message' => 'Token Google wajib dikirimkan (gunakan parameter id_token atau token).'
            ], 422);
        }

        $requestedRole = $request->input('role', 'pengguna');

        $googleId = null;
        $googleEmail = null;
        $googleName = null;
        $googleAvatar = null;

        // 1. Coba verifikasi via Google OAuth2 tokeninfo (cocok untuk id_token JWT dari Flutter Google Sign-In)
        try {
            $googleResponse = Http::get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $token,
            ]);

            if ($googleResponse->successful() && isset($googleResponse->json()['sub'])) {
                $data = $googleResponse->json();
                $googleId = $data['sub'];
                $googleEmail = $data['email'] ?? null;
                $googleName = $data['name'] ?? null;
                $googleAvatar = $data['picture'] ?? null;
            }
        } catch (\Exception $e) {
            // Lanjut ke fallback jika ada exception koneksi
        }

        // 2. Fallback jika bukan id_token, coba verifikasi sebagai access_token via Laravel Socialite
        if (!$googleId) {
            try {
                $socialiteUser = Socialite::driver('google')->userFromToken($token);
                $googleId = $socialiteUser->getId();
                $googleEmail = $socialiteUser->getEmail();
                $googleName = $socialiteUser->getName();
                $googleAvatar = $socialiteUser->getAvatar();
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Token Google tidak valid atau sudah expired',
                    'error'   => $e->getMessage()
                ], 401);
            }
        }

        if (!$googleEmail) {
            return response()->json([
                'message' => 'Email dari akun Google tidak ditemukan atau tidak diizinkan.'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // 1. Cek apakah akun Google ini sudah ada di tabel accounts (berdasarkan Google ID)
            $account = Account::where('provider', 'google')
                ->where('provider_id', $googleId)
                ->first();

            if ($account) {
                $user = $account->user;

                // Proteksi: Tolak jika role bukan pengguna atau relawan (misal Admin/Superadmin)
                if (!in_array($user->role, ['pengguna', 'relawan'])) {
                    return response()->json([
                        'message' => 'Akses ditolak. Jalur login ini hanya untuk Pengguna atau Relawan'
                    ], 403);
                }
            } else {
                // 2. Jika belum ada di accounts untuk Google ID ini, cek apakah email sudah ada di tabel accounts (misal didaftarkan oleh Admin)
                $existingAccount = Account::where('email', $googleEmail)->first();

                if ($existingAccount) {
                    $user = $existingAccount->user;

                    if (!in_array($user->role, ['pengguna', 'relawan'])) {
                        return response()->json([
                            'message' => 'Akses ditolak. Email ini terdaftar sebagai Admin/Superadmin.'
                        ], 403);
                    }

                    // Tautkan Google ID ke akun yang sudah ada
                    if ($existingAccount->provider === 'google') {
                        $existingAccount->update(['provider_id' => $googleId]);
                    } else {
                        Account::updateOrCreate(
                            ['provider' => 'google', 'email' => $googleEmail],
                            ['user_id' => $user->id, 'provider_id' => $googleId, 'password' => null]
                        );
                    }
                } else {
                    // 3. Buat User baru jika belum terdaftar sama sekali
                    $user = User::create([
                        'name'                => $googleName ?? 'User Google',
                        'foto_profile'        => $googleAvatar,
                        'role'                => $requestedRole,
                        'status_verifikasi'   => $requestedRole === 'relawan' ? 'pending' : 'terverifikasi',
                        'status_ketersediaan' => $requestedRole === 'relawan' ? 'off' : null,
                        'is_active'           => true,
                    ]);

                    // 4. Tautkan Akun Google ke tabel accounts
                    Account::create([
                        'user_id'     => $user->id,
                        'provider'    => 'google',
                        'provider_id' => $googleId,
                        'email'       => $googleEmail,
                        'password'    => null,
                    ]);
                }
            }

            // Update foto profil jika belum ada
            if (empty($user->foto_profile) && !empty($googleAvatar)) {
                $user->update(['foto_profile' => $googleAvatar]);
            }

            // Cek apakah akun dinonaktifkan (relawan tetap diizinkan login agar dapat membuka profil dan melihat keterangan dinonaktifkan)
            $isActive = $user->is_active ?? true;
            if (!$isActive && $user->role !== 'relawan') {
                return response()->json([
                    'message' => 'Akun Anda telah dinonaktifkan oleh administrator.'
                ], 403);
            }

            DB::commit();

            // Cek apakah profil pengguna sudah lengkap
            $isProfileComplete = $user->isProfileComplete();

            // Generate Token Sanctum
            $token = $user->createToken('auth_token')->plainTextToken;

            // Lampirkan email untuk response frontend
            $user->email = $googleEmail;

            return response()->json([
                'message'             => $isProfileComplete ? 'Login Google berhasil' : 'Login Google berhasil, silakan lengkapi profil Anda.',
                'access_token'        => $token,
                'token_type'          => 'Bearer',
                'is_profile_complete' => $isProfileComplete,
                'status_verifikasi'   => $user->status_verifikasi,
                'is_active'           => (bool) $user->is_active,
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

        if (!in_array($user->role, ['pengguna', 'relawan'])) { #query dari table master
            return response()->json([
                'message' => 'Layanan ini hanya untuk Pengguna.'
            ], 403);
        }

        $validated = $request->validate([
            'alamat'              => 'required|string|max:255',
            'no_telp'             => 'required|string|max:20|unique:users,no_telp,' . $user->id,
            'role'                => 'nullable|in:pengguna,relawan',
            'pekerjaan'           => 'nullable|string|max:255',
            'alasan_relawan'      => 'nullable|string|max:1000',
            'kategori_user'       => 'nullable|in:umum,tunarungu,tunanetra,tunawicara', 
            'catatan_medis'       => 'nullable|string',
            'getaran'             => 'nullable|boolean',
            'talkback'            => 'nullable|boolean',
            'panduan_suara'       => 'nullable|boolean',
            'text_besar'          => 'nullable|boolean',
            'status_ketersediaan' => 'nullable|string',
            'device_id'           => 'nullable|string',
            'lokasi_user'         => 'nullable|string',
            'dokumen_kyc'         => 'nullable',
        ]);

        if ($request->hasFile('dokumen_kyc')) {
            $path = $request->file('dokumen_kyc')->store('kyc_documents', 'public');
            $validated['dokumen_kyc'] = $path;
        } elseif ($request->filled('dokumen_kyc') && is_string($request->dokumen_kyc)) {
            $validated['dokumen_kyc'] = $request->dokumen_kyc;
        }

        $targetRole = $validated['role'] ?? $user->role;
        if ($targetRole === 'relawan' && ($user->role !== 'relawan' || $user->status_verifikasi !== 'terverifikasi')) {
            $validated['status_verifikasi'] = 'pending';
            if (isset($validated['status_ketersediaan']) && $validated['status_ketersediaan'] === 'tersedia') {
                $validated['status_ketersediaan'] = 'off';
            }
        }

        $user->update($validated);

        return response()->json([
            'message'             => 'Profil berhasil diperbarui.',
            'is_profile_complete' => $user->isProfileComplete(),
            'status_verifikasi'   => $user->status_verifikasi,
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

        // Normalisasi email lalu cari kredensial lokal di tabel accounts
        $email = strtolower(trim($request->email));

        $account = Account::where('email', $email)
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

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Akun administrator Anda telah dinonaktifkan. Silakan hubungi Superadmin.'
            ], 403);
        }

        // Generate Sanctum Token
        $token = $user->createToken('admin_auth_token')->plainTextToken;

        // Lampirkan email untuk response frontend & session
        $user->email = $account->email;

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
                        'name'                => $googleUser->getName(),
                        'foto_profile'        => $googleUser->getAvatar(),
                        'role'                => $requestedRole,
                        'status_verifikasi'   => $requestedRole === 'relawan' ? 'pending' : 'terverifikasi',
                        'status_ketersediaan' => $requestedRole === 'relawan' ? 'off' : null,
                        'is_active'           => true,
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
        $isRelawan = $request->role === 'relawan';

        $request->validate([
            'name'                => 'required|string|max:255',
            'email'               => 'required|email',
            'password'            => 'required|string|min:6',
            'role'                => 'required|in:pengguna,relawan',
            'persetujuan_privasi' => 'required|accepted',
            'no_telp'             => ($isRelawan ? 'required' : 'nullable') . '|string|max:20|unique:users,no_telp',
            'alamat'              => ($isRelawan ? 'required' : 'nullable') . '|string|max:255',
            'pekerjaan'           => ($isRelawan ? 'required' : 'nullable') . '|string|max:255',
            'alasan_relawan'      => ($isRelawan ? 'required' : 'nullable') . '|string|max:1000',
            'dokumen_kyc'         => 'nullable',
        ], [
            'persetujuan_privasi.required' => 'Anda harus menyetujui syarat & ketentuan penggunaan data pribadi dan medis untuk mendaftar.',
            'persetujuan_privasi.accepted' => 'Anda harus menyetujui syarat & ketentuan penggunaan data pribadi dan medis untuk mendaftar.',
            'alamat.required'              => 'Alamat wajib diisi untuk pendaftaran relawan.',
            'no_telp.required'             => 'Nomor HP wajib diisi untuk pendaftaran relawan.',
            'pekerjaan.required'           => 'Pekerjaan wajib diisi untuk pendaftaran relawan.',
            'alasan_relawan.required'      => 'Alasan menjadi relawan wajib diisi untuk pendaftaran relawan.',
        ]);

        $email = strtolower(trim($request->email));

        $existingAccount = Account::where('email', $email)->first();
        if ($existingAccount) {
            return response()->json([
                'message' => 'Email sudah terdaftar. Silakan gunakan email lain atau login.'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $dokumenKyc = null;
            if ($request->hasFile('dokumen_kyc')) {
                $dokumenKyc = $request->file('dokumen_kyc')->store('kyc_documents', 'public');
            } elseif ($request->filled('dokumen_kyc') && is_string($request->dokumen_kyc)) {
                $dokumenKyc = $request->dokumen_kyc;
            }

            $user = User::create([
                'name'                => $request->name,
                'role'                => $request->role,
                'no_telp'             => $request->no_telp ?? null,
                'alamat'              => $request->alamat ?? null,
                'pekerjaan'           => $request->pekerjaan ?? null,
                'alasan_relawan'      => $request->alasan_relawan ?? null,
                'dokumen_kyc'         => $dokumenKyc,
                'status_verifikasi'   => $request->role === 'relawan' ? 'pending' : 'terverifikasi',
                'status_ketersediaan' => $request->role === 'relawan' ? 'off' : null,
                'persetujuan_privasi' => true,
                'waktu_persetujuan'   => now(),
            ]);

            Account::create([
                'user_id'  => $user->id,
                'provider' => 'local',
                'email'    => $email,
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
     * REGISTRASI PENGGUNA (POST /api/auth/register/pengguna)
     */
    public function registerPengguna(Request $request)
    {
        $request->merge(['role' => 'pengguna']);
        return $this->register($request);
    }

    /**
     * REGISTRASI RELAWAN (POST /api/auth/register/relawan)
     */
    public function registerRelawan(Request $request)
    {
        $request->merge(['role' => 'relawan']);
        return $this->register($request);
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

        $email = strtolower(trim($request->email));

        $account = Account::where('email', $email)
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

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Akun Anda telah dinonaktifkan oleh administrator.'
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $user->email = $email;

        return response()->json([
            'message'             => 'Login berhasil',
            'access_token'        => $token,
            'token_type'          => 'Bearer',
            'is_profile_complete' => $user->isProfileComplete(),
            'status_verifikasi'   => $user->status_verifikasi,
            'user'                => $user,
        ]);
    }

    /**
     * BERANDA PENGGUNA & RELAWAN
     */
    public function beranda(Request $request)
    {
        $user = $request->user();

        $activeSosCount = \App\Models\SOS::whereIn('status_sos', ['belum ditangani', 'aktif'])->count();
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
        $activeSosCount = \App\Models\SOS::whereIn('status_sos', ['belum ditangani', 'aktif'])->count();
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
     * PEMBARUAN LOKASI GPS TERKINI (POST /api/user/update-location)
     */
    public function updateLocation(Request $request)
    {
        $validated = $request->validate([
            'latitude'            => 'required|numeric|between:-90,90',
            'longitude'           => 'required|numeric|between:-180,180',
            'lokasi_user'         => 'nullable|string|max:255',
            'status_ketersediaan' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        $updateData = [
            'latitude'        => $validated['latitude'],
            'longitude'       => $validated['longitude'],
            'lokasi_user'     => $validated['lokasi_user'] ?? $user->lokasi_user,
            'last_located_at' => now(),
        ];

        // Parameter 'status_ketersediaan' hanya diizinkan jika user role relawan dan sudah terverifikasi
        if (isset($validated['status_ketersediaan'])) {
            if ($user->role === 'relawan' && $user->status_verifikasi === 'terverifikasi') {
                $updateData['status_ketersediaan'] = $validated['status_ketersediaan'];
            }
        }

        $user->update($updateData);

        return response()->json([
            'message' => 'Lokasi GPS berhasil diperbarui',
            'user'    => $user->refresh(),
        ]);
    }

    /**
     * AMBIL DAFTAR RELAWAN PENDING VERIFIKASI (Khusus Admin & Superadmin)
     */
    public function getPendingRelawan(Request $request)
    {
        $relawans = User::with('accounts')
            ->where('role', 'relawan')
            ->where('status_verifikasi', 'pending')
            ->latest()
            ->get();

        $relawans->transform(function ($relawan) {
            $relawan->email = $relawan->accounts->first()?->email ?? '-';
            return $relawan;
        });

        return response()->json([
            'message' => 'Berhasil mengambil daftar relawan pending verifikasi',
            'total'   => $relawans->count(),
            'data'    => $relawans,
        ]);
    }

    /**
     * VERIFIKASI / APPROVE AKUN RELAWAN (Khusus Admin & Superadmin)
     */
    public function verifikasiRelawan($id, Request $request)
    {
        $request->validate([
            'status_verifikasi' => 'required|in:terverifikasi,ditolak',
            'alasan_penolakan'  => 'nullable|string|max:1000',
        ]);

        $relawan = User::with('accounts')->where('role', 'relawan')->find($id);
        if (!$relawan) {
            return response()->json(['message' => 'Akun relawan tidak ditemukan'], 404);
        }

        $updateData = [
            'status_verifikasi' => $request->status_verifikasi,
        ];

        if ($request->status_verifikasi === 'terverifikasi') {
            $updateData['is_active'] = true;
            $updateData['alasan_penolakan'] = null;
        } elseif ($request->status_verifikasi === 'ditolak') {
            $updateData['is_active'] = false;
            $updateData['alasan_penolakan'] = $request->alasan_penolakan ?? 'Berkas pendaftaran atau kualifikasi relawan belum memenuhi persyaratan.';
        }

        $relawan->update($updateData);
        $relawan->email = $relawan->accounts->first()?->email ?? '-';

        return response()->json([
            'message' => "Status verifikasi relawan {$relawan->name} berhasil diubah menjadi {$request->status_verifikasi}",
            'user'    => $relawan,
        ]);
    }

    /**
     * 7. LOGOUT
     * Catatan: Jika Admin logout, seluruh hak aksesnya otomatis di-reset menjadi kosong ([]).
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user && $user->role === 'admin') {
            $user->update([
                'permissions'            => [],
                'permissions_granted_at' => null,
                'permissions_granted_by' => null,
            ]);
        }

        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil' . ($user && $user->role === 'admin' ? '. Hak akses Admin telah di-reset.' : '.')
        ]);
    }
}