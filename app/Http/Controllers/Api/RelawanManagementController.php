<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Account;
use App\Events\RelawanStatusUpdated;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RelawanManagementController extends Controller
{
    /**
     * Mendaftarkan relawan baru oleh Admin (Pre-Registration via Google SSO)
     * Tidak membutuhkan password karena relawan akan login via Google di mobile
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|max:255',
            'no_telp'   => 'required|string|max:20|unique:users,no_telp',
            'alamat'    => 'required|string|max:255',
            'pekerjaan' => 'nullable|string|max:255',
        ], [
            'no_telp.unique' => 'Nomor HP ini sudah terdaftar di sistem. Gunakan nomor HP lain.',
        ]);

        $email = strtolower(trim($validated['email']));

        // Cek apakah email sudah terdaftar di tabel accounts
        if (Account::where('email', $email)->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Email ini sudah terdaftar di sistem. Silakan gunakan email lain.'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $user = User::create([
                'name'                => $validated['name'],
                'role'                => 'relawan',
                'no_telp'             => $validated['no_telp'],
                'alamat'              => $validated['alamat'],
                'pekerjaan'           => $validated['pekerjaan'] ?? null,
                'status_verifikasi'   => 'terverifikasi',
                'is_active'           => true,
                'persetujuan_privasi' => true,
                'waktu_persetujuan'   => now(),
            ]);

            Account::create([
                'user_id'     => $user->id,
                'provider'    => 'google',
                'provider_id' => null, // Akan diisi otomatis saat relawan pertama kali login via Google di mobile
                'email'       => $email,
                'password'    => null, // Login murni via Google SSO
            ]);

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Relawan berhasil didaftarkan. Relawan dapat langsung login di aplikasi mobile menggunakan Masuk dengan Akun Google.',
                'data'    => [
                    'id'                => $user->id,
                    'name'              => $user->name,
                    'email'             => $email,
                    'role'              => $user->role,
                    'status_verifikasi' => $user->status_verifikasi,
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal mendaftarkan relawan: ' . $e->getMessage()
            ], 500);
        }
    }
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 50);
        $query = User::with('accounts')->where('role', 'relawan');

        if ($request->filled('status_verifikasi')) {
            if ($request->status_verifikasi !== 'all') {
                $query->where('status_verifikasi', $request->status_verifikasi);
            }
        } else {
            // Default untuk daftar relawan: hanya relawan yang sudah terverifikasi
            $query->where('status_verifikasi', 'terverifikasi');
        }

        $columns = [
            'id', 'name', 'alamat', 'no_telp', 'foto_profile',
            'is_active', 'status_verifikasi', 'pekerjaan', 'alasan_relawan',
            'created_at'
        ];

        if (Schema::hasColumn('users', 'dokumen_kyc')) {
            $columns[] = 'dokumen_kyc';
        }
        if (Schema::hasColumn('users', 'alasan_penolakan')) {
            $columns[] = 'alasan_penolakan';
        }

        $relawan = $query->select($columns)
                ->latest()
                ->paginate($perPage);

        $relawan->getCollection()->transform(function ($item) {
            $item->email = $item->accounts->first()?->email ?? '-';
            if (!isset($item->dokumen_kyc)) {
                $item->dokumen_kyc = null;
            }
            if (!isset($item->alasan_penolakan)) {
                $item->alasan_penolakan = null;
            }
            return $item;
        });

        return response()->json([
            'status' => 'success',
            'data' => $relawan
        ], 200);
    }

    public function updateStatus(Request $request, $id)
    {
        // Validasi input Wajib boolean (true / false)
        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $relawan = User::where('role', 'relawan')->findOrFail($id);
        $isActive = filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN);

        $relawan->update([
            'is_active'           => $isActive,
            'status_ketersediaan' => $isActive ? 'tersedia' : 'nonaktif',
        ]);

        // Bersihkan cache lokasi realtime jika dinonaktifkan
        if (!$isActive) {
            Cache::forget("relawan:{$relawan->id}:location");
        }

        // Broadcast event real-time ke Reverb agar dashboard & peta terupdate otomatis
        try {
            broadcast(new RelawanStatusUpdated($relawan));
        } catch (\Throwable $e) {
            // Abaikan jika koneksi websocket/broadcast sedang bermasalah
        }

        $statusPesan = $isActive ? 'diaktifkan' : 'dinonaktifkan';

        return response()->json([
            'status' => 'success',
            'message' => "Akun relawan berhasil {$statusPesan}.",
            'data' => [
                'id' => $relawan->id,
                'name' => $relawan->name,
                'is_active' => (bool) $relawan->is_active
            ]
        ], 200);
    }
}
