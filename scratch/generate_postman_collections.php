<?php

$baseUrl = '{{base_url}}';

function buildHeaders($auth = true) {
    $headers = [
        ['key' => 'Accept', 'value' => 'application/json', 'type' => 'text']
    ];
    return $headers;
}

function buildAuthHeader() {
    return [
        'type' => 'bearer',
        'bearer' => [
            ['key' => 'token', 'value' => '{{token}}', 'type' => 'string']
        ]
    ];
}

function buildAdminAuthHeader() {
    return [
        'type' => 'bearer',
        'bearer' => [
            ['key' => 'token', 'value' => '{{admin_token}}', 'type' => 'string']
        ]
    ];
}

function buildRelawanAuthHeader() {
    return [
        'type' => 'bearer',
        'bearer' => [
            ['key' => 'token', 'value' => '{{relawan_token}}', 'type' => 'string']
        ]
    ];
}

function makeRequest($name, $method, $path, $body = null, $authType = 'user', $params = []) {
    $urlParts = explode('/', ltrim($path, '/'));
    $pathArr = array_values(array_filter($urlParts));
    
    $req = [
        'name' => $name,
        'request' => [
            'method' => strtoupper($method),
            'header' => buildHeaders(),
            'url' => [
                'raw' => '{{base_url}}/' . ltrim($path, '/'),
                'host' => ['{{base_url}}'],
                'path' => $pathArr
            ]
        ]
    ];

    if ($authType === 'user') {
        $req['request']['auth'] = buildAuthHeader();
    } elseif ($authType === 'admin') {
        $req['request']['auth'] = buildAdminAuthHeader();
    } elseif ($authType === 'relawan') {
        $req['request']['auth'] = buildRelawanAuthHeader();
    } // 'none' means public

    if ($body !== null) {
        $req['request']['body'] = [
            'mode' => 'json',
            'json' => json_encode($body, JSON_PRETTY_PRINT)
        ];
        $req['request']['header'][] = ['key' => 'Content-Type', 'value' => 'application/json', 'type' => 'text'];
    }

    return $req;
}

