# Dokumentasi Dashboard: Peta Pemantauan - Sahabat SOS

Platform ini merupakan antarmuka *Command Center* untuk sistem tanggap darurat inklusif bagi penyandang disabilitas ("Sahabat SOS - Platform Inklusi & Tanggap Darurat Difabel Mandiri") berbasis pemantauan spasial (GIS) dan perangkat IoT/ESP32 secara *real-time*.

---

## 1. Navigasi & Status Sistem Utama (Header)
* **Breadcrumb:** `Command Center > Peta Wilayah & Pemantauan`.
* **Koneksi Server:** Status **GIS Server Aktif • Realtime Live GPS** dengan frekuensi sinkronisasi terakhir **2 Detik Lalu**.
* **Mode Tampilan:** Pilihan layer **Peta Vektor** (aktif) dan **Satelit**, serta tombol pintas **Filter Layer**.
* **Pencarian & Lingkup Area:**
  * Bilah pencarian global (*Cari ID Kasus, Relawan, atau Lokasi...*).
  * Pemilihan **Radius Pantau**: Diatur pada wilayah *Jakarta Pusat (5 km)*.
* **Filter Kasus & Personel Aktif:**
  * **Darurat SOS (3 Titik)** (Warna Merah)
  * **Laporan Pengguna (2 Laporan)** (Warna Kuning/Oranye)
  * **Relawan Bertugas (4 Personel)** (Warna Hijau Tua)
  * **Relawan Siaga (18 Personel)** (Warna Toska/Hijau Muda)

---

## 2. Area Peta Spasial (GIS Map)
Menampilkan peta interaktif di sekitar kawasan Jalan Jenderal Sudirman / Taman Monas & Merdeka Barat lengkap dengan kontrol zoom dan penanda lokasi.

### Detail Kasus Aktif (Pop-up `#SOS-8921`)
* **Status:** `#SOS-8921 • BUTUH BANTUAN` (Terdeteksi *1 menit lalu*).
* **Identitas Pelapor:** 
  * **Nama:** Ahmad Fauzi
  * **Kategori Disabilitas:** Tunanetra
  * **Kondisi/Masalah:** Mengalami disorientasi arah saat hendak menuju bus koridor 1.
* **Tindakan Lapangan:** 
  * Relawan yang merespons: **Rian H.** (*Unit Reaksi Cepat • Motor*, jarak 250m).
  * Tombol aksi cepat: **Kanal Radio** untuk komunikasi langsung.

### Legenda Pemantauan
* 🔴 **Merah:** Pelapor Darurat SOS
* 🟡 **Kuning/Oranye:** Pelapor Laporan Pengguna
* 🟢 **Hijau Tua:** Relawan Bertugas
* 🟢 **Hijau Muda / Toska:** Relawan Siaga

---

## 3. Panel Kesiapsiagaan & Manajemen Relawan (Sidebar Kanan)
Memuat ringkasan jumlah dan ketersediaan dari total **22 Personel**:
* **Siaga Bebas:** 14 personel
* **Dalam Misi:** 6 personel
* **Off / Istirahat:** 2 personel

### Daftar Relawan & Penugasan
1. **Rian Hidayat** *(Sedang Bertugas)*
   * **Unit:** Unit Reaksi Cepat • Motor
   * **Tujuan Misi:** `#SOS-8921 (Halte Tosari)`
   * **Estimasi & Jarak:** 250 meter (± 2 menit)
   * **Telemetri Perangkat:** Baterai ESP32: 94% | Akurasi GPS: ±3m
2. **Doni Prasetyo** *(Siaga Bebas)*
   * **Keahlian:** Tunanetra & Fisik
   * **Lokasi:** Posko Sarinah (800m)
   * Opsi: *Tugaskan Langsung →*
3. **Bambang Irawan** *(Siaga Bebas)*
   * **Keahlian:** Relawan Lapangan & Evakuasi
   * **Lokasi:** Posko Menteng (1.2 km)
   * Opsi: *Tugaskan Langsung →*
4. **Farida Nur, S.Hum** *(Online Tele-JBI)*
   * **Peran:** JBI (Juru Bahasa Isyarat) Siaga Video Call Darurat
   * Status perangkat: *Kamera Siap Terhubung* | Opsi: *Panggil JBI*
5. **Hendra Kurniawan** *(Siaga Bebas)*
   * **Keahlian:** Pendampingan Navigasi Medis
   * **Lokasi:** Posko Harmoni (1.8 km dari TKP)

### Tombol Aksi Cepat
* **Broadcast Siaga Radius Terdekat (Push SOS):** Mengirimkan sinyal notifikasi darurat secara serentak ke seluruh relawan terdekat dalam radius insiden.

---

## 4. Status Perangkat & Keamanan (Footer)
* **Status Gateway IoT / ESP32:** IP `103.144.12.82` (*Tersambung*).
* **Standar Keamanan:** Enkripsi data end-to-end `TLS 1.3 / AES-256`.
* **Hak Cipta:** © 2025 Sahabat SOS - Platform Inklusi & Tanggap Darurat Difabel Mandiri.
```[cite: 1]