# Rangkuman Perubahan Backend SahabatSOS: Real-Time SOS & Eskalasi Radius Berjenjang

Dokumen ini ditujukan untuk **Tim Backend SahabatSOS** sebagai referensi resmi mengenai pembaruan arsitektur dan logika yang telah diimplementasikan pada repositori `sahabat-sos-backend`. Perubahan ini mencakup standardisasi siklus hidup status SOS, peniadaan delay WebSocket, alur penugasan darurat berbasis radius bertingkat, serta pengetatan otorisasi relawan.

---

## 1. Ringkasan Eksekutif Perubahan

| Komponen | Kondisi Sebelumnya | Kondisi Setelah Perubahan |
| :--- | :--- | :--- |
| **Status SOS** | Terdapat redundansi status `'proses'` dan `'ditangani'` yang memicu inkonsistensi UI/filter. | Dihapus status `'proses'`. Dibakukan menjadi 4 status resmi: `'belum ditangani'`, `'ditangani'`, `'selesai'`, `'batal'`. |
| **Pembaruan Status Real-Time** | Event `SOSUpdateStatus` masuk ke antrean database `jobs` (`ShouldBroadcast`), memicu delay di UI user. | Menggunakan `ShouldBroadcastNow` dan menghapus `->toOthers()` sehingga update status diterima user seketika. |
| **Distribusi Tugas SOS** | Jika relawan di 2 km kosong pada detik ke-0, langsung di-broadcast ke seluruh relawan tanpa filter jarak. | Menggunakan **Tiered Radius Escalation**: 2 km (0s) ➔ 3 km (30s) ➔ 5 km (60s) ➔ 10 km (90s) ➔ Broadcast umum (120s). |
| **Status Ketersediaan Relawan** | Semua akun relawan dapat mengaktifkan status "tersedia" meski belum diverifikasi admin. | Relawan wajib memiliki `status_verifikasi === 'terverifikasi'` untuk dapat mengaktifkan status "tersedia". |

---

## 2. Rincian Perubahan per File

### 1. `app/Events/SOSUpdateStatus.php`
* **Perubahan:** Mengganti interface `ShouldBroadcast` menjadi **`ShouldBroadcastNow`**.
* **Alasan:**
  * Sebelumnya, ketika relawan menekan tombol **"Terima Tugas"**, backend menaruh event `SOSUpdateStatus` ke antrean worker queue database (`jobs`). Hal ini menyebabkan keterlambatan (delay) beberapa detik hingga antrean selesai diproses worker.
  * Dengan `ShouldBroadcastNow`, event langsung didorong ke Laravel Reverb pada saat transaksi controller selesai, tanpa masuk antrean jobs. User langsung melihat status berubah ke `'ditangani'` seketika.

---

### 2. `app/Http/Controllers/Api/SOSController.php`

#### A. Standardisasi Status SOS & Validasi
* Status `'proses'` telah **dihapus sepenuhnya** dari validasi dan query.
* Pada method `updateStatus()`:
  ```php
  $request->validate([
      'status_sos' => 'required|in:belum ditangani,ditangani,selesai,batal',
  ]);
  ```
* Pengecekan saat relawan mengambil tugas:
  ```php
  if ($request->status_sos === 'ditangani') {
      $updated = SOS::where('id', $id)
          ->where('status_sos', 'belum ditangani') // Kunci atomic mencegah perebutan tugas
          ->whereNull('id_relawan')
          ->update([
              'status_sos' => 'ditangani',
              'id_relawan' => $userId,
          ]);
  ...
  ```
* Menghilangkan method chaining `->toOthers()` pada pemanggilan broadcast:
  ```php
  broadcast(new SOSUpdateStatus($sos)); // Tanpa ->toOthers()
  ```
  Hal ini menjamin user korban yang sedang membuka halaman status SOS selalu menerima event pembaruan status tanpa terfilter socket ID.

#### B. Logika Dispatch Awal SOS (`store`)
* **Hanya relawan dalam radius 2.0 km** yang menerima broadcast di detik ke-0:
  ```php
  $volunteers2km = User::where('role', 'relawan')
      ->where('status_ketersediaan', 'tersedia')
      ->nearby($lat, $lng, 2.0)
      ->get();

  $volunteerIds = $volunteers2km->pluck('id')->toArray();

  if ($volunteers2km->isNotEmpty()) {
      broadcast(new SOSCreated($sos, $volunteerIds, 2.0));
  } else {
      Log::info("SOS ID {$sos->id}: Belum ada relawan tersedia dalam radius 2 km. Menunggu eskalasi berkala.");
  }

  // Jadwalkan eskalasi radius 3 km setelah 30 detik
  EscalateSOSJob::dispatch($sos->id, 3.0)->delay(now()->addSeconds(30));
  ```