// -------------------------------------------------------------
// 1. ROLE: PENGGUNA (User Mobile Workflow)
// -------------------------------------------------------------
$penggunaItems = [
    // 1. Auth & Login
    makeRequest('01. Register Pengguna', 'POST', 'auth/register/pengguna', [
        'name' => 'Budi Pengguna',
        'no_telp' => '081234567890',
        'password' => 'password123',
        'role' => 'pengguna',
        'kategori_user' => 'umum',
        'alamat' => 'Jl. Merdeka No. 10 Jakarta'
    ], 'none'),

    makeRequest('02. Login Pengguna', 'POST', 'auth/login', [
        'no_telp' => '081234567890',
        'password' => 'password123'
    ], 'none'),

    // 2. Profile & Geolocation
    makeRequest('03. Get Profile Saya (me)', 'GET', 'user/me', null, 'user'),
    makeRequest('04. Lengkapi Profile Pengguna', 'POST', 'user/complete-profile', [
        'alamat' => 'Jl. Pemuda No. 45 Jakarta',
        'kategori_user' => 'umum',
        'catatan_medis' => 'Alergi obat penicillin'
    ], 'user'),
    makeRequest('05. Update Lokasi GPS Pengguna', 'POST', 'user/update-location', [
        'latitude' => -6.2088,
        'longitude' => 106.8456,
        'lokasi_user' => 'Jakarta Pusat'
    ], 'user'),
    makeRequest('06. Detail Profil Pengguna', 'GET', 'pengguna/profile', null, 'user'),
    makeRequest('07. Update Profil Pengguna', 'PUT', 'pengguna/profile', [
        'name' => 'Budi Pengguna Updated',
        'catatan_medis' => 'Riwayat asma ringan',
        'text_besar' => true,
        'kontras_tinggi' => false
    ], 'user'),

    // 3. Kontak Darurat
    makeRequest('08. List Kontak Darurat', 'GET', 'pengguna/kontak-darurat', null, 'user'),
    makeRequest('09. Tambah Kontak Darurat', 'POST', 'pengguna/kontak-darurat', [
        'nama_kontak' => 'Istri Budi',
        'nomor_telepon' => '081999888777',
        'hubungan' => 'Istri'
    ], 'user'),
    makeRequest('10. Detail Kontak Darurat', 'GET', 'pengguna/kontak-darurat/1', null, 'user'),
    makeRequest('11. Update Kontak Darurat', 'PUT', 'pengguna/kontak-darurat/1', [
        'nama_kontak' => 'Istri Budi S.',
        'nomor_telepon' => '081999888777',
        'hubungan' => 'Istri'
    ], 'user'),
    makeRequest('12. Toggle Notifikasi Kontak Darurat', 'PATCH', 'pengguna/kontak-darurat/1/toggle-notif', null, 'user'),
    makeRequest('13. Hapus Kontak Darurat', 'DELETE', 'pengguna/kontak-darurat/1', null, 'user'),

    // 4. Beranda & Laporan
    makeRequest('14. Beranda Pengguna', 'GET', 'beranda', null, 'user'),
    makeRequest('15. Get Opsi Laporan', 'GET', 'laporan/options', null, 'user'),
    makeRequest('16. Buat Laporan Kejadian', 'POST', 'laporan', [
        'kategori_laporan' => 'Kecelakaan Lalu Lintas',
        'lokasi_laporan' => 'Jl. Sudirman KM 5',
        'latitude' => -6.2088,
        'longitude' => 106.8456,
        'deskripsi' => 'Terjadi kecelakaan sepeda motor di dekat perempatan'
    ], 'user'),
    makeRequest('17. List Laporan Pengguna', 'GET', 'laporan', null, 'user'),
    makeRequest('18. List Laporan Terdekat', 'GET', 'laporan/nearby?latitude=-6.2088&longitude=106.8456', null, 'user'),
    makeRequest('19. Detail Laporan Kejadian', 'GET', 'laporan/1', null, 'user'),

    // 5. Fitur Sinyal SOS Darurat
    makeRequest('20. Trigger Sinyal SOS', 'POST', 'sos/trigger', [
        'latitude' => -6.2088,
        'longitude' => 106.8456
    ], 'user'),
    makeRequest('21. Get SOS Aktif Saya', 'GET', 'sos/active', null, 'user'),
    makeRequest('22. Detail Sinyal SOS', 'GET', 'sos/1', null, 'user'),
    makeRequest('23. Batalkan Sinyal SOS', 'POST', 'sos/1/cancel', [
        'alasan_batal' => 'Situasi sudah aman / salah tekan'
    ], 'user'),
    makeRequest('24. Riwayat SOS Pengguna', 'GET', 'sos/user/history', null, 'user'),
];

// -------------------------------------------------------------
// 2. ROLE: RELAWAN (Volunteer Workflow)
// -------------------------------------------------------------
$relawanItems = [
    // 1. Auth & Login Relawan
    makeRequest('01. Register Relawan', 'POST', 'auth/register/relawan', [
        'name' => 'Siti Relawan',
        'no_telp' => '085678901234',
        'password' => 'password123',
        'pekerjaan' => 'Tenaga Medis',
        'alasan_relawan' => 'Ingin membantu korban darurat di sekitar'
    ], 'none'),

    makeRequest('02. Login Relawan', 'POST', 'auth/login', [
        'no_telp' => '085678901234',
        'password' => 'password123'
    ], 'none'),

    // 2. Profile & Location
    makeRequest('03. Get Profile Relawan (me)', 'GET', 'user/me', null, 'relawan'),
    makeRequest('04. Update Lokasi GPS Relawan', 'POST', 'user/update-location', [
        'latitude' => -6.2090,
        'longitude' => 106.8460,
        'lokasi_user' => 'Jakarta Pusat',
        'status_ketersediaan' => 'tersedia'
    ], 'relawan'),
    makeRequest('05. Detail Profile Relawan', 'GET', 'relawan/profile', null, 'relawan'),

    // 3. Beranda & Tugas SOS
    makeRequest('06. Beranda Relawan', 'GET', 'beranda', null, 'relawan'),
    makeRequest('07. List Laporan Terdekat', 'GET', 'laporan/nearby?latitude=-6.2090&longitude=106.8460', null, 'relawan'),
    makeRequest('08. List SOS Aktif Sekitar Relawan', 'GET', 'sos/active/relawan', null, 'relawan'),
    makeRequest('09. Menolak / Melewati SOS (rejectSOS)', 'POST', 'sos/1/reject', null, 'relawan'),
    makeRequest('10. Terima & Proses SOS (proses)', 'PATCH', 'sos/1/status', [
        'status_sos' => 'proses'
    ], 'relawan'),
    makeRequest('11. Lihat Tugas SOS Aktif (activeTask)', 'GET', 'sos/relawan/tasks', null, 'relawan'),
    makeRequest('12. Selesaikan SOS (selesai)', 'PATCH', 'sos/1/status', [
        'status_sos' => 'selesai'
    ], 'relawan'),
    makeRequest('13. Update Status Laporan Kejadian', 'PUT', 'laporan/1/status', [
        'status' => 'selesai'
    ], 'relawan'),
];

