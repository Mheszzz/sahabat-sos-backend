# 🔧 Perbaikan & Pengembangan — Sahabat SOS Backend

## Ringkasan Prioritas

| Prioritas | Kategori | Jumlah Item |
|-----------|----------|-------------|
| 🔴 Kritis | Bug & Masalah Teknis | 5 |
| 🟡 Penting | Refactoring & Kualitas Kode | 6 |
| 🟢 Rekomendasi | Pengembangan Fitur Baru | 5 |

---

# 🔴 BAGIAN 1: Bug & Masalah Kritis

## 1.1 Field `catatan_medis` Tidak Ada di Migration

**Masalah:** Di [`User.php`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/app/Models/User.php#L38) model, field `catatan_medis` tercantum di `$fillable` dan digunakan di [`ProfilePenggunaController.php`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/app/Http/Controllers/Api/ProfilePenggunaController.php#L41) serta [`DashboardAdminController.php`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/app/Http/Controllers/Api/DashboardAdminController.php#L108). Namun, field ini **tidak ada di migration mana pun**.

**Dampak:** Jika tabel `users` belum memiliki kolom ini secara manual, maka setiap update profil yang mengisi `catatan_medis` akan **menyebabkan error SQL**.

**Solusi:** Buat migration baru:

```php
// database/migrations/xxxx_xx_xx_add_catatan_medis_to_users_table.php

public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->text('catatan_medis')->nullable()->after('metode_komunikasi');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('catatan_medis');
    });
}
```

---

## 1.2 Dead Code — Middleware `EnsureUserRole` Duplikat

**Masalah:** File [`EnsureUserRole.php`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/app/Http/Middleware/EnsureUserRole.php) memiliki fungsi yang **100% identik** dengan [`RoleMiddleware.php`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/app/Http/Middleware/RoleMiddleware.php). Keduanya menerima variadic `$roles` dan melakukan pengecekan `in_array($user->role, $roles)`.

`EnsureUserRole` **tidak pernah diregistrasi** di [`bootstrap/app.php`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/bootstrap/app.php) dan tidak digunakan di route mana pun.

**Dampak:** Dead code menambah kebingungan bagi developer baru yang bergabung di project.

**Solusi:** Hapus file `EnsureUserRole.php`:

```bash
rm app/Http/Middleware/EnsureUserRole.php
```

---

## 1.3 Route Formatting Rusak di Line 58

**Masalah:** Di [`api.php` line 58](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/routes/api.php#L58), dua statement berada di satu baris:

```php
// SEBELUM (rusak)
    });Route::get('/beranda', [AuthController::class, 'beranda']);
```

**Dampak:** Meskipun PHP tetap berjalan, ini membuat `Route::get('/beranda', ...)` terlihat seperti bagian dari group `role:pengguna,relawan`, padahal sebenarnya berada **di luar group** tersebut. Ini sangat membingungkan saat membaca code.

**Solusi:**

```php
// SESUDAH (benar)
    });

    Route::get('/beranda', [AuthController::class, 'beranda']);
```

---

## 1.4 Komentar "SOS Endpoints" Menggantung

**Masalah:** Di [`api.php` line 45-48](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/routes/api.php#L45-L48), ada komentar `// SOS ENdpoints` yang diikuti baris kosong tanpa isi. Sepertinya dulu SOS routes berada di sini, lalu dipindahkan ke dalam group `role:pengguna` tapi komentarnya tertinggal.

```php
    // SOS ENdpoints   ← komentar menggantung, typo "ENdpoints"
   

```

**Dampak:** Membingungkan pembaca kode. Ada typo "ENdpoints".

**Solusi:** Hapus komentar dan baris kosong tersebut (line 45-48).

---

## 1.5 Broadcast WebSocket Belum Aktif

**Masalah:** Di [`.env`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/.env#L36), `BROADCAST_CONNECTION=log` — artinya semua event SOS dan Laporan hanya **ditulis ke log file**, bukan dikirim melalui WebSocket ke client Flutter/React.

**Dampak:** Fitur real-time (SOS notifikasi ke relawan, update status SOS) **tidak akan bekerja** di environment saat ini.

**Solusi:** Untuk mengaktifkan real-time, ubah `.env`:

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

Dan jalankan Reverb server:

```bash
php artisan reverb:start
```

> [!WARNING]
> Pastikan `.env.example` juga di-update dengan variabel Reverb agar developer lain yang clone project bisa langsung setup.

---

# 🟡 BAGIAN 2: Refactoring & Kualitas Kode

## 2.1 AuthController Terlalu Besar (Fat Controller)

**Masalah:** [`AuthController.php`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/app/Http/Controllers/Api/AuthController.php) memiliki **623 baris** dan menangani **11 method** yang meliputi:

- Registrasi (3 method)
- Login (2 method: user + admin)
- Google OAuth (3 method: redirect, callback, mobile)
- Profil & lokasi (3 method: me, completeProfile, updateLocation)
- Verifikasi relawan (2 method)
- Beranda (2 method: user + admin)
- Logout

**Dampak:** Melanggar **Single Responsibility Principle**. Sulit di-test, sulit di-maintain, dan sulit di-review saat code review.

**Solusi:** Pecah menjadi beberapa controller yang fokus:

```
app/Http/Controllers/Api/
├── Auth/
│   ├── RegisterController.php       ← register, registerPengguna, registerRelawan
│   ├── LoginController.php          ← login, adminLogin
│   ├── GoogleAuthController.php     ← redirectToGoogle, handleGoogleCallback, loginGoogleMobile
│   └── LogoutController.php         ← logout
├── UserController.php               ← me, completeProfile, updateLocation
├── BerandaController.php            ← beranda, berandaAdmin
├── VerifikasiRelawanController.php  ← getPendingRelawan, verifikasiRelawan
└── ... (controller lainnya tetap)
```

Contoh `RegisterController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Services\AuthService;

class RegisterController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    public function register(RegisterRequest $request)
    {
        $result = $this->authService->register($request->validated());
        return response()->json($result, 201);
    }

    public function registerPengguna(RegisterRequest $request)
    {
        $request->merge(['role' => 'pengguna']);
        return $this->register($request);
    }

    public function registerRelawan(RegisterRequest $request)
    {
        $request->merge(['role' => 'relawan']);
        return $this->register($request);
    }
}
```

---

## 2.2 Tidak Ada Form Request Classes

**Masalah:** Semua validasi dilakukan langsung di controller method menggunakan `$request->validate()` atau `Validator::make()`. Contoh di [`AuthController@register`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/app/Http/Controllers/Api/AuthController.php#L359-L376) — 18 baris validasi rules.

**Dampak:**
- Controller menjadi bloated
- Validasi rules tidak bisa di-reuse
- Sulit di-unit test

**Solusi:** Buat Form Request classes untuk setiap endpoint yang memerlukan validasi:

```bash
php artisan make:request RegisterRequest
php artisan make:request LoginRequest
php artisan make:request StoreLaporanRequest
php artisan make:request StoreSOSRequest
php artisan make:request UpdateProfileRequest
```

Contoh `StoreLaporanRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLaporanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // sudah dicek di middleware
    }

    public function rules(): array
    {
        return [
            'kategori_laporan'    => 'required|string|max:255',
            'lokasi_laporan'      => 'required|string|max:255',
            'latitude'            => 'nullable|numeric|between:-90,90',
            'longitude'           => 'nullable|numeric|between:-180,180',
            'radius'              => 'nullable|numeric|min:0.1|max:50',
            'deskripsi'           => 'nullable|string',
            'pesan_cepat'         => 'nullable',
            'keterangan_tambahan' => 'nullable|string',
            'foto_laporan'        => 'nullable|file|image|mimes:jpeg,png,jpg,webp|max:5120',
            'rekam_suara'         => 'nullable|file|mimes:mp3,wav,m4a,aac,ogg,webm,3gp|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'kategori_laporan.required' => 'Kategori laporan wajib dipilih.',
            'lokasi_laporan.required'   => 'Lokasi laporan wajib diisi.',
            'foto_laporan.max'          => 'Ukuran foto maksimal 5MB.',
            'rekam_suara.max'           => 'Ukuran rekaman suara maksimal 10MB.',
        ];
    }
}
```

Lalu di controller:

```php
// SEBELUM
public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        // ... 10+ baris rules
    ]);
    if ($validator->fails()) { ... }
    // ... logic
}

// SESUDAH
public function store(StoreLaporanRequest $request)
{
    // Validasi sudah otomatis dijalankan oleh Laravel
    // Langsung ke business logic
}
```

---

## 2.3 Tidak Ada API Resource Classes

**Masalah:** Response JSON di-craft manual di setiap controller. Contoh, format user data berbeda-beda di berbagai endpoint:

- [`AuthController@me`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/app/Http/Controllers/Api/AuthController.php#L240-L244): mengembalikan `$user` langsung
- [`ProfilePenggunaController@show`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/app/Http/Controllers/Api/ProfilePenggunaController.php#L23-L44): men-craft response manual dengan nested `aksesibilitas`
- [`DashboardAdminController`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/app/Http/Controllers/Api/DashboardAdminController.php#L103-L109): format profil korban yang lagi berbeda

**Dampak:** Inkonsistensi format JSON antara endpoint. Frontend harus meng-handle berbagai bentuk data user.

**Solusi:** Buat API Resource classes:

```bash
php artisan make:resource UserResource
php artisan make:resource SOSResource
php artisan make:resource LaporanResource
php artisan make:resource KontakDaruratResource
```

Contoh `UserResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'email'             => $this->accounts()->first()?->email,
            'no_telp'           => $this->no_telp,
            'alamat'            => $this->alamat,
            'role'              => $this->role,
            'kategori_user'     => $this->kategori_user,
            'metode_komunikasi' => $this->metode_komunikasi ?? 'chat',
            'foto_profile'      => $this->foto_profile,
            'foto_profile_url'  => $this->foto_profile_url,
            'aksesibilitas'     => [
                'talkback'       => (bool) $this->talkback,
                'text_besar'     => (bool) $this->text_besar,
                'getaran'        => (bool) $this->getaran,
                'kontras_tinggi' => (bool) $this->kontras_tinggi,
                'panduan_suara'  => (bool) $this->panduan_suara,
            ],
            'catatan_medis'      => $this->catatan_medis,
            'status_verifikasi'  => $this->status_verifikasi,
            'is_profile_complete'=> $this->isProfileComplete(),
            'lokasi'             => [
                'latitude'   => $this->latitude,
                'longitude'  => $this->longitude,
                'alamat'     => $this->lokasi_user,
                'updated_at' => $this->last_located_at?->toIso8601String(),
            ],
        ];
    }
}
```

Penggunaan:

```php
// SEBELUM (manual)
return response()->json([
    'user' => $user,
    'is_profile_complete' => $user->isProfileComplete(),
]);

// SESUDAH (konsisten)
return response()->json([
    'data' => new UserResource($user),
]);
```

---

## 2.4 Tidak Ada Service Layer

**Masalah:** Seluruh business logic berada langsung di controller. Contoh [`SOSController@store`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/app/Http/Controllers/Api/SOSController.php#L16-L70) — method ini melakukan:
1. Validasi
2. Cek SOS aktif
3. Simpan ke DB
4. Cari relawan terdekat
5. Broadcast event
6. Dispatch eskalasi job

Semua dalam **1 method** di controller.

**Dampak:**
- Tidak bisa di-unit test tanpa HTTP request
- Logic tidak bisa di-reuse (misalnya jika nanti ada SOS dari hardware/IoT device)
- Sulit di-debug

**Solusi:** Buat Service layer:

```
app/
├── Services/
│   ├── AuthService.php
│   ├── SOSService.php
│   ├── LaporanService.php
│   └── UserService.php
```

Contoh `SOSService.php`:

```php
<?php

namespace App\Services;

use App\Events\SOSCreated;
use App\Jobs\EscalateSOSJob;
use App\Models\SOS;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SOSService
{
    /**
     * Cek apakah user masih punya SOS aktif.
     */
    public function hasActiveSOS(int $userId): bool
    {
        return SOS::where('id_pengguna', $userId)
            ->whereIn('status_sos', ['aktif', 'proses'])
            ->exists();
    }

    /**
     * Buat SOS baru dan broadcast ke relawan terdekat.
     */
    public function triggerSOS(int $userId, float $lat, float $lng): SOS
    {
        $sos = SOS::create([
            'id_pengguna' => $userId,
            'latitude'    => $lat,
            'longitude'   => $lng,
            'status_sos'  => 'aktif',
            'waktu_sos'   => now(),
        ]);

        $this->notifyNearbyVolunteers($sos, $lat, $lng);

        return $sos;
    }

    /**
     * Notifikasi relawan terdekat dengan strategi eskalasi 2 tahap.
     */
    private function notifyNearbyVolunteers(SOS $sos, float $lat, float $lng): void
    {
        $nearestVolunteer = User::where('role', 'relawan')
            ->nearby($lat, $lng, 1.0)
            ->first();

        if ($nearestVolunteer) {
            Log::info("SOS #{$sos->id}: Relawan ditemukan < 1km (User #{$nearestVolunteer->id})");
            broadcast(new SOSCreated($sos, $nearestVolunteer->id))->toOthers();
            EscalateSOSJob::dispatch($sos->id)->delay(now()->addSeconds(30));
        } else {
            Log::info("SOS #{$sos->id}: Tidak ada relawan < 1km → eskalasi langsung ke 3km");
            EscalateSOSJob::dispatchSync($sos->id);
        }
    }
}
```

Lalu controller menjadi sangat ringkas:

```php
class SOSController extends Controller
{
    public function __construct(
        private SOSService $sosService
    ) {}

    public function store(StoreSOSRequest $request)
    {
        $userId = $request->user()->id;

        if ($this->sosService->hasActiveSOS($userId)) {
            return response()->json([
                'message' => 'Anda masih memiliki sinyal SOS aktif.'
            ], 422);
        }

        $sos = $this->sosService->triggerSOS(
            $userId,
            (float) $request->latitude,
            (float) $request->longitude
        );

        return response()->json([
            'message' => 'Sinyal SOS berhasil dikirim.',
            'data'    => new SOSResource($sos),
        ], 201);
    }
}
```

---

## 2.5 Naming Convention Inkonsisten

**Masalah:**

| Item | Saat Ini | Konvensi Laravel |
|------|----------|-----------------|
| Model | `Kontak_darurat` | Seharusnya `KontakDarurat` (PascalCase, tanpa underscore) |
| Table | `s_o_s` | Terlihat aneh (auto-generated dari model `SOS`). Lebih baik `sos_signals` atau custom table name |
| Table | `kontak_darurats` | Pluralisasi bahasa Indonesia yang aneh |
| Table | `pesan_cepats` | Sama, pluralisasi yang aneh |
| Table | `laporans` | Sama |

**Solusi:**

1. **Rename model** `Kontak_darurat` → `KontakDarurat` (dan rename file dari `Kontak_darurat.php` ke `KontakDarurat.php`):

```php
// app/Models/KontakDarurat.php
class KontakDarurat extends Model
{
    protected $table = 'kontak_darurats'; // tetap pakai table name yg ada
}
```

2. Untuk tabel, karena sudah ada data di production, **jangan rename tabel** — cukup set `$table` property di model secara eksplisit (yang sudah dilakukan). Ini hanya perlu diperhatikan untuk proyek baru.

---

## 2.6 Validasi Duplikat dengan Alias Field di ProfilePenggunaController

**Masalah:** Di [`ProfilePenggunaController@update`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/app/Http/Controllers/Api/ProfilePenggunaController.php#L54-L72), ada banyak field alias yang di-validate dua kali:

```php
'name'                      => 'nullable|string|max:255',
'nama'                      => 'nullable|string|max:255',      // alias

'kategori_user'             => 'nullable|in:...',
'kategori'                  => 'nullable|in:...',               // alias

'metode_komunikasi'         => 'nullable|string|in:...',
'metode_komunikasi_pilihan' => 'nullable|string|in:...',        // alias

'text_besar'                => 'nullable|boolean',
'ukuran_teks'               => 'nullable|boolean',              // alias

'kontras_tinggi'            => 'nullable|boolean',
'mode_kontras_sangat_tinggi'=> 'nullable|boolean',              // alias
```

**Dampak:** Controller menjadi sangat panjang (207 baris) karena harus handle setiap alias secara manual. Frontend developer akan bingung field mana yang "resmi".

**Solusi:** Pilih **satu nama field resmi**, dan gunakan Laravel `prepareForValidation()` di Form Request untuk mapping alias:

```php
class UpdateProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Mapping alias ke field resmi
        $mappings = [
            'nama'                       => 'name',
            'kategori'                   => 'kategori_user',
            'metode_komunikasi_pilihan'  => 'metode_komunikasi',
            'ukuran_teks'                => 'text_besar',
            'mode_kontras_sangat_tinggi' => 'kontras_tinggi',
        ];

        foreach ($mappings as $alias => $official) {
            if ($this->has($alias) && !$this->has($official)) {
                $this->merge([$official => $this->input($alias)]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name'               => 'nullable|string|max:255',
            'kategori_user'      => 'nullable|in:umum,tunanetra,tunarungu,tunawicara',
            'metode_komunikasi'  => 'nullable|string|in:chat,pesan_suara_audio,keduanya',
            'text_besar'         => 'nullable|boolean',
            'kontras_tinggi'     => 'nullable|boolean',
            // ... hanya field resmi
        ];
    }
}
```

---

# 🟢 BAGIAN 3: Pengembangan Fitur Baru

## 3.1 API Versioning

**Saat Ini:** Semua route berada di `/api/...` tanpa versioning.

**Masalah:** Jika ada breaking change di API, semua client (Flutter app yang sudah di-publish di Play Store) akan langsung terdampak.

**Solusi:** Tambahkan prefix version:

```php
// routes/api.php → routes/api_v1.php

// bootstrap/app.php
->withRouting(
    api: __DIR__.'/../routes/api_v1.php',
    apiPrefix: 'api/v1',
    // ...
)
```

Semua endpoint menjadi `/api/v1/auth/login`, `/api/v1/sos/trigger`, dll.

---

## 3.2 Rate Limiting

**Saat Ini:** Tidak ada rate limiting di endpoint mana pun.

**Masalah:** Endpoint seperti `/api/sos/trigger` dan `/api/auth/login` rawan di-abuse. Seorang user bisa trigger SOS berkali-kali atau brute-force login.

**Solusi:** Tambahkan di `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'role'       => \App\Http\Middleware\RoleMiddleware::class,
        'permission' => \App\Http\Middleware\AdminPermissionMiddleware::class,
    ]);

    // Rate limiting
    $middleware->throttle('api', 60); // 60 requests/menit untuk API umum
})
```

Dan di `AppServiceProvider.php`:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('auth', function ($request) {
        return Limit::perMinute(5)->by($request->ip()); // Login max 5x/menit
    });

    RateLimiter::for('sos', function ($request) {
        return Limit::perMinute(3)->by($request->user()?->id ?: $request->ip());
    });
}
```

Lalu di routes:

```php
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('/sos/trigger', [SOSController::class, 'store'])->middleware('throttle:sos');
```

---

## 3.3 Logging & Audit Trail

**Saat Ini:** Logging hanya ada di `SOSController` (menggunakan `Log::info`).

**Masalah:** Tidak ada audit trail untuk aksi-aksi kritis seperti:
- Admin memverifikasi/menolak relawan
- Superadmin mengubah permissions admin
- Admin menugaskan relawan ke SOS (dispatch)

**Solusi:** Buat tabel `audit_logs` dan model:

```php
// Migration
Schema::create('audit_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained('users');
    $table->string('action');           // 'verifikasi_relawan', 'dispatch_sos', dll
    $table->string('target_type');      // 'App\Models\User', 'App\Models\SOS'
    $table->unsignedBigInteger('target_id');
    $table->json('old_values')->nullable();
    $table->json('new_values')->nullable();
    $table->string('ip_address')->nullable();
    $table->timestamps();
});
```

---

## 3.4 Unit & Feature Testing

**Saat Ini:** Folder `tests/` ada tapi kemungkinan besar masih default Laravel (belum ada custom test).

**Masalah:** Tidak ada automated test. Setiap perubahan kode berpotensi memperkenalkan bug tanpa terdeteksi.

**Solusi:** Tambahkan Feature Test minimal untuk flow kritis:

```php
// tests/Feature/SOSTest.php
class SOSTest extends TestCase
{
    use RefreshDatabase;

    public function test_pengguna_can_trigger_sos()
    {
        $user = User::factory()->create(['role' => 'pengguna']);

        $response = $this->actingAs($user)
            ->postJson('/api/sos/trigger', [
                'latitude'  => -6.2088,
                'longitude' => 106.8456,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'data' => ['id', 'status_sos']]);

        $this->assertDatabaseHas('s_o_s', [
            'id_pengguna' => $user->id,
            'status_sos'  => 'aktif',
        ]);
    }

    public function test_pengguna_cannot_trigger_duplicate_sos()
    {
        $user = User::factory()->create(['role' => 'pengguna']);
        SOS::factory()->create([
            'id_pengguna' => $user->id,
            'status_sos'  => 'aktif',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/sos/trigger', [
                'latitude'  => -6.2088,
                'longitude' => 106.8456,
            ]);

        $response->assertStatus(422);
    }

    public function test_relawan_can_accept_sos()
    {
        $pengguna = User::factory()->create(['role' => 'pengguna']);
        $relawan = User::factory()->create(['role' => 'relawan']);
        $sos = SOS::factory()->create([
            'id_pengguna' => $pengguna->id,
            'status_sos'  => 'aktif',
        ]);

        $response = $this->actingAs($relawan)
            ->patchJson("/api/sos/{$sos->id}/status", [
                'status_sos' => 'proses',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('s_o_s', [
            'id'         => $sos->id,
            'status_sos' => 'proses',
            'id_relawan' => $relawan->id,
        ]);
    }
}
```

---

## 3.5 API Documentation (Swagger/OpenAPI)

**Saat Ini:** Dokumentasi API hanya ada di file Postman collection ([`Sahabat_SOS_API.postman_collection.json`](file:///c:/KULIAH/MAGANG/SAHABAT-SOS/sahabat-sos-backend/Sahabat_SOS_API.postman_collection.json)).

**Masalah:** Postman collection tidak bisa di-render sebagai dokumentasi interaktif dan tidak ter-sinkronisasi otomatis dengan kode.

**Solusi:** Install `l5-swagger` atau `scramble` untuk auto-generate:

```bash
composer require dedoc/scramble
```

Scramble akan otomatis scan route dan controller untuk menghasilkan OpenAPI docs di `/docs/api`.

---

# 📋 Prioritas Implementasi yang Disarankan

| Fase | Item | Estimasi Effort |
|------|------|-----------------|
| **Fase 1** (Segera) | 1.1 Migration `catatan_medis` | 10 menit |
| | 1.2 Hapus `EnsureUserRole.php` | 5 menit |
| | 1.3 Fix formatting line 58 | 5 menit |
| | 1.4 Hapus komentar menggantung | 5 menit |
| **Fase 2** (Minggu ini) | 1.5 Aktifkan Reverb WebSocket | 1 jam |
| | 2.5 Rename model `Kontak_darurat` | 30 menit |
| | 3.2 Rate Limiting | 1 jam |
| **Fase 3** (Sprint berikutnya) | 2.2 Form Request classes | 3–4 jam |
| | 2.3 API Resource classes | 3–4 jam |
| | 2.6 Cleanup alias validation | 1 jam |
| **Fase 4** (Refactoring besar) | 2.1 Pecah AuthController | 4–6 jam |
| | 2.4 Service Layer | 6–8 jam |
| | 3.4 Unit Testing | 4–6 jam |
| **Fase 5** (Nice-to-have) | 3.1 API Versioning | 2 jam |
| | 3.3 Audit Trail | 3 jam |
| | 3.5 API Documentation | 1 jam |