* **Catatan:** Blok `else` yang sebelumnya langsung menyebarkan broadcast ke seluruh relawan (`relawan-channel`) pada detik ke-0 telah dihapus.

---

### 3. `app/Jobs/EscalateSOSJob.php`

Job ini bertugas mengeksekusi eskalasi jangkauan radius pencarian relawan secara otomatis jika panggilan darurat belum diambil oleh relawan mana pun.

#### Alur Bertingkat (Tiered Escalation Ladder):
1. **Detik 0 (Di Controller):** Radius **2 km** (hanya relawan terdekat).
2. **Detik 30 (Job Radius 3 km):**
   * Pengecekan awal: Jika status bukan lagi `'belum ditangani'` atau `id_relawan != null`, eskalasi dihentikan.
   * Mencari relawan dalam radius **3.0 km** (mengecualikan relawan yang telah menolak melalui `SOSRejection`).
   * Broadcast ke channel privat relawan yang ditemukan.
   * Menjadwalkan eskalasi berikutnya ke radius **5.0 km** dengan delay 30 detik.
3. **Detik 60 (Job Radius 5 km):**
   * Mencari relawan dalam radius **5.0 km**.
   * Menjadwalkan eskalasi berikutnya ke radius **10.0 km** dengan delay 30 detik.
4. **Detik 90 (Job Radius 10 km):**
   * Mencari relawan dalam radius **10.0 km**.
   * Menjadwalkan eskalasi tahap akhir (broadcast umum) dengan delay 30 detik (`targetRadius = 0.0`).
5. **Detik 120 (Tahap Akhir / Broadcast Umum):**
   * Jika sampai radius 10 km belum ada relawan yang mengambil, broadcast dikirim ke channel publik `private-relawan-channel` untuk seluruh relawan siaga.
6. **Penghapusan `->toOthers()`:**
   * Panggilan broadcast di dalam job diubah menjadi `broadcast(new SOSCreated(...))` tanpa `->toOthers()`, karena queue worker berjalan di lingkungan CLI tanpa request socket context.
7. **Pencatatan Audit Trail:**
   * Setiap jenjang eskalasi otomatis mencatat riwayat ke tabel `sos_activities` dengan aksi `sos_eskalasi`.

---

### 4. `app/Http/Controllers/Api/RelawanLocationController.php`
* Pada method `updateLocation()`:
  * Query untuk mengecek apakah relawan sedang menangani SOS aktif disederhanakan:
    ```php
    $activeSos = SOS::where('id_relawan', $user->id)
        ->where('status_sos', 'ditangani')
        ->first();
    ```

---

### 5. `app/Http/Controllers/Api/BerandaRelawanController.php`
* Pada method `updateStatusKetersediaan()`:
  * Menambahkan validasi guard agar relawan yang belum disetujui/diverifikasi oleh admin tidak bisa mengaktifkan status siaga:
    ```php
    if ($request->status_ketersediaan === 'tersedia' && $user->status_verifikasi !== 'terverifikasi') {
        return response()->json([
            'success' => false,
            'message' => 'Akun relawan Anda belum diverifikasi oleh admin. Tidak dapat mengaktifkan status siaga.',
        ], 403);
    }
    ```

---

## 3. Matriks Status SOS Terbaru

Sistem saat ini hanya mengenali 4 status SOS berikut:

| Status | Makna | Siapa yang Mengubah |
| :--- | :--- | :--- |
| `belum ditangani` | Sinyal SOS baru dibuat dan sedang mencari relawan terdekat. | Sistem / User via `POST /api/sos` |
| `ditangani` | Relawan telah menerima tugas dan sedang menuju lokasi korban. | Relawan via `PUT /api/sos/{id}/status` |
| `selesai` | Penanganan darurat telah selesai dilakukan. | Relawan via `PUT /api/sos/{id}/status` |
| `batal` | Panggilan SOS dibatalkan oleh pengguna. | Pengguna via `POST /api/sos/{id}/batal` |

> ⚠️ **Catatan:** Status `'proses'` dan `'aktif'` **tidak lagi digunakan**. Jangan menambahkan nilai status ini ke dalam query baru.

---

## 4. Prasyarat Operasional Server

Untuk memastikan seluruh mekanisme di atas berjalan lancar di server production/staging:

1. **Laravel Reverb:**
   ```bash
   php artisan reverb:start --host=0.0.0.0 --port=8080
   ```
   *Melayani koneksi WebSocket real-time untuk mobile app dan web admin.*

2. **Laravel Queue Worker:**
   ```bash
   php artisan queue:work --tries=3
   ```
   *Wajib aktif untuk mengeksekusi `EscalateSOSJob` tepat waktu sesuai interval delay 30 detik.*
