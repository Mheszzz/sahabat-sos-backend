<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Pendaftaran relawan dengan string URL atau path palsu (bukan file upload) harus ditolak
     */
    public function test_relawan_registration_rejects_string_dokumen_kyc_url(): void
    {
        $payload = [
            'name'                => 'Relawan Penipu',
            'email'               => 'penipu@gmail.com',
            'password'            => 'rahasia123',
            'role'                => 'relawan',
            'no_telp'             => '081299990001',
            'alamat'              => 'Jl. Bahaya No. 1',
            'pekerjaan'           => 'Penyusup',
            'alasan_relawan'      => 'Mau iseng',
            'persetujuan_privasi' => true,
            'dokumen_kyc'         => 'https://evil-phishing-site.com/login',
        ];

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dokumen_kyc']);
    }

    /**
     * 2. Pendaftaran relawan dengan file berbahaya (.php) harus ditolak oleh validasi mimes
     */
    public function test_relawan_registration_rejects_php_file(): void
    {
        Storage::fake('public');

        $phpFile = UploadedFile::fake()->createWithContent('exploit.php', '<?php phpinfo(); ?>');

        $payload = [
            'name'                => 'Relawan Hacker',
            'email'               => 'hacker@gmail.com',
            'password'            => 'rahasia123',
            'role'                => 'relawan',
            'no_telp'             => '081299990002',
            'alamat'              => 'Jl. Bahaya No. 2',
            'pekerjaan'           => 'Penyusup',
            'alasan_relawan'      => 'Testing eksploit',
            'persetujuan_privasi' => true,
            'dokumen_kyc'         => $phpFile,
        ];

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dokumen_kyc']);
    }

    /**
     * 3. Upload foto profil dengan dimensi melebihi batas 4096px (pixel bomb DoS) harus ditolak
     */
    public function test_upload_foto_profile_rejects_oversized_pixel_dimensions(): void
    {
        Storage::fake('public');

        $user = User::create([
            'role'                => 'pengguna',
            'name'                => 'User Test',
            'no_telp'             => '081299990003',
            'status_verifikasi'   => 'terverifikasi',
            'is_active'           => true,
            'persetujuan_privasi' => true,
        ]);

        // Buat file fake image dengan dimensi 5000 x 5000 piksel
        $hugeImage = UploadedFile::fake()->image('bomb.png', 5000, 5000);

        $response = $this->actingAs($user)
            ->postJson('/api/pengguna/profile/foto', [
                'foto_profile' => $hugeImage,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['foto_profile']);
    }

    /**
     * 4. Upload foto profil normal dengan dimensi wajar (< 4096px) harus berhasil
     */
    public function test_upload_foto_profile_valid_succeeds(): void
    {
        Storage::fake('public');

        $user = User::create([
            'role'                => 'pengguna',
            'name'                => 'User Test 2',
            'no_telp'             => '081299990004',
            'status_verifikasi'   => 'terverifikasi',
            'is_active'           => true,
            'persetujuan_privasi' => true,
        ]);

        $validImage = UploadedFile::fake()->image('avatar.jpg', 500, 500);

        $response = $this->actingAs($user)
            ->postJson('/api/pengguna/profile/foto', [
                'foto_profile' => $validImage,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Foto profil berhasil diperbarui.');

        $this->assertNotNull($user->refresh()->foto_profile);
    }

    /**
     * 5. Proxy endpoint /storage-file/{path} menolak akses jika path traversal atau file terlarang
     */
    public function test_storage_file_endpoint_blocks_dangerous_extensions_and_traversal(): void
    {
        Storage::fake('public');

        // Path traversal
        $responseTraversal = $this->get('/api/storage-file/../../.env');
        $responseTraversal->assertStatus(404);

        // Buat file berbahaya di disk public
        Storage::disk('public')->put('malicious.php', '<?php echo "evil"; ?>');

        $responseForbidden = $this->get('/api/storage-file/malicious.php');
        $responseForbidden->assertStatus(403);
    }

    /**
     * 6. Proxy endpoint /storage-file/{path} mengirimkan Security Headers yang benar
     */
    public function test_storage_file_endpoint_returns_security_headers(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put('profile/test.jpg', 'fake-image-bytes');

        $response = $this->get('/api/storage-file/profile/test.jpg');

        $response->assertStatus(200)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}
