<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SOS;
use App\Models\Laporan;
use App\Models\CatatanPenanganan;
use App\Models\KategoriLaporan;
use App\Models\User;
use App\Models\SOSActivity;
use App\Events\SOSUpdateStatus;
use Illuminate\Support\Facades\Validator;

class KasusAktifController extends Controller
{
    /**
     * Konversi status internal ke format readable:
     * - 'Belum Tertangani'
     * - 'Ditangani'
     * - 'Selesai'
     */
    public static function formatStatus(?string $status): string
    {
        $s = strtolower(trim($status ?? ''));
        return match ($s) {
            'aktif', 'pending', 'belum tertangani', 'belum_tertangani' => 'Belum Tertangani',
            'proses', 'ditangani', 'dalam penanganan' => 'Ditangani',
            'selesai' => 'Selesai',
            'batal'   => 'Batal',
            default   => 'Belum Tertangani',
        };
    }

    /**
     * Konversi format readable kembali ke internal query value
     */
    public static function mapStatusToInternal(?string $status): ?array
    {
        if (!$status) return null;
        $s = strtolower(trim($status));
        return match ($s) {
            'belum tertangani', 'belum_tertangani', 'aktif' => ['aktif'],
            'ditangani', 'proses' => ['proses'],
            'selesai' => ['selesai'],
            'batal'   => ['batal'],
            default   => [$status],
        };
    }

    /**
     * Dapatkan Sub Judul / Jenis Laporan secara dinamis dari database (tanpa hardcode):
     * - Jika SOS: selalu 'Darurat'
     * - Jika Laporan: cari title di tabel kategori_laporans
     */
    public static function getJenisLaporan(string $tipe, ?string $kategoriLaporan): string
    {
        if (strtolower($tipe) === 'sos') {
            return 'Darurat';
        }

        if (empty($kategoriLaporan)) {
            return 'Lainnya';
        }

        // Ambil dinamis dari database KategoriLaporan (dikelola oleh Admin)
        $kategoriModel = KategoriLaporan::find($kategoriLaporan);
        if ($kategoriModel && !empty($kategoriModel->title)) {
            return $kategoriModel->title;
        }

        $byTitle = KategoriLaporan::where('title', $kategoriLaporan)->first();
        if ($byTitle && !empty($byTitle->title)) {
            return $byTitle->title;
        }

        // Fallback jika berupa slug bebas
        return ucwords(str_replace(['_', '-'], ' ', $kategoriLaporan));
    }

    /**
     * Transformasi seragam untuk item tabel dan kartu
     */
    public static function transformItem($item, string $tipe, bool $isDetail = false): array
    {
        $isSos = strtolower($tipe) === 'sos';
        $rawStatus = $isSos ? $item->status_sos : $item->status;
        $readableStatus = self::formatStatus($rawStatus);
        $jenisLaporan = self::getJenisLaporan($tipe, $isSos ? null : $item->kategori_laporan);

        $pelaporData = null;
        if ($item->pengguna) {
            $pelaporData = [
                'id'            => $item->pengguna->id,
                'name'          => $item->pengguna->name,
                'no_telp'       => $item->pengguna->no_telp,
                'foto_profile'  => $item->pengguna->foto_profile_url ?? ($item->pengguna->foto_profile ? url('storage/' . $item->pengguna->foto_profile) : null),
                'kategori_user' => $item->pengguna->kategori_user ?? 'umum',
            ];
            if ($isDetail && $item->pengguna->relationLoaded('kontakDarurat')) {
                $pelaporData['kontak_darurat'] = $item->pengguna->kontakDarurat;
            }
        }

        $relawanData = null;
        if ($item->relawan) {
            $relawanData = [
                'id'           => $item->relawan->id,
                'name'         => $item->relawan->name,
                'no_telp'      => $item->relawan->no_telp,
                'foto_profile' => $item->relawan->foto_profile_url ?? ($item->relawan->foto_profile ? url('storage/' . $item->relawan->foto_profile) : null),
            ];
        }

        $lokasiData = [
            'alamat'    => $isSos ? 'Lokasi Panggilan Darurat SOS' : ($item->lokasi_laporan ?? 'Lokasi Kejadian'),
            'latitude'  => (float) $item->latitude,
            'longitude' => (float) $item->longitude,
        ];

        $waktu = $isSos ? ($item->waktu_sos ?? $item->created_at) : ($item->waktu_laporan ?? $item->created_at);

        $result = [
            'id'            => $item->id,
            'status'        => $readableStatus,
            'status_raw'    => $rawStatus,
            'kategori'      => $isSos ? 'SOS' : 'Laporan',
            'jenis_laporan' => $jenisLaporan,
            'sub_judul'     => $jenisLaporan, // Alias sub_judul untuk fleksibilitas klien
            'id_pengguna'   => $item->id_pengguna,
            'pelapor'       => $pelaporData,
            'id_relawan'    => $item->id_relawan,
            'relawan'       => $relawanData,
            'lokasi'        => $lokasiData,
            'deskripsi'     => $isSos ? 'Panggilan Darurat SOS Warga' : ($item->deskripsi ?? ''),
            'waktu'         => $waktu ? $waktu->toDateTimeString() : null,
        ];

        if ($isDetail) {
            if (!$isSos) {
                $result['foto_laporan']     = $item->foto_laporan;
                $result['foto_laporan_url'] = $item->foto_laporan ? url('storage/' . $item->foto_laporan) : null;
                $result['rekam_suara']      = $item->rekam_suara;
                $result['rekam_suara_url']  = $item->rekam_suara ? url('storage/' . $item->rekam_suara) : null;
            } else {
                $result['battery_level']    = $item->battery_level;
                $result['signal_strength']  = $item->signal_strength;
                $result['device_info']      = $item->device_info;
            }

            if ($item->relationLoaded('catatanPenanganan')) {
                $result['catatan_penanganan'] = $item->catatanPenanganan;
            }
        }

        return $result;
    }

