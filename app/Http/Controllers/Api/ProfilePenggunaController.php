<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProfilePenggunaController extends Controller
{
    /**
     * 1. READ / TAMPILKAN PROFIL PENGGUNA (GET /api/pengguna/profile)
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $user->append('foto_profile_url');

        return response()->json([
            'message'             => 'Berhasil mengambil data profil pengguna',
            'is_profile_complete' => $user->isProfileComplete(),
            'data'                => [
                'id'                   => $user->id,
                'name'                 => $user->name,
                'email'                => $user->accounts()->where('provider', 'local')->value('email') ?? $user->accounts()->first()?->email,
                'no_telp'              => $user->no_telp,
                'alamat'               => $user->alamat,
                'role'                 => $user->role,
                'kategori_user'        => $user->kategori_user,
                'metode_komunikasi'    => $user->metode_komunikasi ?? 'chat',
                'foto_profile'         => $user->foto_profile,
                'foto_profile_url'     => $user->foto_profile_url,
                'aksesibilitas'        => [
                    'talkback'       => (bool) $user->talkback,
                    'text_besar'     => (bool) $user->text_besar,
                    'getaran'        => (bool) $user->getaran,
                    'kontras_tinggi' => (bool) $user->kontras_tinggi,
                    'panduan_suara'  => (bool) $user->panduan_suara,
                ],
                'catatan_medis'        => $user->catatan_medis,
                'status_verifikasi'    => $user->status_verifikasi,
            ]
        ]);
    }

    /**
     * 2. UPDATE PROFIL PENGGUNA (PUT / POST /api/pengguna/profile)
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name'                     => 'nullable|string|max:255',
            'nama'                     => 'nullable|string|max:255',
            'no_telp'                  => 'nullable|string|max:20|unique:users,no_telp,' . $user->id,
            'alamat'                   => 'nullable|string|max:255',
            'kategori_user'            => 'nullable|in:umum,tunanetra,tunarungu,tunawicara',
            'kategori'                 => 'nullable|in:umum,tunanetra,tunarungu,tunawicara',
            'metode_komunikasi'        => 'nullable|string|in:chat,pesan_suara_audio,keduanya',
            'metode_komunikasi_pilihan'=> 'nullable|string|in:chat,pesan_suara_audio,keduanya',
            'talkback'                 => 'nullable|boolean',
            'text_besar'               => 'nullable|boolean',
            'ukuran_teks'              => 'nullable|boolean',
            'getaran'                  => 'nullable|boolean',
            'kontras_tinggi'           => 'nullable|boolean',
            'mode_kontras_sangat_tinggi'=> 'nullable|boolean',
            'panduan_suara'            => 'nullable|boolean',
            'foto_profile'             => 'nullable|file|image|mimes:jpeg,png,jpg,webp|max:5120',
            'catatan_medis'            => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $dataToUpdate = [];

        // Nama
        if ($request->filled('name')) {
            $dataToUpdate['name'] = $request->name;
        } elseif ($request->filled('nama')) {
            $dataToUpdate['name'] = $request->nama;
        }

        // Telepon & Alamat
        if ($request->has('no_telp')) {
            $dataToUpdate['no_telp'] = $request->no_telp;
        }
        if ($request->has('alamat')) {
            $dataToUpdate['alamat'] = $request->alamat;
        }

        // Kategori User
        if ($request->filled('kategori_user')) {
            $dataToUpdate['kategori_user'] = $request->kategori_user;
        } elseif ($request->filled('kategori')) {
            $dataToUpdate['kategori_user'] = $request->kategori;
        }

        // Metode Komunikasi Pilihan (chat / pesan_suara_audio / keduanya)
        if ($request->filled('metode_komunikasi')) {
            $dataToUpdate['metode_komunikasi'] = $request->metode_komunikasi;
        } elseif ($request->filled('metode_komunikasi_pilihan')) {
            $dataToUpdate['metode_komunikasi'] = $request->metode_komunikasi_pilihan;
        }

        // Pengaturan Aksesibilitas
        if ($request->has('talkback')) {
            $dataToUpdate['talkback'] = filter_var($request->talkback, FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->has('text_besar')) {
            $dataToUpdate['text_besar'] = filter_var($request->text_besar, FILTER_VALIDATE_BOOLEAN);
        } elseif ($request->has('ukuran_teks')) {
            $dataToUpdate['text_besar'] = filter_var($request->ukuran_teks, FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->has('getaran')) {
            $dataToUpdate['getaran'] = filter_var($request->getaran, FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->has('kontras_tinggi')) {
            $dataToUpdate['kontras_tinggi'] = filter_var($request->kontras_tinggi, FILTER_VALIDATE_BOOLEAN);
        } elseif ($request->has('mode_kontras_sangat_tinggi')) {
            $dataToUpdate['kontras_tinggi'] = filter_var($request->mode_kontras_sangat_tinggi, FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->has('panduan_suara')) {
            $dataToUpdate['panduan_suara'] = filter_var($request->panduan_suara, FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->has('catatan_medis')) {
            $dataToUpdate['catatan_medis'] = $request->catatan_medis;
        }

        // Upload Foto Profile jika ada file dikirim
        if ($request->hasFile('foto_profile')) {
            if ($user->foto_profile && !str_starts_with($user->foto_profile, 'http')) {
                Storage::disk('public')->delete($user->foto_profile);
            }
            $path = $request->file('foto_profile')->store('profile/foto', 'public');
            $dataToUpdate['foto_profile'] = $path;
        }

        $user->update($dataToUpdate);
        $user->refresh()->append('foto_profile_url');

        return response()->json([
            'message'             => 'Profil berhasil diperbarui.',
            'is_profile_complete' => $user->isProfileComplete(),
            'user'                => $user,
        ]);
    }

    /**
     * 3. UPLOAD / UPDATE FOTO PROFILE (POST /api/pengguna/profile/foto)
     */
    public function uploadFoto(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'foto_profile' => 'required|file|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi file foto gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        if ($user->foto_profile && !str_starts_with($user->foto_profile, 'http')) {
            Storage::disk('public')->delete($user->foto_profile);
        }

        $path = $request->file('foto_profile')->store('profile/foto', 'public');
        $user->update(['foto_profile' => $path]);
        $user->refresh()->append('foto_profile_url');

        return response()->json([
            'message'          => 'Foto profil berhasil diperbarui.',
            'foto_profile'     => $user->foto_profile,
            'foto_profile_url' => $user->foto_profile_url,
        ]);
    }

    /**
     * 4. HAPUS FOTO PROFILE (DELETE /api/pengguna/profile/foto)
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
