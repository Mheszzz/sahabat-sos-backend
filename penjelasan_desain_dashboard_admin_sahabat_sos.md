# Dokumentasi & Analisis Desain: Dashboard Admin "Sahabat SOS"
**Sistem:** Admin Command Center — Tanggap Darurat & Pendampingan Difabel Mandiri  
**Tipe UI:** Web-based Mission-Critical Emergency Dispatch & Monitoring Dashboard  

---

## 1. Ringkasan Eksekutif & Tujuan Produk

Dashboard **Sahabat SOS (Admin Command)** dirancang sebagai pusat komando operasional (*incident command center*) terpadu untuk memantau, merespons, dan mengoordinasikan bantuan darurat bagi penyandang disabilitas (tunanetra, tunarungu/wicara, dan disabilitas fisik/motorik).

Desain ini mengutamakan **kecepatan aksi (*speed-to-dispatch*)**, **visibilitas situasi real-time (situational awareness)**, serta **keterbacaan informasi kritis** di bawah tekanan waktu darurat.

---

## 2. Struktur Tata Letak (Layout & Information Architecture)

Dashboard menggunakan tata letak berbasis kisi (*grid layout*) responsif yang membagi layar ke dalam area fungsional yang teratur:

```
+---------------------------------------------------------------------------------------+
| Header: Breadcrumbs | Global Search (⌘K) | Notifikasi | Kontrol Sirine Audio          |
+---------+-----------------------------------------------------------------------------+
| Sidebar | KPI Metric Cards (Panggilan SOS, Laporan, Darurat Aktif, Relawan Siaga)     |
| Nav     +-----------------------------------------+-----------------------------------+
|         | Daftar Kasus Darurat & Laporan Aktif    | Peta GIS Interaktif (Live Tracking)|
|         | (Live Incident Cards & Status Relawan)  | Quick Dispatch Relawan Terdekat   |
|         +-----------------------------------------+-----------------------------------+
|         | Analisis: Kebutuhan Jenis Difabel       | Analisis: Kategori Masalah/Laporan|
+---------+-----------------------------------------------------------------------------+
| Footer: Hak Cipta | Link SOP Relawan | Protokol Enkripsi | Bantuan Teknis POSKO        |
+---------------------------------------------------------------------------------------+
```

### A. Sidebar Navigasi (Kiri)
* **Branding:** Identitas "Sahabat SOS - ADMIN COMMAND" dengan ikon pelindung/hati.
* **Menu Utama:**
  1. *Dashboard Utama* (Halaman aktif).
  2. *Kasus Aktif* (Dilengkapi badge counter darurat `[6]` berwarna merah cerah).
  3. *Riwayat Kasus* (Arsip dan pelaporan historis).
  4. *Peta Pemantauan* (Tampilan GIS full-screen).
  5. *Pengaturan Sistem* (Konfigurasi perangkat keras, integrasi API, perizinan).
* **Footer Sidebar:** Profil operator yang sedang bertugas (*Dimas Wicaksono - Admin*) beserta status dan menu opsi akun.

### B. Header / Top Navigation Bar
* **Navigasi Hirarkis:** Breadcrumb *Command Center > Dashboard Utama*.
* **Global Search:** Dilengkapi shortcut keyboard `[⌘K]` untuk pencarian cepat ID kasus, nama pelapor, atau personel relawan.
* **Fitur Kritis "Sirine On":** Toggle alarm suara darurat untuk memastikan operator langsung siaga saat ada sinyal SOS masuk tanpa harus terus menatap layar.

---

## 3. Komponen Utama Dashboard

### 1. Kartu Metrik KPI (Top Stat Cards)
Memberikan rangkuman cepat kondisi operasional hari berjalan:
* **Panggilan SOS Hari Ini:** `48` (indikator tren naik `+12%`).
* **Jumlah Laporan Hari Ini:** `15` (indikator tren naik `+87%`).
* **Darurat SOS Aktif:** `6` (Diberi aksen border merah peringatan tebal, membedakan status hidup/kritis dari metrik biasa).
* **Relawan Siaga Aktif:** `142 Personel Lapangan` (dirinci: 89 Siaga, 53 Sedang Bertugas).

### 2. Antrean Kasus Darurat (Incident Live Queue)
Menyajikan kartu kasus yang masuk secara kronologis dan berkode warna urgensi:
* **Identitas Kasus & Sinyal:**
  * Tag ID Kasus (contoh: `#SOS-8921`).
  * Asal Sinyal Darurat (misal: *Tombol Hardware SOS Aktif* dari perangkat IoT khusus).
  * Waktu relatif (*2 menit yang lalu*).
