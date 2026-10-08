<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\SOS;
use App\Models\SOSActivity;
use App\Models\CatatanPenanganan;
use Carbon\Carbon;

class SosSeeder extends Seeder
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
        // 1. SOS DENGAN STATUS: 'aktif' (Sinyal Kritis / Menunggu Relawan)
        // ---------------------------------------------------------------------
        $sosAktifList = [
            [
                'id_pengguna'     => $budi->id,
                'id_relawan'      => null,
                'latitude'        => -7.766500,
                'longitude'       => 110.407200,
                'status_sos'      => 'aktif',
                'waktu_sos'       => Carbon::now()->subMinutes(5),
                'battery_level'   => 14, // Kritis
                'signal_strength' => '4G / Kuat',
                'device_info'     => [
                    'battery_level'   => 14,
                    'battery_status'  => 'Kritis',
                    'signal_strength' => '4G / Kuat',
                    'device_model'    => 'Samsung Galaxy A54 5G',
                    'os_version'      => 'Android 14',
                ],
                'activity_desc'   => 'Sinyal SOS darurat diaktifkan oleh pengguna tunanetra melalui tombol darurat cepat (tekan 5x tombol fisik).',
            ],
            [
                'id_pengguna'     => $rahma->id,
                'id_relawan'      => null,
                'latitude'        => -7.808900,
                'longitude'       => 110.378900,
                'status_sos'      => 'aktif',
                'waktu_sos'       => Carbon::now()->subMinutes(18),
                'battery_level'   => 62,
                'signal_strength' => '4G / Kuat',
                'device_info'     => [
                    'battery_level'   => 62,
                    'battery_status'  => 'Baik',
                    'signal_strength' => '4G / Kuat',
                    'device_model'    => 'Xiaomi Redmi Note 12',
                    'os_version'      => 'Android 13',
                ],
                'activity_desc'   => 'Tombol darurat SOS ditekan dari antarmuka visual aplikasi Sahabat SOS.',
            ],
        ];

        foreach ($sosAktifList as $item) {
            $actDesc = $item['activity_desc'];
            unset($item['activity_desc']);

            $sos = SOS::create($item);

            SOSActivity::create([
                'sos_id'      => $sos->id,
                'user_id'     => $item['id_pengguna'],
                'action'      => 'sos_dipicu',
                'description' => $actDesc,
                'created_at'  => $item['waktu_sos'],
            ]);
        }

        // ---------------------------------------------------------------------
        // 2. SOS DENGAN STATUS: 'proses' (Sedang Ditangani oleh Relawan)
        // ---------------------------------------------------------------------
        $sosProsesList = [
            [
                'id_pengguna'     => $dewi->id,
                'id_relawan'      => $rian?->id,
                'latitude'        => -7.772000,
                'longitude'       => 110.401000,
                'status_sos'      => 'proses',
                'waktu_sos'       => Carbon::now()->subMinutes(35),
                'battery_level'   => 45,
                'signal_strength' => '4G / Kuat',
                'device_info'     => [
                    'battery_level'   => 45,
                    'battery_status'  => 'Sedang',
                    'signal_strength' => '4G / Kuat',
                    'device_model'    => 'Vivo V27 5G',
                    'os_version'      => 'Android 14',
                ],
                'activities' => [
                    [
                        'action'      => 'sos_dipicu',
                        'description' => 'Sinyal darurat SOS diaktifkan oleh pengguna tunawicara.',
                        'user_id'     => $dewi->id,
                        'time'        => Carbon::now()->subMinutes(35),
                    ],
                    [
                        'action'      => 'relawan_menerima',
                        'description' => 'Relawan Rian Hidayat menerima panggilan darurat dan bergerak menuju lokasi korban.',
                        'user_id'     => $rian?->id,
                        'time'        => Carbon::now()->subMinutes(30),
                    ],
                ],
                'catatan' => 'Relawan Rian Hidayat sedang dalam perjalanan menuju lokasi (estimasi tiba ~3 menit). Kontak darurat keluarga telah diberitahu.',
            ],
            [
                'id_pengguna'     => $fauzi->id,
                'id_relawan'      => $annisa?->id,
                'latitude'        => -7.795000,
                'longitude'       => 110.368000,
                'status_sos'      => 'proses',
                'waktu_sos'       => Carbon::now()->subMinutes(50),
                'battery_level'   => 78,
                'signal_strength' => '5G / Sangat Kuat',
                'device_info'     => [
                    'battery_level'   => 78,
                    'battery_status'  => 'Baik',
                    'signal_strength' => '5G / Sangat Kuat',
                    'device_model'    => 'OPPO Reno 10',
                    'os_version'      => 'Android 13',
                ],
                'activities' => [
                    [
                        'action'      => 'sos_dipicu',
                        'description' => 'Sinyal SOS darurat aktif di kawasan padat Malioboro.',
                        'user_id'     => $fauzi->id,
                        'time'        => Carbon::now()->subMinutes(50),
                    ],
                    [
                        'action'      => 'admin_dispatch',
                        'description' => 'Petugas Admin mengalokasikan penanganan darurat kepada Relawan Annisa Putri.',
                        'user_id'     => $admin?->id,
                        'time'        => Carbon::now()->subMinutes(47),
                    ],
                    [
                        'action'      => 'relawan_menerima',
                        'description' => 'Relawan Annisa Putri mengonfirmasi penugasan dan telah tiba di TKP.',
                        'user_id'     => $annisa?->id,
                        'time'        => Carbon::now()->subMinutes(40),
                    ],
                ],
                'catatan' => 'Relawan Annisa telah tiba di TKP dan mendampingi pelapor memeriksa situasi.',
            ],
        ];

        foreach ($sosProsesList as $item) {
            $activities  = $item['activities'] ?? [];
            $catatanText = $item['catatan'] ?? null;
            unset($item['activities'], $item['catatan']);

            $sos = SOS::create($item);

            foreach ($activities as $act) {
                SOSActivity::create([
                    'sos_id'      => $sos->id,
                    'user_id'     => $act['user_id'],
                    'action'      => $act['action'],
                    'description' => $act['description'],
                    'created_at'  => $act['time'],
                ]);
            }

            if ($catatanText && $admin) {
                CatatanPenanganan::create([
                    'id_admin' => $admin->id,
                    'id_sos'   => $sos->id,
                    'catatan'  => $catatanText,
                ]);
            }
        }

        // ---------------------------------------------------------------------
        // 3. SOS DENGAN STATUS: 'selesai' (Selesai Ditangani)
        // ---------------------------------------------------------------------
        $sosSelesaiList = [
            [
                'id_pengguna'     => $budi->id,
                'id_relawan'      => $dimas?->id,
                'latitude'        => -7.760000,
                'longitude'       => 110.410000,
                'status_sos'      => 'selesai',
                'waktu_sos'       => Carbon::now()->subHours(3),
                'battery_level'   => 85,
                'signal_strength' => '4G / Kuat',
                'device_info'     => [
                    'battery_level'   => 85,
                    'battery_status'  => 'Baik',
                    'signal_strength' => '4G / Kuat',
                    'device_model'    => 'Samsung Galaxy A54 5G',
                    'os_version'      => 'Android 14',
                ],
                'activities' => [
                    ['action' => 'sos_dipicu', 'description' => 'Panggilan darurat SOS diaktifkan.', 'user_id' => $budi->id, 'time' => Carbon::now()->subHours(3)],
                    ['action' => 'relawan_menerima', 'description' => 'Relawan Dimas Anggara menerima dan tiba di lokasi kejadian.', 'user_id' => $dimas?->id, 'time' => Carbon::now()->subHours(3)->addMinutes(10)],
                    ['action' => 'sos_selesai', 'description' => 'Penanganan selesai, korban berhasil dievakuasi ke tempat aman.', 'user_id' => $dimas?->id, 'time' => Carbon::now()->subHours(2)],
                ],
                'catatan' => 'Korban disabilitas netra berhasil diantar pulang dengan selamat bersama pihak keluarga.',
            ],
            [
                'id_pengguna'     => $rahma->id,
                'id_relawan'      => $rian?->id,
                'latitude'        => -7.811000,
                'longitude'       => 110.380000,
                'status_sos'      => 'selesai',
                'waktu_sos'       => Carbon::now()->subDay()->subHours(1),
                'battery_level'   => 70,
                'signal_strength' => '4G / Kuat',
                'device_info'     => [
                    'battery_level'   => 70,
                    'battery_status'  => 'Baik',
                    'signal_strength' => '4G / Kuat',
                    'device_model'    => 'Xiaomi Redmi Note 12',
                    'os_version'      => 'Android 13',
                ],
                'activities' => [
                    ['action' => 'sos_dipicu', 'description' => 'Sinyal SOS darurat aktif.', 'user_id' => $rahma->id, 'time' => Carbon::now()->subDay()->subHours(1)],
                    ['action' => 'relawan_menerima', 'description' => 'Relawan Rian Hidayat merespon panggilan.', 'user_id' => $rian?->id, 'time' => Carbon::now()->subDay()->subHours(1)->addMinutes(5)],
                    ['action' => 'sos_selesai', 'description' => 'Kasus darurat berhasil diselesaikan tuntas.', 'user_id' => $rian?->id, 'time' => Carbon::now()->subDay()],
                ],
                'catatan' => 'Korban mendapatkan pendampingan medis ringan dan sudah kembali beristirahat di rumah.',
            ],
        ];

        foreach ($sosSelesaiList as $item) {
            $activities  = $item['activities'] ?? [];
            $catatanText = $item['catatan'] ?? null;
            unset($item['activities'], $item['catatan']);

            $sos = SOS::create($item);

            foreach ($activities as $act) {
                SOSActivity::create([
                    'sos_id'      => $sos->id,
                    'user_id'     => $act['user_id'],
                    'action'      => $act['action'],
                    'description' => $act['description'],
                    'created_at'  => $act['time'],
                ]);
            }

            if ($catatanText && $admin) {
                CatatanPenanganan::create([
                    'id_admin' => $admin->id,
                    'id_sos'   => $sos->id,
                    'catatan'  => $catatanText,
                ]);
            }
        }

        // ---------------------------------------------------------------------
        // 4. SOS DENGAN STATUS: 'batal' (Dibatalkan / False Alarm)
        // ---------------------------------------------------------------------
        $sosBatal = SOS::create([
            'id_pengguna'     => $fauzi->id,
            'id_relawan'      => null,
            'latitude'        => -7.770000,
            'longitude'       => 110.390000,
            'status_sos'      => 'batal',
            'alasan_batal'    => 'Tombol darurat fisik tidak sengaja tertekan berkali-kali saat ponsel berada di dalam saku baju.',
            'waktu_sos'       => Carbon::now()->subHours(2),
            'battery_level'   => 90,
            'signal_strength' => '4G / Kuat',
            'device_info'     => [
                'battery_level'   => 90,
                'battery_status'  => 'Baik',
                'signal_strength' => '4G / Kuat',
                'device_model'    => 'OPPO Reno 10',
                'os_version'      => 'Android 13',
            ],
        ]);

        SOSActivity::create([
            'sos_id'      => $sosBatal->id,
            'user_id'     => $fauzi->id,
            'action'      => 'sos_dipicu',
            'description' => 'Sinyal SOS darurat terpicu secara otomatis.',
            'created_at'  => Carbon::now()->subHours(2),
        ]);

        SOSActivity::create([
            'sos_id'      => $sosBatal->id,
            'user_id'     => $fauzi->id,
            'action'      => 'sos_dibatalkan',
            'description' => 'Panggilan SOS dibatalkan oleh pengguna dengan alasan tidak sengaja tertekan di saku.',
            'created_at'  => Carbon::now()->subHours(2)->addMinutes(2),
        ]);
    }
}