// -------------------------------------------------------------
// 3. ROLE: ADMIN (Web Dashboard Admin Workflow)
// -------------------------------------------------------------
$adminItems = [
    // 1. Auth Login Admin
    makeRequest('01. Login Admin Dashboard', 'POST', 'auth/admin/login', [
        'no_telp' => '081111111111',
        'password' => 'password123'
    ], 'none'),

    // 2. Dashboard Monitoring & Maps
    makeRequest('02. Beranda Admin', 'GET', 'admin/beranda', null, 'admin'),
    makeRequest('03. Dashboard Stats Admin', 'GET', 'admin/dashboard', null, 'admin'),
    makeRequest('04. Dashboard Stats (Grafik)', 'GET', 'admin/dashboard-stats', null, 'admin'),
    makeRequest('05. Peta Kasus Aktif Admin', 'GET', 'admin/dashboard/peta-kasus', null, 'admin'),
    makeRequest('06. Sebaran Urgensi Kasus Admin', 'GET', 'admin/dashboard/sebaran-urgensi', null, 'admin'),

    // 3. Verifikasi & Dispatch Relawan
    makeRequest('07. List Relawan Pending Verifikasi', 'GET', 'admin/relawan/pending', null, 'admin'),
    makeRequest('08. Verifikasi Relawan (Disetujui/Ditolak)', 'PUT', 'admin/relawan/1/verifikasi', [
        'status_verifikasi' => 'terverifikasi'
    ], 'admin'),
    makeRequest('09. Quick Dispatch (Cari Relawan Terdekat)', 'GET', 'admin/dashboard/quick-dispatch?id_sos=1', null, 'admin'),
    makeRequest('10. Dispatch Relawan ke Lokasi SOS', 'POST', 'admin/dashboard/dispatch', [
        'id_sos' => 1,
        'id_relawan' => 2
    ], 'admin'),
    makeRequest('11. Admin Selesaikan SOS', 'PUT', 'admin/dashboard/sos/1/selesai', null, 'admin'),
    makeRequest('12. Global Search Dashboard', 'GET', 'admin/dashboard/search?q=Budi', null, 'admin'),

    // 4. Kelola Master Data
    makeRequest('13. List Kategori Laporan', 'GET', 'admin/kategori-laporan', null, 'admin'),
    makeRequest('14. Buat Kategori Laporan Baru', 'POST', 'admin/kategori-laporan', [
        'nama_kategori' => 'Bencana Alam Fire',
        'deskripsi' => 'Kategori untuk laporan kebakaran'
    ], 'admin'),
    makeRequest('15. Update Kategori Laporan', 'PUT', 'admin/kategori-laporan/1', [
        'nama_kategori' => 'Kebakaran Hutan & Pemukiman'
    ], 'admin'),
    makeRequest('16. Hapus Kategori Laporan', 'DELETE', 'admin/kategori-laporan/1', null, 'admin'),

    makeRequest('17. List Pesan Cepat', 'GET', 'admin/pesan-cepat', null, 'admin'),
    makeRequest('18. Buat Pesan Cepat Baru', 'POST', 'admin/pesan-cepat', [
        'pesan' => 'Butuh Pertolongan Medis Segera'
    ], 'admin'),
    makeRequest('19. Update Pesan Cepat', 'PUT', 'admin/pesan-cepat/1', [
        'pesan' => 'Butuh Mobil Ambulans Segera'
    ], 'admin'),
    makeRequest('20. Hapus Pesan Cepat', 'DELETE', 'admin/pesan-cepat/1', null, 'admin'),
];

