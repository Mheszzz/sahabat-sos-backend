<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Account;
use App\Models\Kontak_darurat;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Update/Ensure Admin Utama has full admin permissions for testing
        $adminAccount = Account::where('email', 'admin@sahabatsos.com')->where('provider', 'local')->first();
        if ($adminAccount && $adminAccount->user) {
            $adminAccount->user->update([
                'permissions' => [
                    'kelola_laporan',
                    'pantau_peta',
                    'kelola_relawan',
                    'verifikasi_relawan',
                    'broadcast_sirene',
                    'kelola_admin',
                ],
                'permissions_granted_at' => Carbon::now(),
            ]);
        }

        // 2. Seed Pengguna (Masyarakat / Difabel)
        $penggunaList = [
            [
                'name'               => 'Budi Santoso',
                'email'              => 'budi@sahabatsos.test',
                'no_telp'            => '081211112222',
                'alamat'             => 'Jl. Seturan Raya No. 12, Depok, Sleman, Yogyakarta',
                'kategori_user'      => 'tunanetra',
                'metode_komunikasi'  => 'pesan_suara_audio',
                'talkback'           => true,
                'panduan_suara'      => true,
                'getaran'            => true,
                'catatan_medis'      => 'Tunanetra total sejak lahir. Membutuhkan panduan suara atau pendamping fisik di jalan ramai.',
                'latitude'           => -7.765432,
                'longitude'          => 110.408765,
                'lokasi_user'        => 'Seturan, Sleman',
                'kontak' => [
                    ['nama' => 'Siti Aminah (Ibu)', 'no_telp' => '081299991111', 'tipe' => 'utama', 'pesan' => 'Mohon segera hubungi ibu jika terjadi keadaan darurat'],
                    ['nama' => 'Hendra Santoso (Kakak)', 'no_telp' => '081299992222', 'tipe' => 'sekunder', 'pesan' => 'Kontak sekunder darurat keluarga'],
                ],
            ],
            [
                'name'               => 'Siti Rahma',
                'email'              => 'rahma@sahabatsos.test',
                'no_telp'            => '081222223333',
                'alamat'             => 'Jl. Babaran No. 45, Umbulharjo, Yogyakarta',
                'kategori_user'      => 'tunarungu',
                'metode_komunikasi'  => 'chat',
                'getaran'            => true,
                'text_besar'         => true,
                'kontras_tinggi'     => true,
                'catatan_medis'      => 'Disabilitas pendengaran total. Komunikasi wajib menggunakan teks tertulis atau BISINDO.',
                'latitude'           => -7.810500,
                'longitude'          => 110.381200,
                'lokasi_user'        => 'Babaran, Umbulharjo',
                'kontak' => [
                    ['nama' => 'Bambang Raharjo (Ayah)', 'no_telp' => '081288881111', 'tipe' => 'utama', 'pesan' => 'Hubungi via WhatsApp/SMS karena anak tunarungu'],
                ],
            ],
            [
                'name'               => 'Dewi Lestari',
                'email'              => 'dewi@sahabatsos.test',
                'no_telp'            => '081233334444',
                'alamat'             => 'Jl. Kaliurang KM 5.2 No. 8, Sleman, Yogyakarta',
                'kategori_user'      => 'tunawicara',
                'metode_komunikasi'  => 'chat',
                'getaran'            => true,
                'catatan_medis'      => 'Hambatan wicara dan riwayat asma ringan. Bawa inhaler darurat.',
                'latitude'           => -7.768900,
                'longitude'          => 110.379800,
                'lokasi_user'        => 'Kaliurang, Sleman',
                'kontak' => [
                    ['nama' => 'Ratna Sari (Adik)', 'no_telp' => '081277771111', 'tipe' => 'utama', 'pesan' => 'Tolong prioritaskan bantuan asma jika terjadi serangan sesak napas'],
                ],
            ],
            [
                'name'               => 'Ahmad Fauzi',
                'email'              => 'fauzi@sahabatsos.test',
                'no_telp'            => '081244445555',
                'alamat'             => 'Jl. Malioboro No. 102, Danurejan, Yogyakarta',
                'kategori_user'      => 'umum',
                'metode_komunikasi'  => 'keduanya',
                'catatan_medis'      => 'Warga umum, tidak ada alergi atau riwayat penyakit kronis.',
                'latitude'           => -7.789500,
                'longitude'          => 110.364500,
                'lokasi_user'        => 'Malioboro, Yogyakarta',
                'kontak' => [
                    ['nama' => 'Nurul Hidayah (Istri)', 'no_telp' => '081266661111', 'tipe' => 'utama', 'pesan' => 'Hubungi istri segera jika ada insiden'],
                ],
            ],
        ];

        foreach ($penggunaList as $p) {
            $user = User::firstOrCreate(
                ['no_telp' => $p['no_telp']],
                [
                    'name'                => $p['name'],
                    'alamat'              => $p['alamat'],
                    'role'                => 'pengguna',
                    'kategori_user'       => $p['kategori_user'],
                    'metode_komunikasi'   => $p['metode_komunikasi'],
                    'talkback'            => $p['talkback'] ?? false,
                    'panduan_suara'       => $p['panduan_suara'] ?? false,
                    'getaran'             => $p['getaran'] ?? false,
                    'text_besar'          => $p['text_besar'] ?? false,
                    'kontras_tinggi'      => $p['kontras_tinggi'] ?? false,
                    'catatan_medis'       => $p['catatan_medis'],
                    'latitude'            => $p['latitude'],
                    'longitude'           => $p['longitude'],
                    'lokasi_user'         => $p['lokasi_user'],
                    'persetujuan_privasi' => true,
                    'waktu_persetujuan'   => Carbon::now(),
                    'is_active'           => true,
                ]
            );

            Account::firstOrCreate(
                ['provider' => 'local', 'email' => $p['email']],
                [
                    'user_id'  => $user->id,
                    'password' => Hash::make('password123'),
                ]
            );

            // Seed kontak darurat
            if (!empty($p['kontak'])) {
                foreach ($p['kontak'] as $ktk) {
                    Kontak_darurat::firstOrCreate(
                        ['id_pengguna' => $user->id, 'no_telp' => $ktk['no_telp']],
                        [
                            'nama'         => $ktk['nama'],
                            'pesan'        => $ktk['pesan'],
                            'tipe'         => $ktk['tipe'],
                            'terima_notif' => true,
                        ]
                    );
                }
            }
        }

        // 3. Seed Relawan Siaga (Volunteers)
        $relawanList = [
            [
                'name'                => 'Rian Hidayat (Relawan)',
                'email'               => 'relawan1@sahabatsos.test',
                'no_telp'             => '085611110001',
                'alamat'              => 'Jl. Seturan No. 5, Depok, Sleman',
                'pekerjaan'           => 'Paramedis / First Aid',
                'alasan_relawan'      => 'Ingin berkontribusi membantu sesama dan kaum disabilitas dalam keadaan darurat medis.',
                'status_verifikasi'   => 'terverifikasi',
                'status_ketersediaan' => 'siaga',
                'lokasi_user'         => 'Posko Sleman Timur (Seturan)',
                'latitude'            => -7.765000,
                'longitude'           => 110.408000,
            ],
            [
                'name'                => 'Annisa Putri (Relawan)',
                'email'               => 'relawan2@sahabatsos.test',
                'no_telp'             => '085611110002',
                'alamat'              => 'Jl. Babaran No. 10, Umbulharjo, Kota Yogyakarta',
                'pekerjaan'           => 'Penerjemah Bahasa Isyarat (BISINDO)',
                'alasan_relawan'      => 'Memiliki keahlian komunikasi isyarat untuk mendampingi rekan tunarungu dan tunawicara.',
                'status_verifikasi'   => 'terverifikasi',
                'status_ketersediaan' => 'siaga',
                'lokasi_user'         => 'Posko Kota Yogyakarta (Babaran)',
                'latitude'            => -7.810000,
                'longitude'           => 110.380000,
            ],
            [
                'name'                => 'Dimas Anggara (Relawan)',
                'email'               => 'relawan3@sahabatsos.test',
                'no_telp'             => '085611110003',
                'alamat'              => 'Jl. Malioboro No. 20, Danurejan, Kota Yogyakarta',
                'pekerjaan'           => 'Relawan Tanggap Darurat & SAR',
                'alasan_relawan'      => 'Siaga respon cepat bantuan evakuasi dan pendampingan mobilitas darurat.',
                'status_verifikasi'   => 'terverifikasi',
                'status_ketersediaan' => 'siaga',
                'lokasi_user'         => 'Posko Reaksi Cepat Malioboro',
                'latitude'            => -7.792500,
                'longitude'           => 110.365800,
            ],
        ];

        foreach ($relawanList as $r) {
            $relawan = User::firstOrCreate(
                ['no_telp' => $r['no_telp']],
                [
                    'name'                => $r['name'],
                    'alamat'              => $r['alamat'],
                    'role'                => 'relawan',
                    'pekerjaan'           => $r['pekerjaan'],
                    'alasan_relawan'      => $r['alasan_relawan'],
                    'status_verifikasi'   => $r['status_verifikasi'],
                    'status_ketersediaan' => $r['status_ketersediaan'],
                    'lokasi_user'         => $r['lokasi_user'],
                    'latitude'            => $r['latitude'],
                    'longitude'           => $r['longitude'],
                    'last_located_at'     => Carbon::now(),
                    'persetujuan_privasi' => true,
                    'waktu_persetujuan'   => Carbon::now(),
                    'is_active'           => true,
                ]
            );

            Account::firstOrCreate(
                ['provider' => 'local', 'email' => $r['email']],
                [
                    'user_id'  => $relawan->id,
                    'password' => Hash::make('password123'),
                ]
            );
        }
    }
}
