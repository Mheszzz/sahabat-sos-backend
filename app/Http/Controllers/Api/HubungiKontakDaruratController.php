<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SOS;
use App\Models\Laporan;
use App\Models\Kontak_darurat;
use App\Models\SOSActivity;

class HubungiKontakDaruratController extends Controller
{
    /**
     * Format nomor telepon ke standar nomor WhatsApp internasional (62xxx)
     */
    public static function formatWhatsAppNumber(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        // Hapus semua karakter selain angka
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        if (empty($cleaned)) {
            return null;
        }

        // Tangani awalan: 6208... -> 628..., 08... -> 628..., 8... -> 628...
        if (str_starts_with($cleaned, '620')) {
            $cleaned = '62' . substr($cleaned, 3);
        } elseif (str_starts_with($cleaned, '0')) {
            $cleaned = '62' . substr($cleaned, 1);
        } elseif (str_starts_with($cleaned, '8')) {
            $cleaned = '62' . $cleaned;
        }

        return $cleaned;
    }

    /**
     * Susun template pesan darurat WhatsApp yang informatif dan resmi
     */
    public static function generateWhatsAppMessage($kasus, string $tipe, $pengguna, $kontak, ?string $customPesan = null): string
    {
        $isSos = strtolower($tipe) === 'sos';
        $jenisKasus = $isSos 
            ? 'Panggilan Darurat SOS' 
            : ('Laporan: ' . KasusAktifController::getJenisLaporan('laporan', $kasus->kategori_laporan));

        $waktu = $isSos ? ($kasus->waktu_sos ?? $kasus->created_at) : ($kasus->waktu_laporan ?? $kasus->created_at);
        if ($waktu) {
            $waktuCarbon = $waktu instanceof \Carbon\Carbon ? $waktu->copy() : \Carbon\Carbon::parse($waktu);
            $waktuStr = $waktuCarbon->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') . ' WIB';
        } else {
            $waktuStr = \Carbon\Carbon::now('Asia/Jakarta')->format('d/m/Y H:i') . ' WIB';
        }

        $lokasi = $isSos ? 'Lokasi Panggilan Darurat SOS Korban' : ($kasus->lokasi_laporan ?? 'Lokasi Kejadian');
        $lat = $kasus->latitude;
        $lng = $kasus->longitude;
        $gmapsUrl = ($lat && $lng) ? "https://www.google.com/maps?q={$lat},{$lng}" : null;

        $kategoriUser = $pengguna->kategori_user ? ucfirst($pengguna->kategori_user) : 'Umum';

        $lines = [];
        $lines[] = "🚨 *PEMBERITAHUAN DARURAT - SAHABAT SOS* 🚨";
        $lines[] = "";
        $statusTipe = ($kontak->tipe === 'utama') ? " *(Kontak Darurat Utama)*" : "";
        $lines[] = "Halo Bapak/Ibu *" . ($kontak->nama ?? 'Keluarga') . "*{$statusTipe},";
        $lines[] = "Kami dari Tim Pendamping Sahabat SOS menginformasikan bahwa keluarga/kerabat Anda:";
        $lines[] = "• *Nama*: " . ($pengguna->name ?? 'Warga');
        $lines[] = "• *No. Telp*: " . ($pengguna->no_telp ?? '-');
        $lines[] = "• *Kategori*: " . $kategoriUser;
        if (!empty($pengguna->catatan_medis) && $pengguna->catatan_medis !== '-') {
            $lines[] = "• *Catatan Medis*: " . $pengguna->catatan_medis;
        }
        $lines[] = "";
        $lines[] = "📌 *Informasi Kasus*:";
        $lines[] = "• *Status*: " . ($isSos ? 'DARURAT SOS' : 'Laporan Warga') . " (" . $jenisKasus . ")";
        $lines[] = "• *Waktu*: " . $waktuStr;
        $lines[] = "• *Lokasi*: " . $lokasi;
        if ($gmapsUrl) {
            $lines[] = "• *Titik Peta*: " . $gmapsUrl;
        }
        if (!$isSos && !empty($kasus->deskripsi)) {
            $lines[] = "• *Keterangan*: " . $kasus->deskripsi;
        }

        // Catatan khusus kontak darurat jika didaftarkan pengguna
        if (!empty($kontak->pesan)) {
            $lines[] = "";
            $lines[] = "📝 *Pesan dari Korban untuk Kontak Ini*:";
            $lines[] = "\"" . $kontak->pesan . "\"";
        }

        // Catatan kustom tambahan dari admin jika ada
        if (!empty($customPesan)) {
            $lines[] = "";
            $lines[] = "💬 *Catatan Petugas/Admin*:";
            $lines[] = $customPesan;
        }

        $lines[] = "";
        $lines[] = "Mohon dapat segera menghubungi korban atau memastikan keselamatannya. Tim kami sedang menindaklanjuti kasus ini.";
        $lines[] = "Terima kasih.";

        return implode("\n", $lines);
    }