* **Detail Kejadian & Lokasi:** Judul insiden tebal, alamat presisi (contoh: *Halte Menara Astra*), dan koordinat GPS (*Lat/Long*).
* **Profil Korban Berdasarkan Inklusi:** Mencantumkan jenis disabilitas (misal: *Tunanetra*, *Disabilitas Fisik*) beserta status kontak darurat keluarga.
* **Alokasi Relawan & Integrasi Layanan:** Menampilkan nama relawan yang ditugaskan, estimasi waktu tiba (*ETA: ~4 menit*), serta integrasi instansi terkait (*Ambulans 118 Dipanggil*, koordinasi petugas TransJakarta).
* **Tombol Aksi Cepat:** `Hubungi Relawan`, `Tandai Selesai`, dan `Lihat Detail Kasus →`.

### 3. Peta Pantauan GIS & Quick Dispatch (Kanan Atas)
* **Live GIS Map:** Visualisasi spasial titik kejadian darurat (merah), posisi relawan yang sedang bergerak (oranye/hijau), dan Posko Sahabat SOS (biru).
* **Modul Relawan Siap Beroperasi (Terdekat dari TKP):**
  * Menampilkan relawan dengan kompetensi spesifik (misal: *Juru Bahasa Isyarat / JBI*, *Navigasi Tunanetra & P3K*, *Pendamping Mobilitas & Kursi Roda*).
  * Menghitung jarak relatif ke titik insiden (*350 m*, *620 m*, *1.1 km*).
  * Tombol aksi instan **`Tugaskan`** untuk mempercepat proses *dispatch* dalam satu klik.

### 4. Modul Statistik & Analisis Kebutuhan (Bagian Bawah)
* **Sebaran Kategori Kebutuhan Difabel:**
  * *Disabilitas Netra (Tunanetra):* 45% (185 kasus).
  * *Disabilitas Rungu & Wicara (Tunarungu):* 30% (124 kasus).
  * *Disabilitas Fisik / Motorik (Kursi Roda):* 25% (103 kasus).
  * Metrik performa: *Tingkat keberhasilan bantuan tepat waktu: 98.1%*.
* **Persentase Kategori Laporan:** Menampilkan diagram batang horizontal untuk klasifikasi masalah utama: Ancaman Bahaya (30%), Aksesibilitas Rusak (11%), Kondisi Medis (9%), Butuh Panduan (5%), Tersesat (5%), dan Lainnya.

---

## 4. Analisis Sistem Desain & Visual (Design System)

| Elemen | Karakteristik & Implementasi |
| :--- | :--- |
| **Palet Warna Primer** | *Deep Forest Green / Teal* (`#0D4B3E` / `#053A2F`) mencerminkan stabilitas, ketenangan, dan rasa aman. |
| **Warna Aksen Peringatan** | *Signal Red* (`#E03131`) untuk status bahaya/SOS aktif, *Amber Yellow* untuk status menunggu penugasan, dan *Mint Green* untuk status terkendali/selesai. |
| **Tipografi** | Sans-serif modern berbobot medium hingga bold pada angka/ID kasus untuk keterbacaan tinggi di berbagai resolusi layar. |
| **Prinsip Ergonomi UX** | Mengurangi beban kognitif operator (*cognitive load*) dengan mengelompokkan data kompleks ke dalam kartu modular dengan label status yang tegas. |
| **Aksesibilitas Khusus** | Dilengkapi tag jenis disabilitas yang langsung menginstruksikan operator mengenai protokol penanganan khusus yang diperlukan korban. |

---

## 5. Kelebihan Desain & Rekomendasi Pengembangan

### Kelebihan Utama:
1. **Kontekstual Disabilitas:** Tidak hanya mencatat kejadian bencana/kecelakaan, tetapi secara spesifik mencantumkan profil ragam disabilitas pelapor dan kualifikasi relawan pendamping yang sesuai (seperti JBI dan pemandu mobilitas).
2. **Keterhubungan Data (Triangulasi Cepat):** Menghubungkan pelapor, relawan terdekat, dan layanan publik (TransJakarta, Ambulans 118) dalam satu kartu ringkas.
3. **Pemberian Prioritas Visual:** Kasus paling kritis memiliki pembeda warna yang kentara sehingga operator tidak akan melewatkan laporan darurat baru.

### Rekomendasi Pengembangan (Iterasi Selanjutnya):
* **Fitur Filter Cepat:** Menambahkan filter cepat berdasarkan sektor wilayah kota atau jenis disabilitas pada tabel antrean.
* **Integrasi Live Chat / Voice-to-Text:** Fitur transkripsi otomatis untuk mempermudah komunikasi dengan pelapor tunarungu secara real-time langsung dari panel admin.
* **Indikator Cuaca & Lalu Lintas:** Integrasi layer rute macet pada peta GIS untuk kalkulasi ETA relawan yang lebih presisi.