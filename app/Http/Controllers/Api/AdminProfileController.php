<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AdminProfileController extends Controller
{
    /**
     * 1. TAMPILKAN PROFIL ADMIN (GET /api/admin/profile)
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $user->append('foto_profile_url');

        $account = $user->accounts()->where('provider', 'local')->first();

        return response()->json([
            'message' => 'Berhasil mengambil profil Admin',
            'user'    => [
                'id'                     => $user->id,
                'name'                   => $user->name,
                'email'                  => $account?->email,
                'no_telp'                => $user->no_telp,
                'alamat'                 => $user->alamat,
                'role'                   => $user->role,
                'foto_profile'           => $user->foto_profile,
                'foto_profile_url'       => $user->foto_profile_url,
                'permissions'            => $user->permissions ?? [],
                'permissions_granted_at' => $user->permissions_granted_at,
                'is_active'              => (bool) $user->is_active,
            ]
        ]);
    }

    /**
     * 2. UPDATE DATA DIRI & FOTO PROFIL ADMIN (PUT / POST /api/admin/profile)
     * Catatan: Gunakan POST dengan FormData saat mengunggah file foto di frontend.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name'         => 'sometimes|required|string|max:255',
            'no_telp'      => 'nullable|string|max:20|unique:users,no_telp,' . $user->id,
            'alamat'       => 'nullable|string|max:255',
            'foto_profile' => 'nullable|file|image|mimes:jpeg,png,jpg,webp|max:5120',
        ], [
            'name.required'      => 'Nama tidak boleh kosong.',
            'no_telp.unique'     => 'Nomor telepon sudah digunakan oleh akun lain.',
            'foto_profile.image' => 'File foto profil harus berupa gambar.',
            'foto_profile.max'   => 'Ukuran foto profil maksimal 5MB.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $dataToUpdate = [];

        if ($request->has('name')) {
            $dataToUpdate['name'] = $request->name;
        }

        if ($request->has('no_telp')) {
            $dataToUpdate['no_telp'] = $request->no_telp;
        }

        if ($request->has('alamat')) {
            $dataToUpdate['alamat'] = $request->alamat;
        }

        // Upload Foto Profile baru jika ada file yang dikirim
        if ($request->hasFile('foto_profile')) {
            if ($user->foto_profile && !str_starts_with($user->foto_profile, 'http')) {
                Storage::disk('public')->delete($user->foto_profile);
            }
            $path = $request->file('foto_profile')->store('profile/foto', 'public');
            $dataToUpdate['foto_profile'] = $path;
        }

        if (!empty($dataToUpdate)) {
            $user->update($dataToUpdate);
        }

        $user->refresh()->append('foto_profile_url');
        $account = $user->accounts()->where('provider', 'local')->first();

        return response()->json([
            'message' => 'Profil Admin berhasil diperbarui.',
            'user'    => [
                'id'               => $user->id,
                'name'             => $user->name,
                'email'            => $account?->email,
                'no_telp'          => $user->no_telp,
                'alamat'           => $user->alamat,
                'role'             => $user->role,
                'foto_profile'     => $user->foto_profile,
                'foto_profile_url' => $user->foto_profile_url,
                'permissions'      => $user->permissions ?? [],
                'is_active'        => (bool) $user->is_active,
            ]
        ]);
    }

    /**
     * 3. UBAH KEAMANAN / KREDENSIAL: EMAIL DAN/ATAU PASSWORD (PUT /api/admin/profile/security)
     * Bisa ganti email saja, password saja, atau keduanya sekaligus dalam satu form/request.
     */
    public function updateSecurity(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password'      => 'required|string',
            'email'                 => 'nullable|email|max:255',
            'password'              => 'nullable|string|min:6|confirmed',
            'password_confirmation' => 'nullable|string',
        ], [
            'current_password.required' => 'Password saat ini wajib diisi untuk verifikasi keamanan.',
            'email.email'               => 'Format email tidak valid.',
            'password.min'              => 'Password baru minimal harus 6 karakter.',
            'password.confirmed'        => 'Konfirmasi password baru tidak cocok.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Minimal salah satu (email atau password) harus diisi
        if (!$request->filled('email') && !$request->filled('password')) {
            return response()->json([
                'message' => 'Harap isi email baru atau password baru yang ingin diubah.',
            ], 422);
        }

        $user = $request->user();
        $account = $user->accounts()->where('provider', 'local')->first();

        if (!$account) {
            return response()->json([
                'message' => 'Akun autentikasi lokal tidak ditemukan.',
            ], 404);
        }

        // 1. Verifikasi kecocokan password saat ini
        if (!Hash::check($request->current_password, $account->password)) {
            return response()->json([
                'message' => 'Password saat ini tidak sesuai.',
                'errors'  => [
                    'current_password' => ['Password saat ini tidak sesuai.']
                ]
            ], 422);
        }

        $updatedFields = [];

        // 2. Proses ganti Email jika diisi
        if ($request->filled('email')) {
            $newEmail = strtolower(trim($request->email));

            if ($account->email !== $newEmail) {
                // Cek apakah email baru sudah terdaftar di akun lain
                $isTaken = Account::where('provider', 'local')
                    ->where('email', $newEmail)
                    ->where('id', '!=', $account->id)
                    ->exists();

                if ($isTaken) {
                    return response()->json([
                        'message' => 'Email tersebut sudah digunakan oleh akun lain.',
                        'errors'  => [
                            'email' => ['Email tersebut sudah digunakan oleh akun lain.']
                        ]
                    ], 422);
                }

                $account->email = $newEmail;
                $updatedFields[] = 'email';
            }
        }

        // 3. Proses ganti Password jika diisi
        if ($request->filled('password')) {
            if (Hash::check($request->password, $account->password)) {
                return response()->json([
                    'message' => 'Password baru tidak boleh sama dengan password saat ini.',
                    'errors'  => [
                        'password' => ['Password baru tidak boleh sama dengan password saat ini.']
                    ]
                ], 422);
            }

            $account->password = $request->password;
            $updatedFields[] = 'password';
        }

        if (empty($updatedFields)) {
            return response()->json([
                'message' => 'Tidak ada perubahan data email atau password.',
            ], 200);
        }

        $account->save();

        $pesan = match (count($updatedFields)) {
            2 => 'Email dan password berhasil diperbarui.',
            1 => $updatedFields[0] === 'email' ? 'Email berhasil diperbarui.' : 'Password berhasil diperbarui.',
            default => 'Kredensial berhasil diperbarui.',
        };

        return response()->json([
            'message'        => $pesan,
            'updated_fields' => $updatedFields,
            'email'          => $account->email,
        ]);
    }


    /**
     * 5. HAPUS FOTO PROFIL ADMIN (DELETE /api/admin/profile/foto)
     */
    public function destroyFoto(Request $request)
    {
        $user = $request->user();

        if ($user->foto_profile) {
            if (!str_starts_with($user->foto_profile, 'http')) {
                Storage::disk('public')->delete($user->foto_profile);
            }
            $user->update(['foto_profile' => null]);
        }

        return response()->json([
            'message' => 'Foto profil berhasil dihapus.',
        ]);
    }
}
