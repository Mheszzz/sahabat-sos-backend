<?php

namespace Tests\Feature;

use App\Events\LaporanCreated;
use App\Models\KategoriLaporan;
use App\Models\Laporan;
use App\Models\PesanCepat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LaporanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed kategori laporan default
        KategoriLaporan::create([
            'id'        => 'bantuan_mobilitas',
            'title'     => 'Bantuan Mobilitas',
            'subtitle'  => 'Pendampingan jalan / kursi roda',
            'icon'      => 'accessible_rounded',
            'color'     => '#00838F',
            'is_active' => true,
        ]);

        KategoriLaporan::create([
            'id'        => 'kondisi_medis',
            'title'     => 'Kondisi Medis',
            'subtitle'  => 'Pertolongan pertama non-darurat',
            'icon'      => 'medical_services_rounded',
            'color'     => '#E53935',
            'is_active' => true,
        ]);

        // Seed pesan cepat template
        PesanCepat::create([
            'pesan'     => 'Butuh bantuan menyeberang jalan',
            'is_active' => true,
        ]);

        PesanCepat::create([
            'pesan'     => 'Saya butuh bantuan mobilitas / kursi roda',
            'is_active' => true,
        ]);
    }

    /**
     * Helper membuat Pengguna
     */
    private function createPengguna(array $attributes = []): User
    {
        static $counter = 1;
        $phone = '0812345678' . str_pad((string) $counter++, 2, '0', STR_PAD_LEFT);

        return User::create(array_merge([
            'role'                => 'pengguna',
            'name'                => 'Budi Santoso',
            'no_telp'             => $phone,
            'kategori_user'       => 'tunanetra',
            'latitude'            => -7.7828,
            'longitude'           => 110.3670,
            'is_active'           => true,
            'status_verifikasi'   => 'terverifikasi',
            'persetujuan_privasi' => true,
        ], $attributes));
    }

    /**
     * Helper membuat Admin Posko
     */
    private function createAdmin(array $permissions = ['kelola_laporan']): User
    {
        static $adminCounter = 1;
        $phone = '0812999988' . str_pad((string) $adminCounter++, 2, '0', STR_PAD_LEFT);

        return User::create([
            'role'                => 'admin',
            'name'                => 'Admin Posko Pusat',
            'no_telp'             => $phone,
            'permissions'         => $permissions,
            'is_active'           => true,
            'status_verifikasi'   => 'terverifikasi',
            'persetujuan_privasi' => true,
        ]);
    }

    /**
     * Helper membuat fake image valid (PNG) tanpa dependensi ekstensi GD
     */
    private function createFakeImageFile(string $filename, int $sizeInKb): UploadedFile
    {
        $pngHeader = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==");
        $padding = max(0, ($sizeInKb * 1024) - strlen($pngHeader));
        $content = $pngHeader . str_repeat('0', $padding);

        return UploadedFile::fake()->createWithContent($filename, $content);
    }

    // =========================================================================
    // TASK-3.1: Buat Laporan dengan Kategori & Template Satu-Klik
    // =========================================================================

    public function test_pengguna_can_get_dynamic_report_options()
    {
        $pengguna = $this->createPengguna();

        $response = $this->actingAs($pengguna)
            ->getJson('/api/laporan/options');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'kategori_laporan',
                'pesan_cepat',
            ]);

        $this->assertNotEmpty($response->json('kategori_laporan'));
        $this->assertContains('Butuh bantuan menyeberang jalan', $response->json('pesan_cepat'));
    }

    public function test_pengguna_can_submit_quick_report_with_template()
    {
        Event::fake([LaporanCreated::class]);

        $pengguna = $this->createPengguna();

        $payload = [
            'kategori_laporan' => 'bantuan_mobilitas',
            'lokasi_laporan'   => 'Jl. Malioboro No. 56, Yogyakarta',
            'latitude'         => -7.7925,
            'longitude'        => 110.3658,
            'pesan_cepat'      => 'Butuh bantuan menyeberang jalan',
        ];

        $response = $this->actingAs($pengguna)
            ->postJson('/api/laporan', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.kategori_laporan', 'bantuan_mobilitas')
            ->assertJsonPath('data.lokasi_laporan', 'Jl. Malioboro No. 56, Yogyakarta')
            ->assertJsonPath('data.status', 'belum ditangani')
            ->assertJsonPath('data.deskripsi', 'Butuh bantuan menyeberang jalan');

        $this->assertDatabaseHas('laporans', [
            'id_pengguna'      => $pengguna->id,
            'kategori_laporan' => 'bantuan_mobilitas',
            'lokasi_laporan'   => 'Jl. Malioboro No. 56, Yogyakarta',
            'status'           => 'belum ditangani',
        ]);

        Event::assertDispatched(LaporanCreated::class);
    }

    public function test_submitted_report_appears_in_user_history()
    {
        $pengguna = $this->createPengguna();

        $laporan = Laporan::create([
            'id_pengguna'      => $pengguna->id,
            'kategori_laporan' => 'bantuan_mobilitas',
            'lokasi_laporan'   => 'Depan Pasar Kranggan',
            'latitude'         => -7.7820,
            'longitude'        => 110.3660,
            'deskripsi'        => 'Butuh bantuan menyeberang jalan',
            'status'           => 'belum ditangani',
            'waktu_laporan'    => now(),
        ]);

        // Cek via endpoint riwayat pengguna mobile
        $responseRiwayat = $this->actingAs($pengguna)
            ->getJson('/api/pengguna/riwayat');

        $responseRiwayat->assertStatus(200)
            ->assertJsonPath('success', true);

        $items = $responseRiwayat->json('data');
        $this->assertNotEmpty($items);
        $this->assertEquals($laporan->id, $items[0]['id']);
        $this->assertEquals('Belum Tertangani', $items[0]['status']);

        // Cek via endpoint list laporan
        $responseList = $this->actingAs($pengguna)
            ->getJson('/api/laporan');

        $responseList->assertStatus(200);
        $this->assertEquals(1, $responseList->json('data.total'));
    }

    // =========================================================================
    // TASK-3.2: Buat Laporan dengan Lampiran Foto (Kamera / Galeri < 5MB)
    // =========================================================================

    public function test_pengguna_can_submit_report_with_photo_under_5mb()
    {
        Storage::fake('public');
        Event::fake([LaporanCreated::class]);

        $pengguna = $this->createPengguna();
        $fakePhoto = $this->createFakeImageFile('bukti_kejadian.png', 2048); // 2 MB

        $payload = [
            'kategori_laporan' => 'kondisi_medis',
            'lokasi_laporan'   => 'Halte Trans Jogja Kaliurang',
            'latitude'         => -7.7680,
            'longitude'        => 110.3800,
            'deskripsi'        => 'Terpeleset di dekat halte, kaki terkilir',
            'foto_laporan'     => $fakePhoto,
        ];

        $response = $this->actingAs($pengguna)
            ->postJson('/api/laporan', $payload);

        $response->assertStatus(201);

        $laporanId = $response->json('data.id');
        $laporan = Laporan::find($laporanId);

        $this->assertNotNull($laporan->foto_laporan);
        Storage::disk('public')->assertExists($laporan->foto_laporan);

        $fotoUrl = $response->json('data.foto_laporan_url');
        $this->assertNotNull($fotoUrl);
        $this->assertStringContainsString('storage/' . $laporan->foto_laporan, $fotoUrl);
    }

    public function test_report_with_photo_exceeding_5mb_is_rejected()
    {
        Storage::fake('public');

        $pengguna = $this->createPengguna();
        // File 6000 KB (> 5120 KB / 5MB)
        $oversizedPhoto = $this->createFakeImageFile('foto_jumbo.png', 6000);

        $payload = [
            'kategori_laporan' => 'kondisi_medis',
            'lokasi_laporan'   => 'Jl. Solo KM 9',
            'deskripsi'        => 'Kecelakaan ringan',
            'foto_laporan'     => $oversizedPhoto,
        ];

        $response = $this->actingAs($pengguna)
            ->postJson('/api/laporan', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['foto_laporan']);
    }

    // =========================================================================
    // TASK-3.3: Buat Laporan dengan Rekaman Suara (Voice Note)
    // =========================================================================

    public function test_pengguna_can_submit_report_with_voice_note()
    {
        Storage::fake('public');
        Event::fake([LaporanCreated::class]);

        $pengguna = $this->createPengguna();
        $fakeAudio = UploadedFile::fake()->create('rekaman_laporan.wav', 800, 'audio/wav'); // 800 KB

        $payload = [
            'kategori_laporan' => 'bantuan_mobilitas',
            'lokasi_laporan'   => 'Stasiun Tugu Pintu Barat',
            'latitude'         => -7.7890,
            'longitude'        => 110.3640,
            'deskripsi'        => 'Suara deskripsi situasi di stasiun',
            'rekam_suara'      => $fakeAudio,
        ];

        $response = $this->actingAs($pengguna)
            ->postJson('/api/laporan', $payload);

        $response->assertStatus(201);

        $laporanId = $response->json('data.id');
        $laporan = Laporan::find($laporanId);

        $this->assertNotNull($laporan->rekam_suara);
        Storage::disk('public')->assertExists($laporan->rekam_suara);

        $audioUrl = $response->json('data.rekam_suara_url');
        $this->assertNotNull($audioUrl);
        $this->assertStringContainsString('storage/' . $laporan->rekam_suara, $audioUrl);
    }

    public function test_pengguna_can_submit_report_with_both_photo_and_audio()
    {
        Storage::fake('public');
        Event::fake([LaporanCreated::class]);

        $pengguna = $this->createPengguna();
        $fakePhoto = $this->createFakeImageFile('tempat_kejadian.png', 1500);
        $fakeAudio = UploadedFile::fake()->create('suara_kejadian.mp3', 600, 'audio/mpeg');

        $payload = [
            'kategori_laporan' => 'kondisi_medis',
            'lokasi_laporan'   => 'Depan Ruko Seturan Square',
            'latitude'         => -7.7650,
            'longitude'        => 110.4080,
            'pesan_cepat'      => 'Saya butuh bantuan mobilitas / kursi roda',
            'foto_laporan'     => $fakePhoto,
            'rekam_suara'      => $fakeAudio,
        ];

        $response = $this->actingAs($pengguna)
            ->postJson('/api/laporan', $payload);

        $response->assertStatus(201);

        $laporan = Laporan::find($response->json('data.id'));
        $this->assertNotNull($laporan->foto_laporan);
        $this->assertNotNull($laporan->rekam_suara);

        Storage::disk('public')->assertExists($laporan->foto_laporan);
        Storage::disk('public')->assertExists($laporan->rekam_suara);

        $this->assertNotNull($response->json('data.foto_laporan_url'));
        $this->assertNotNull($response->json('data.rekam_suara_url'));
    }

    // =========================================================================
    // TASK-3.4: Verifikasi Laporan di Web Admin Posko (Kelola Laporan)
    // =========================================================================

    public function test_admin_with_kelola_laporan_permission_can_verify_reports_in_dashboard()
    {
        $pengguna = $this->createPengguna(['name' => 'Siti Rahma']);
        $admin = $this->createAdmin(['kelola_laporan']);

        $laporan = Laporan::create([
            'id_pengguna'      => $pengguna->id,
            'kategori_laporan' => 'bantuan_mobilitas',
            'lokasi_laporan'   => 'Titik Nol Kilometer Yogyakarta',
            'latitude'         => -7.8012,
            'longitude'        => 110.3650,
            'deskripsi'        => 'Butuh kursi roda darurat',
            'foto_laporan'     => 'laporan/foto/sample_bukti.jpg',
            'rekam_suara'      => 'laporan/audio/sample_audio.wav',
            'status'           => 'belum ditangani',
            'waktu_laporan'    => now(),
        ]);

        // 1. Cek endpoint daftar tugas aktif laporan (/api/tugas-aktif/laporan)
        $responseTable = $this->actingAs($admin)
            ->getJson('/api/tugas-aktif/laporan');

        $responseTable->assertStatus(200)
            ->assertJsonPath('message', 'Berhasil mengambil daftar Laporan Aktif');

        $items = $responseTable->json('data.data');
        $this->assertNotEmpty($items);

        $verifiedItem = collect($items)->firstWhere('id', $laporan->id);
        $this->assertNotNull($verifiedItem);
        $this->assertEquals('Belum Tertangani', $verifiedItem['status']);
        $this->assertEquals('Bantuan Mobilitas', $verifiedItem['jenis_laporan']);
        $this->assertNotNull($verifiedItem['foto_laporan_url']);
        $this->assertNotNull($verifiedItem['rekam_suara_url']);
        $this->assertEquals('Siti Rahma', $verifiedItem['pelapor']['name']);

        // 2. Cek endpoint detail tugas aktif (/api/tugas-aktif/laporan/{id})
        $responseDetail = $this->actingAs($admin)
            ->getJson("/api/tugas-aktif/laporan/{$laporan->id}");

        $responseDetail->assertStatus(200)
            ->assertJsonPath('data.id', $laporan->id)
            ->assertJsonPath('data.jenis_laporan', 'Bantuan Mobilitas')
            ->assertJsonPath('data.foto_laporan_url', url('storage/laporan/foto/sample_bukti.jpg'))
            ->assertJsonPath('data.rekam_suara_url', url('storage/laporan/audio/sample_audio.wav'));
    }

    public function test_admin_without_kelola_laporan_permission_is_blocked()
    {
        // Admin hanya punya izin verifikasi_relawan, tidak punya kelola_laporan
        $adminUnauthorized = $this->createAdmin(['verifikasi_relawan']);

        $response = $this->actingAs($adminUnauthorized)
            ->getJson('/api/tugas-aktif/laporan');

        $response->assertStatus(403);
    }
}