    /**
     * 1. GET SEMUA KASUS AKTIF (GET /api/tugas-aktif)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('kelola_laporan') && !$user->hasPermission('pantau_peta')) {
            return response()->json([
                'message' => "Akses ditolak. Anda belum memiliki hak akses 'kelola_laporan'. Silakan hubungi Superadmin."
            ], 403);
        }

        $tipe = $request->query('tipe'); // 'laporan' | 'sos' | null (keduanya)
        $statusFilter = self::mapStatusToInternal($request->query('status')) ?? ['aktif', 'proses'];
        $perPage = (int) $request->query('per_page', 10);
        $response = [];

        // 1. Ambil Data Laporan Aktif
        if (!$tipe || $tipe === 'laporan') {
            $laporanQuery = Laporan::with(['pengguna', 'relawan'])
                ->whereIn('status', $statusFilter)
                ->latest('waktu_laporan');

            $laporan = $laporanQuery->paginate($perPage, ['*'], 'page_laporan');
            $laporan->getCollection()->transform(function ($item) {
                return self::transformItem($item, 'laporan');
            });
            $response['laporan'] = $laporan;
        }

        // 2. Ambil Data SOS Aktif
        if (!$tipe || $tipe === 'sos') {
            $sosQuery = SOS::with(['pengguna', 'relawan'])
                ->whereIn('status_sos', $statusFilter)
                ->latest('waktu_sos');

            $sos = $sosQuery->paginate($perPage, ['*'], 'page_sos');
            $sos->getCollection()->transform(function ($item) {
                return self::transformItem($item, 'sos');
            });
            $response['sos'] = $sos;
        }

        return response()->json([
            'message' => 'Berhasil mengambil daftar tugas aktif',
            'data'    => $response,
        ]);
    }

    /**
     * 2. PEMISAHAN ENDPOINT: KHUSUS LAPORAN AKTIF (GET /api/tugas-aktif/laporan)
     */
    public function indexLaporan(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('kelola_laporan') && !$user->hasPermission('pantau_peta')) {
            return response()->json([
                'message' => "Akses ditolak. Anda belum memiliki hak akses 'kelola_laporan'."
            ], 403);
        }

        $statusFilter = self::mapStatusToInternal($request->query('status')) ?? ['aktif', 'proses'];
        $perPage = (int) $request->query('per_page', 10);

        $laporan = Laporan::with(['pengguna', 'relawan'])
            ->whereIn('status', $statusFilter)
            ->latest('waktu_laporan')
            ->paginate($perPage);

        $laporan->getCollection()->transform(function ($item) {
            return self::transformItem($item, 'laporan');
        });

