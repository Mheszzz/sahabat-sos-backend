# Checklist Revisi Pengembangan Sistem

---

## 1. Back End

### A. Penyesuaian Database & Format Data Tabel Kasus Aktif
- [x] **Standardisasi Format Status:** Ubah representasi nilai status menjadi *readable*:
  - `Belum Tertangani`
  - `Ditangani`
  - `Selesai`
- [x] **Pembaruan Struktur Kolom / Respons Tabel:**
  - `ID`
  - `Status`
  - `Kategori`: Dibagi secara eksplisit menjadi 2 klasifikasi:
    - `SOS`
    - `Laporan`
  - `Jenis Laporan`:
    - Jika `Kategori == SOS` $\rightarrow$ set nilai otomatis menjadi `Darurat`.
    - Jika `Kategori == Laporan` $\rightarrow$ sesuaikan dengan kategori yang dipilih (`Butuh Pendamping`, `Kondisi Medis`, `Ancaman / Bahaya`, `Tersesat`, `Aksesibilitas Rusak`, `Lainnya`). Dinamis dari tabel master `kategori_laporans` tanpa hardcode.

### B. Penambahan Endpoint & API
- [x] **Endpoint Penugasan Relawan:** Implementasi endpoint Admin untuk menugaskan relawan ke kasus/laporan (`POST /api/tugas-aktif/{tipe}/{id}/dispatch` dan `POST /api/admin/dashboard/dispatch`).
- [x] **Endpoint Tugas Relawan:** Implementasi endpoint untuk Relawan melihat daftar tugas yang diterima (`GET /api/relawan/tugas`).
- [x] **Endpoint Riwayat (*History*):** Implementasi endpoint riwayat untuk Laporan dan Kasus SOS (`GET /api/relawan/riwayat`, `GET /api/pengguna/riwayat`, dan `GET /api/admin/riwayat-kasus`).
- [x] **Endpoint Manajemen Tugas:** Sediakan 2 pemisahan endpoint untuk tugas:
  - Endpoint Laporan Aktif (`GET /api/tugas-aktif/laporan`)
  - Endpoint Kasus SOS (`GET /api/tugas-aktif/sos`)

---

## 2. Front End Web

### A. Pembersihan Komponen & UI Lama
- [ ] Hapus modul/komponen **Satelit Geospasial GPS Online (Akurasi 3m)**.
- [ ] Hapus modul/komponen **Command Center GIS Online**.
- [ ] Hapus modul/komponen **Live GPS**.

### B. Penyesuaian Komponen & Alur Tindakan
- [ ] **Pembaruan Hubungi Korban / Kontak Darurat / Medis:**  
  Ubah tata letak dan hierarki tombol menjadi urutan berikut:
  1. `Hubungi Korban`
  2. `Hubungi Kontak Darurat`
  3. `Eskalasi Medis`
  4. `Hubungi Ambulans`
- [ ] **Footer:** Perbaiki tata letak dan komponen footer.

### C. Pembaruan Antarmuka (UI) Kasus Aktif
- [ ] Perbarui tabel dan halaman daftar **Kasus Aktif**.
- [ ] Perbarui halaman **Detail Kasus Aktif**:
  - Penyesuaian tipografi (buat teks judul, ID kasus `#29`, status `aktif`, jenis `tersesat`, tanggal/waktu lebih proporsional, besar, dan kontras jelas).
  - Perbaiki konsistensi huruf kapital / *casing*.

### D. Integrasi API (Fetch API)
- [ ] Integrasikan API untuk fitur **Kelola Relawan**.
- [ ] Integrasikan API untuk fitur **Master Data Laporan** (Kategori Laporan Darurat & *Quick Messages* / Pesan Cepat).

---

## 3. Front End Mobile

### A. Halaman Riwayat (*History*)
- [ ] Buat dan tambahkan halaman **History** yang mencakup riwayat Kasus SOS dan Laporan Aktif.

### B. Halaman Tugas
- [ ] Modifikasi halaman **Tugas** agar menampilkan pemisahan daftar:
  - Tab / List **Kasus SOS**
  - Tab / List **Laporan Aktif**

### C. Halaman Beranda (*Home*)
- [ ] Terapkan mekanisme **Pagination** (pembagian halaman / *infinite scroll*) pada bagian riwayat laporan di beranda.