    /**
     * HUBUNGI KONTAK DARURAT DARI KASUS (SOS / LAPORAN) KE WHATSAPP
     * GET  /api/tugas-aktif/{tipe}/{id}/kontak-darurat
     * POST /api/tugas-aktif/{tipe}/{id}/hubungi-kontak-darurat
     * GET  /api/tugas-aktif/{tipe}/{id}/hubungi-kontak-darurat
     */
    public function hubungi(Request $request, $tipe, $id)
    {
        $user = $request->user();
        if (!$user->hasPermission('kelola_laporan') && !$user->hasPermission('pantau_peta')) {
            return response()->json([
                'message' => "Akses ditolak. Anda belum memiliki hak akses 'kelola_laporan'."
            ], 403);
        }

        $tipeLower = strtolower($tipe);
        $kasus = null;

        if ($tipeLower === 'laporan') {
            $kasus = Laporan::with(['pengguna.kontakDarurat', 'relawan'])->find($id);
        } elseif ($tipeLower === 'sos') {
            $kasus = SOS::with(['pengguna.kontakDarurat', 'relawan'])->find($id);
        } else {
            return response()->json([
                'message' => "Tipe tidak valid. Gunakan 'laporan' atau 'sos'."
            ], 400);
        }

        if (!$kasus) {
            return response()->json(['message' => ucfirst($tipe) . ' tidak ditemukan'], 404);
        }

        $pengguna = $kasus->pengguna;
        if (!$pengguna) {
            return response()->json([
                'message' => 'Data pengguna/pelapor untuk kasus ini tidak ditemukan.'
            ], 404);
        }

        // Ambil daftar kontak darurat pengguna (urutkan tipe 'utama' di posisi paling atas)
        $kontakList = $pengguna->kontakDarurat()
            ->orderByRaw("CASE WHEN tipe = 'utama' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'desc')
            ->get();

        if ($kontakList->isEmpty()) {
            $userWa = self::formatWhatsAppNumber($pengguna->no_telp);
            return response()->json([
                'message' => 'Pengguna ini belum memiliki kontak darurat yang terdaftar.',
                'data'    => [
                    'kasus_id'      => (int) $kasus->id,
                    'tipe'          => $tipeLower,
                    'pelapor'       => [
                        'id'           => $pengguna->id,
                        'name'         => $pengguna->name,
                        'no_telp'      => $pengguna->no_telp,
                        'no_telp_wa'   => $userWa,
                        'whatsapp_url' => $userWa ? "https://wa.me/{$userWa}" : null,
                    ],
                    'target_kontak' => null,
                    'whatsapp_url'  => $userWa ? "https://wa.me/{$userWa}" : null,
                    'daftar_kontak' => [],
                ]
            ], 404);
        }

        // Cari kontak darurat yang bertipe 'utama' (sesuai spesifikasi di KontakDaruratController)
        $kontakUtama = $kontakList->firstWhere('tipe', 'utama');

        // Pesan kustom tambahan jika diberikan oleh admin
        $customPesan = $request->input('pesan') ?? $request->input('custom_pesan') ?? $request->input('catatan');

        // Tentukan target kontak:
        // Prioritas pertama: jika admin memilih kontak_id tertentu secara eksplisit
        // Prioritas utama/default: WAJIB memilih kontak dengan tipe 'utama' (atau fallback ke kontak pertama jika belum diset utama)
        $selectedKontakId = $request->input('kontak_id') ?? $request->input('kontak_darurat_id') ?? $request->query('kontak_id');
        $targetKontak = null;

        if ($selectedKontakId) {
            $targetKontak = $kontakList->firstWhere('id', (int) $selectedKontakId);
        }

        if (!$targetKontak) {
            $targetKontak = $kontakUtama ?? $kontakList->first();
        }

        // Transformasikan seluruh daftar kontak darurat lengkap dengan URL WhatsApp masing-masing
        $transformedKontakList = $kontakList->map(function ($k) use ($kasus, $tipeLower, $pengguna, $customPesan) {
            $waNumber = self::formatWhatsAppNumber($k->no_telp);
            $pesanKontak = self::generateWhatsAppMessage($kasus, $tipeLower, $pengguna, $k, $customPesan);
            $encodedText = rawurlencode($pesanKontak);

            return [
                'id'               => $k->id,
                'nama'             => $k->nama,
                'no_telp'          => $k->no_telp,
                'no_telp_wa'       => $waNumber,
                'tipe'             => $k->tipe ?? 'sekunder',
                'is_utama'         => ($k->tipe === 'utama'),
                'terima_notif'     => (bool) $k->terima_notif,
                'pesan_khusus'     => $k->pesan,
                'pesan_template'   => $pesanKontak,
                'whatsapp_url'     => $waNumber ? "https://wa.me/{$waNumber}?text={$encodedText}" : null,
                'whatsapp_web_url' => $waNumber ? "https://web.whatsapp.com/send?phone={$waNumber}&text={$encodedText}" : null,
            ];
        });

        // Susun data kontak target
        $targetWaNumber = self::formatWhatsAppNumber($targetKontak->no_telp);
        $targetPesan = self::generateWhatsAppMessage($kasus, $tipeLower, $pengguna, $targetKontak, $customPesan);
        $targetEncodedText = rawurlencode($targetPesan);
        $targetWhatsAppUrl = $targetWaNumber ? "https://wa.me/{$targetWaNumber}?text={$targetEncodedText}" : null;
        $targetWhatsAppWebUrl = $targetWaNumber ? "https://web.whatsapp.com/send?phone={$targetWaNumber}&text={$targetEncodedText}" : null;

        // Catat aktivitas log jika kasus SOS
        if ($tipeLower === 'sos' && ($request->isMethod('post') || $request->has('kontak_id') || $request->has('log_activity'))) {
            $labelAktivitas = $targetKontak->tipe === 'utama' ? 'Kontak Darurat Utama' : 'Kontak Darurat';
            SOSActivity::record(
                $kasus->id,
                'kontak_darurat_wa',
                "Admin {$user->name} menghubungi {$labelAktivitas} {$targetKontak->nama} ({$targetKontak->no_telp}) via WhatsApp",
                $user->id,
                [
                    'kontak_id'   => $targetKontak->id,
                    'nama_kontak' => $targetKontak->nama,
                    'no_telp'     => $targetKontak->no_telp,
                    'no_telp_wa'  => $targetWaNumber,
                    'tipe_kontak' => $targetKontak->tipe,
                    'is_utama'    => ($targetKontak->tipe === 'utama'),
                ]
            );
        }

        $labelKontak = ($targetKontak->tipe === 'utama') ? 'Kontak Darurat Utama' : 'Kontak Darurat';

        return response()->json([
            'message' => "Berhasil menyiapkan tautan WhatsApp {$labelKontak} ({$targetKontak->nama}) untuk {$tipeLower} #{$kasus->id}",
            'data'    => [
                'kasus_id'         => (int) $kasus->id,
                'tipe'             => $tipeLower,
                'status'           => KasusAktifController::formatStatus($tipeLower === 'sos' ? $kasus->status_sos : $kasus->status),
                'pelapor'          => [
                    'id'            => $pengguna->id,
                    'name'          => $pengguna->name,
                    'no_telp'       => $pengguna->no_telp,
                    'kategori_user' => $pengguna->kategori_user ?? 'umum',
                ],
                'target_kontak'    => [
                    'id'               => $targetKontak->id,
                    'nama'             => $targetKontak->nama,
                    'no_telp'          => $targetKontak->no_telp,
                    'no_telp_wa'       => $targetWaNumber,
                    'tipe'             => $targetKontak->tipe ?? 'sekunder',
                    'is_utama'         => ($targetKontak->tipe === 'utama'),
                    'pesan_khusus'     => $targetKontak->pesan,
                    'pesan_wa'         => $targetPesan,
                    'whatsapp_url'     => $targetWhatsAppUrl,
                    'whatsapp_web_url' => $targetWhatsAppWebUrl,
                ],
                'pesan_wa'         => $targetPesan,
                'whatsapp_url'     => $targetWhatsAppUrl,
                'whatsapp_web_url' => $targetWhatsAppWebUrl,
                'daftar_kontak'    => $transformedKontakList,
            ]
        ], 200);
    }

    /**
     * Alias method untuk fleksibilitas
     */
    public function hubungiKontakDarurat(Request $request, $tipe, $id)
    {
        return $this->hubungi($request, $tipe, $id);
    }
}