        return response()->json([
            'message' => 'Berhasil mengambil daftar Laporan Aktif',
            'data'    => $laporan,
        ]);
    }

    /**
     * 3. PEMISAHAN ENDPOINT: KHUSUS SOS AKTIF (GET /api/tugas-aktif/sos)
     */
    public function indexSOS(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('kelola_laporan') && !$user->hasPermission('pantau_peta')) {
            return response()->json([
                'message' => "Akses ditolak. Anda belum memiliki hak akses 'kelola_laporan'."
            ], 403);
        }

        $statusFilter = self::mapStatusToInternal($request->query('status')) ?? ['aktif', 'proses'];
        $perPage = (int) $request->query('per_page', 10);

        $sos = SOS::with(['pengguna', 'relawan'])
            ->whereIn('status_sos', $statusFilter)
            ->latest('waktu_sos')
            ->paginate($perPage);

        $sos->getCollection()->transform(function ($item) {
            return self::transformItem($item, 'sos');
        });

        return response()->json([
            'message' => 'Berhasil mengambil daftar Kasus SOS Aktif',
            'data'    => $sos,
        ]);
    }

    /**
     * 4. DETAIL KASUS AKTIF (GET /api/tugas-aktif/{tipe}/{id})
     */
    public function show($tipe, $id, Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('kelola_laporan') && !$user->hasPermission('pantau_peta')) {
            return response()->json([
                'message' => "Akses ditolak. Anda belum memiliki hak akses 'kelola_laporan'."
            ], 403);
        }

        $data = null;
        $tipeLower = strtolower($tipe);

        if ($tipeLower === 'laporan') {
            $data = Laporan::with(['pengguna.kontakDarurat', 'relawan'])->find($id);
        } elseif ($tipeLower === 'sos') {
            $data = SOS::with(['pengguna.kontakDarurat', 'relawan'])->find($id);
        } else {
            return response()->json([
                'message' => "Tipe tidak valid. Gunakan 'laporan' atau 'sos'."
            ], 400);
        }

        if (!$data) {
            return response()->json(['message' => ucfirst($tipe) . ' tidak ditemukan'], 404);
        }

        return response()->json([
            'message' => "Detail $tipe berhasil diambil",
            'data'    => self::transformItem($data, $tipeLower, true),
        ]);
    }

    /**
     * 5. PENUGASAN RELAWAN KE KASUS / LAPORAN (POST /api/tugas-aktif/{tipe}/{id}/dispatch)
     */
    public function dispatchRelawan(Request $request, $tipe = null, $id = null)
    {
        $user = $request->user();
        if (!$user->hasPermission('kelola_laporan') && !$user->hasPermission('kelola_relawan')) {
            return response()->json([
                'message' => "Akses ditolak. Anda belum memiliki hak akses penugasan relawan."
            ], 403);
        }

        $tipe = strtolower($tipe ?? $request->input('tipe', ''));
        $id = $id ?? $request->input('kasus_id') ?? $request->input('id');
        $relawanId = $request->input('relawan_id');

        if (!$tipe || !$id || !$relawanId) {
            return response()->json([
                'message' => 'Parameter tipe (sos/laporan), kasus_id/id, dan relawan_id wajib diisi.'
            ], 422);
        }

        $relawan = User::where('role', 'relawan')->find($relawanId);
        if (!$relawan) {
            return response()->json(['message' => 'Relawan yang dipilih tidak ditemukan.'], 404);
        }

        if ($tipe === 'laporan') {
            $laporan = Laporan::with(['pengguna'])->find($id);
            if (!$laporan) {
                return response()->json(['message' => 'Laporan tidak ditemukan.'], 404);
            }

            if ($laporan->status === 'selesai') {
                return response()->json(['message' => 'Laporan ini sudah berstatus selesai.'], 422);
            }

            $laporan->update([
                'id_relawan' => $relawan->id,
                'status'     => 'proses',
            ]);

            return response()->json([
                'message' => "Relawan {$relawan->name} berhasil ditugaskan ke Laporan #{$laporan->id}",
                'data'    => self::transformItem($laporan->fresh(['pengguna', 'relawan']), 'laporan', true),
            ], 200);

        } elseif ($tipe === 'sos') {
            $sos = SOS::with(['pengguna'])->find($id);
            if (!$sos) {
                return response()->json(['message' => 'Kasus SOS tidak ditemukan.'], 404);
            }

            if ($sos->status_sos === 'selesai' || $sos->status_sos === 'batal') {
                return response()->json(['message' => 'Kasus SOS ini sudah tidak aktif.'], 422);
            }

            $sos->update([
                'id_relawan' => $relawan->id,
                'status_sos' => 'proses',
            ]);

            SOSActivity::record(
                $sos->id,
                'admin_dispatch',
                "Admin {$user->name} menugaskan relawan {$relawan->name} ke lokasi kasus SOS",
                $user->id,
                [
                    'relawan_id'   => $relawan->id,
                    'relawan_nama' => $relawan->name,
                ]
            );

            broadcast(new SOSUpdateStatus($sos))->toOthers();

            return response()->json([
                'message' => "Relawan {$relawan->name} berhasil ditugaskan ke Kasus SOS #{$sos->id}",
                'data'    => self::transformItem($sos->fresh(['pengguna', 'relawan']), 'sos', true),
            ], 200);
        }

        return response()->json(['message' => "Tipe tidak valid. Gunakan 'laporan' atau 'sos'."], 400);
    }

    /**
     * 6. SELESAIKAN KASUS (POST /api/tugas-aktif/{tipe}/{id}/selesai)
     */
    public function tanganiKasus(Request $request, $tipe, $id)
    {
        $user = $request->user();
        if (!$user->hasPermission('kelola_laporan')) {
            return response()->json([
                'message' => "Akses ditolak. Anda belum memiliki hak akses 'kelola_laporan'."
            ], 403);
        }

        $request->validate([
            'catatan' => 'required|string',
        ]);

        $tipeLower = strtolower($tipe);

        if ($tipeLower === 'laporan') {
            $laporan = Laporan::find($id);
            if (!$laporan) {
                return response()->json(['message' => 'Laporan tidak ditemukan'], 404);
            }

            $laporan->update(['status' => 'selesai']);

            $catatan = CatatanPenanganan::create([
                'id_admin'   => $user->id,
                'id_laporan' => $laporan->id,
                'catatan'    => $request->catatan,
            ]);

            return response()->json([
                'message' => 'Laporan berhasil diselesaikan',
                'data'    => [
                    'kasus'   => self::transformItem($laporan->fresh(['pengguna', 'relawan']), 'laporan', true),
                    'catatan' => $catatan,
                ]
            ]);

        } elseif ($tipeLower === 'sos') {
            $sos = SOS::find($id);
            if (!$sos) {
                return response()->json(['message' => 'SOS tidak ditemukan'], 404);
            }

            $sos->update(['status_sos' => 'selesai']);

            $catatan = CatatanPenanganan::create([
                'id_admin' => $user->id,
                'id_sos'   => $sos->id,
                'catatan'  => $request->catatan,
            ]);

            SOSActivity::record(
                $sos->id,
                'sos_selesai',
                "Kasus SOS #{$sos->id} ditandai selesai oleh Admin {$user->name}",
                $user->id
            );

            broadcast(new SOSUpdateStatus($sos))->toOthers();

            return response()->json([
                'message' => 'SOS berhasil diselesaikan',
                'data'    => [
                    'kasus'   => self::transformItem($sos->fresh(['pengguna', 'relawan']), 'sos', true),
                    'catatan' => $catatan,
                ]
            ]);
        }

        return response()->json(['message' => "Tipe tidak valid. Gunakan 'laporan' atau 'sos'."], 400);
    }

    /**
     * 7. RIWAYAT KASUS SELESAI ADMIN (GET /api/admin/riwayat-kasus)
     */
    public function riwayatAdmin(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('kelola_laporan') && !$user->hasPermission('pantau_peta')) {
            return response()->json([
                'message' => "Akses ditolak. Anda belum memiliki hak akses 'kelola_laporan'."
            ], 403);
        }

        $tipe = $request->query('tipe'); // 'laporan' | 'sos' | null
        $perPage = (int) $request->query('per_page', 10);
        $response = [];

        if (!$tipe || $tipe === 'laporan') {
            $laporan = Laporan::with(['pengguna', 'relawan'])
                ->where('status', 'selesai')
                ->latest('updated_at')
                ->paginate($perPage, ['*'], 'page_laporan');

            $laporan->getCollection()->transform(function ($item) {
                return self::transformItem($item, 'laporan');
            });
            $response['laporan'] = $laporan;
        }

        if (!$tipe || $tipe === 'sos') {
            $sos = SOS::with(['pengguna', 'relawan'])
                ->whereIn('status_sos', ['selesai', 'batal'])
                ->latest('updated_at')
                ->paginate($perPage, ['*'], 'page_sos');

            $sos->getCollection()->transform(function ($item) {
                return self::transformItem($item, 'sos');
            });
            $response['sos'] = $sos;
        }

        return response()->json([
            'message' => 'Berhasil mengambil riwayat kasus selesai',
            'data'    => $response,
        ]);
    }
}

