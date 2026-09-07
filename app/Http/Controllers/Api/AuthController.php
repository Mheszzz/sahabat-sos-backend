<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            } else {
                // 2. Jika belum ada di accounts, cek apakah email sudah ada di tabel users
                $user = User::where('email', $googleUser->getEmail())->first();

                if (!$user) {
                    // 3. Buat User baru jika belum terdaftar
                    $user = User::create([
                        'name'         => $googleUser->getName(),
                        'email'        => $googleUser->getEmail(),
                        'foto_profile' => $googleUser->getAvatar(),
                        'role'         => $requestedRole,
                    ]);
                } else {
                    if (!in_array($user->role, ['pengguna', 'relawan'])) {
                        return response()->json([
                            'message' => 'Akses ditolak. Email ini terdaftar sebagai Admin.'
                        ], 403);
                    }
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

            // Generate Token Sanctum
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message'      => 'Login Google berhasil',
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'user'         => $user,
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
     * 2. URL REDIRECT GOOGLE (Khusus Pengujian via Web Browser)
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
     * 3. CALLBACK GOOGLE (Khusus Pengujian via Web Browser)
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
            } else {
                $user = User::where('email', $googleUser->getEmail())->first();

                if (!$user) {
                    $user = User::create([
                        'name'         => $googleUser->getName(),
                        'email'        => $googleUser->getEmail(),
                        'foto_profile' => $googleUser->getAvatar(),
                        'role'         => $requestedRole,
                    ]);
                } else {
                    if (!in_array($user->role, ['pengguna', 'relawan'])) {
                        return response()->json([
                            'message' => 'Akses ditolak. Email ini terdaftar sebagai Admin.'
                        ], 403);
                    }
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

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message'      => 'Login Google berhasil',
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'user'         => $user,
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
     * 4. LOGOUT
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logout berhasil']);
    }
}