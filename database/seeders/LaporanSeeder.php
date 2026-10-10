<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Laporan;
use App\Models\CatatanPenanganan;
use Carbon\Carbon;

class LaporanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Pastikan User & Relawan sudah ter-seed
        $this->call(UserSeeder::class);

        $penggunas = User::where('role', 'pengguna')->get();
        $relawans  = User::where('role', 'relawan')->get();
        $admin     = User::whereIn('role', ['admin', 'superadmin'])->first();

        if ($penggunas->isEmpty()) {
            return;
        }

        $budi   = $penggunas->where('kategori_user', 'tunanetra')->first() ?? $penggunas->first();
        $rahma  = $penggunas->where('kategori_user', 'tunarungu')->first() ?? $penggunas->first();
        $dewi   = $penggunas->where('kategori_user', 'tunawicara')->first() ?? $penggunas->first();
        $fauzi  = $penggunas->where('kategori_user', 'umum')->first() ?? $penggunas->first();

        $rian   = $relawans->first();
        $annisa = $relawans->skip(1)->first() ?? $relawans->first();
        $dimas  = $relawans->skip(2)->first() ?? $relawans->first();

        // ---------------------------------------------------------------------
        // 1. LAPORAN DENGAN STATUS: 'aktif' (Menunggu Relawan / Kasus Baru)
        // ---------------------------------------------------------------------
        $laporanAktif = [
            [
                'id_pengguna'      => $budi->id,
                'id_relawan'       => null,
                'lokasi_laporan'   => 'Area Seturan, Depok, Sleman - Depan Ruko Seturan Square',
                'latitude'         => -7.765432,
                'longitude'        => 110.408765,
                'kategori_laporan' => 'kondisi_medis',
                'deskripsi'        => 'Pengguna tunanetra terpeleset di trotoar licin tanpa guiding block, mengalami luka lecet dan dislokasi pergelangan tangan, butuh pertolongan pertama.',
                'status'           => 'belum ditangani',
                'waktu_laporan'    => Carbon::now()->subMinutes(10),
            ],
            [
                'id_pengguna'      => $rahma->id,
                'id_relawan'       => null,
                'lokasi_laporan'   => 'Area Babaran, Umbulharjo - Simpang Empat Babaran',
                'latitude'         => -7.810500,
                'longitude'        => 110.381200,
                'kategori_laporan' => 'butuh_pendamping',
                'deskripsi'        => 'Pengguna tunarungu membutuhkan pendamping relawan untuk menyeberang persimpangan lalu lintas padat menuju puskesmas.',
                'status'           => 'belum ditangani',
                'waktu_laporan'    => Carbon::now()->subMinutes(25),
            ],
            [
                'id_pengguna'      => $fauzi->id,
                'id_relawan'       => null,
                'lokasi_laporan'   => 'Kawasan Malioboro - Depan Pintu Masuk Stasiun Tugu',
                'latitude'         => -7.789500,
                'longitude'        => 110.364500,
                'kategori_laporan' => 'aksesibilitas_rusak',
                'deskripsi'        => 'Jalur pemandu disabilitas netra (tactile paving) tertutup tumpukan kayu renovasi toko dan menghalangi pejalan kaki disabilitas.',
                'status'           => 'belum ditangani',
                'waktu_laporan'    => Carbon::now()->subHours(1),
            ],
            [
                'id_pengguna'      => $dewi->id,
                'id_relawan'       => null,
                'lokasi_laporan'   => 'Jl. Kaliurang KM 5.5, Depok, Sleman - Halte Trans Jogja',
                'latitude'         => -7.768900,
                'longitude'        => 110.379800,
                'kategori_laporan' => 'tersesat',
                'deskripsi'        => 'Tersesat saat mencari jalur bus menuju kampus, aplikasi navigasi tidak responsif. Butuh panduan arah langsung via teks/chat.',
                'status'           => 'belum ditangani',
                'waktu_laporan'    => Carbon::now()->subHours(2),
            ],
        ];

        foreach ($laporanAktif as $data) {
            Laporan::create($data);
        }

        // ---------------------------------------------------------------------
        // 2. LAPORAN DENGAN STATUS: 'proses' (Sedang Ditangani oleh Relawan)
        // ---------------------------------------------------------------------
        $laporanProses = [
            [
                'id_pengguna'      => $dewi->id,
                'id_relawan'       => $rian?->id,
                'lokasi_laporan'   => 'Area Gejayan, Sleman - Dekat Apotek K-24 Affandi',
                'latitude'         => -7.761200,
                'longitude'        => 110.392400,
                'kategori_laporan' => 'kondisi_medis',
                'deskripsi'        => 'Mengalami sesak napas ringan dan kehabisan obat inhaler. Membutuhkan bantuan relawan untuk membelikan obat darurat.',
                'status'           => 'ditangani',
                'waktu_laporan'    => Carbon::now()->subMinutes(45),
                'catatan'          => 'Relawan Rian Hidayat telah menerima laporan dan sedang membelikan inhaler di apotek terdekat.',
            ],
            [
                'id_pengguna'      => $budi->id,
                'id_relawan'       => $annisa?->id,
                'lokasi_laporan'   => 'Jl. Veteran No. 15, Umbulharjo, Yogyakarta',
                'latitude'         => -7.812300,
                'longitude'        => 110.384500,
                'kategori_laporan' => 'butuh_pendamping',
                'deskripsi'        => 'Pendampingan administrasi dan mobilitas pengurusan berkas kependudukan di kantor kelurahan setempat.',
                'status'           => 'ditangani',
                'waktu_laporan'    => Carbon::now()->subHours(1)->subMinutes(30),
                'catatan'          => 'Relawan Annisa Putri telah tiba di lokasi dan saat ini sedang mendampingi pelapor di loket pelayanan kelurahan.',
            ],
        ];

        foreach ($laporanProses as $data) {
            $catatanText = $data['catatan'] ?? null;
            unset($data['catatan']);

            $laporan = Laporan::create($data);

            if ($catatanText && $admin) {
                CatatanPenanganan::create([
                    'id_admin'   => $admin->id,
                    'id_laporan' => $laporan->id,
                    'catatan'    => $catatanText,
                ]);
            }
        }

        // ---------------------------------------------------------------------
        // 3. LAPORAN DENGAN STATUS: 'selesai' (Kasus Selesai Ditangani)
        // ---------------------------------------------------------------------
        $laporanSelesai = [
            [
                'id_pengguna'      => $rahma->id,
                'id_relawan'       => $dimas?->id,
                'lokasi_laporan'   => 'Titik Nol Kilometer Yogyakarta - Depan Gedung BNI',
                'latitude'         => -7.801200,
                'longitude'        => 110.365000,
                'kategori_laporan' => 'tersesat',
                'deskripsi'        => 'Terpisah dari keluarga saat karnaval budaya di Titik Nol Kilometer, butuh bantuan komunikasi untuk menghubungi posko keamanan.',
                'status'           => 'selesai',
                'waktu_laporan'    => Carbon::now()->subHours(4),
                'catatan'          => 'Relawan Dimas Anggara berhasil mempertemukan pelapor dengan keluarganya di posko satpam BNI 46.',
            ],
            [
                'id_pengguna'      => $fauzi->id,
                'id_relawan'       => $rian?->id,
                'lokasi_laporan'   => 'Area Ringroad Utara, Maguwoharjo - Halte Bus Bandara Adisutjipto',
                'latitude'         => -7.778000,
                'longitude'        => 110.421000,
                'kategori_laporan' => 'ancaman_bahaya',
                'deskripsi'        => 'Ada orang mencurigakan yang membuntuti di area halte malam hari, butuh penjemputan atau pendampingan pengamanan.',
                'status'           => 'selesai',
                'waktu_laporan'    => Carbon::now()->subDay()->subHours(2),
                'catatan'          => 'Relawan Rian Hidayat bersama petugas keamanan stasiun mengawal pelapor hingga menaiki taksi resmi dengan aman.',
            ],
            [
                'id_pengguna'      => $budi->id,
                'id_relawan'       => $annisa?->id,
                'lokasi_laporan'   => 'Jl. Palagan Tentara Pelajar KM 7, Sleman',
                'latitude'         => -7.734500,
                'longitude'        => 110.370200,
                'kategori_laporan' => 'lainnya',
                'deskripsi'        => 'Kerusakan pada tongkat pemandu pintar (baterai habis dan sensor macet) saat berada di supermarket.',
                'status'           => 'selesai',
                'waktu_laporan'    => Carbon::now()->subDays(2),
                'catatan'          => 'Bantuan perbaikan tongkat dan pengisian daya telah dibantu oleh Relawan Annisa Putri.',
            ],
        ];

        foreach ($laporanSelesai as $data) {
            $catatanText = $data['catatan'] ?? null;
            unset($data['catatan']);

            $laporan = Laporan::create($data);

            if ($catatanText && $admin) {
                CatatanPenanganan::create([
                    'id_admin'   => $admin->id,
                    'id_laporan' => $laporan->id,
                    'catatan'    => $catatanText,
                ]);
            }
        }
    }
}