// -------------------------------------------------------------
// 4. ROLE: SUPERADMIN (Web Dashboard Superadmin Workflow)
// -------------------------------------------------------------
$superadminItems = [
    // 1. Auth Superadmin
    makeRequest('01. Login Superadmin Dashboard', 'POST', 'auth/admin/login', [
        'no_telp' => '089999999999',
        'password' => 'password123'
    ], 'none'),

    // 2. Manajemen Admin
    makeRequest('02. List Seluruh Akun Admin', 'GET', 'superadmin/admins', null, 'admin'),
    makeRequest('03. Tambah Akun Admin Baru', 'POST', 'superadmin/admins', [
        'name' => 'Admin Wilayah Pusat',
        'no_telp' => '081222333444',
        'password' => 'password123',
        'role' => 'admin',
        'permissions' => ['verifikasi_relawan', 'kelola_laporan']
    ], 'admin'),
    makeRequest('04. Update Hak Akses (Permissions) Admin', 'PUT', 'superadmin/admins/1/permissions', [
        'permissions' => ['verifikasi_relawan', 'kelola_laporan', 'dispatch_sos']
    ], 'admin'),
    makeRequest('05. Revoke Permissions Admin', 'DELETE', 'superadmin/admins/1/permissions', null, 'admin'),

    // 3. Manajemen Status Akun Relawan
    makeRequest('06. List Relawan & Status Keaktifan', 'GET', 'superadmin/relawan', null, 'admin'),
    makeRequest('07. Nonaktifkan Akun Relawan (is_active: false)', 'PUT', 'superadmin/relawan/1/updateStatus', [
        'is_active' => false
    ], 'admin'),
    makeRequest('08. Aktifkan Kembali Akun Relawan (is_active: true)', 'PUT', 'superadmin/relawan/1/updateStatus', [
        'is_active' => true
    ], 'admin'),
];

// -------------------------------------------------------------
// HELPER FOR POSTMAN COLLECTION FORMAT
// -------------------------------------------------------------
function makeCollection($name, $items) {
    return [
        'info' => [
            '_postman_id' => bin2hex(random_bytes(16)),
            'name' => $name,
            'description' => "Dokumentasi API lengkap untuk {$name} backend Sahabat SOS, diurutkan dari Login sampai fitur terakhir.",
            'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'
        ],
        'variable' => [
            ['key' => 'base_url', 'value' => 'http://localhost:8000/api', 'type' => 'string'],
            ['key' => 'token', 'value' => '', 'type' => 'string'],
            ['key' => 'relawan_token', 'value' => '', 'type' => 'string'],
            ['key' => 'admin_token', 'value' => '', 'type' => 'string']
        ],
        'item' => $items
    ];
}

// Write Individual Collections
if (!is_dir(__DIR__ . '/../postman')) {
    mkdir(__DIR__ . '/../postman', 0777, true);
}

file_put_contents(
    __DIR__ . '/../postman/1_Pengguna.postman_collection.json',
    json_encode(makeCollection('Sahabat SOS - Role Pengguna', $penggunaItems), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

file_put_contents(
    __DIR__ . '/../postman/2_Relawan.postman_collection.json',
    json_encode(makeCollection('Sahabat SOS - Role Relawan', $relawanItems), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

file_put_contents(
    __DIR__ . '/../postman/3_Admin.postman_collection.json',
    json_encode(makeCollection('Sahabat SOS - Role Admin', $adminItems), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

file_put_contents(
    __DIR__ . '/../postman/4_Superadmin.postman_collection.json',
    json_encode(makeCollection('Sahabat SOS - Role Superadmin', $superadminItems), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

// Write Master Combined Collection
$masterItems = [
    [
        'name' => '1. Role Pengguna (Mobile)',
        'item' => $penggunaItems
    ],
    [
        'name' => '2. Role Relawan (Mobile)',
        'item' => $relawanItems
    ],
    [
        'name' => '3. Role Admin (Dashboard)',
        'item' => $adminItems
    ],
    [
        'name' => '4. Role Superadmin (Dashboard)',
        'item' => $superadminItems
    ]
];

file_put_contents(
    __DIR__ . '/../Sahabat_SOS_API.postman_collection.json',
    json_encode(makeCollection('Sahabat SOS API (Master Collection per Role)', $masterItems), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

echo "Successfully generated all Postman Collections!\n";
