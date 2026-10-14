# Konfigurasi Sertifikat Android (Debug Keystore)

Dokumen ini memuat informasi *fingerprint* sertifikat Android dari `debug.keystore` yang digunakan oleh tim pengembang pada proyek **Sahabat SOS**. Informasi ini dibutuhkan saat melakukan integrasi layanan Google (seperti *Google Sign-In* dan *Google Maps*) atau *Firebase*.

---

## 🔑 Daftar SHA Fingerprints Pengembang

Karena setiap perangkat komputer/laptop pengembang memiliki file `debug.keystore` yang berbeda, seluruh SHA-1 di bawah ini **wajib didaftarkan** di **Google Cloud Console** atau **Firebase Console** agar semua pengembang dapat menjalankan dan menguji fitur *Google Sign-In* tanpa kendala.

### 1. Developer: Mahes
* **Lokasi Keystore**: `C:\Users\mahes\.android\debug.keystore`
* **Alias**: `androiddebugkey`
* **Masa Berlaku**: Hingga 29 September 2056

| Tipe | Nilai |
| :--- | :--- |
| **SHA-1** | `DD:E3:4B:6E:78:00:F2:9D:D3:9E:18:EB:1F:86:BC:7D:B1:70:43:20` |
| **SHA-256** | `C7:06:94:B6:4F:27:BE:65:03:12:70:0C:B2:82:DF:6A:AA:88:8E:A0:9C:9D:7F:31:0B:BD:23:DF:50:22:83:91` |
| **MD5** | `C9:71:CB:E9:7C:14:A3:CE:6D:EF:B2:90:1D:A5:9A:35` |

---

### 2. Developer: Muha (Perangkat Ini)
* **Lokasi Keystore**: `C:\Users\acer\.android\debug.keystore`
* **Alias**: `androiddebugkey`
* **Masa Berlaku**: Hingga 3 Oktober 2055

| Tipe | Nilai |
| :--- | :--- |
| **SHA-1** | `F2:5E:93:A6:D3:BE:28:F8:DD:5A:13:F3:8F:23:B9:62:E4:8B:3D:B4` |
| **SHA-256** | `8D:03:53:12:89:8E:F8:FD:FF:93:09:B1:7C:81:64:65:71:08:16:AE:F7:5E:E1:7C:39:9D:DC:B0:07:C8:32:5B` |

---

## 📋 Detail Konfigurasi Aplikasi

* **Package Name / Application ID**: `com.sossahabat.app`
* **Android Client ID**: `757209543690-jpg1blpas12drdam7leck0ha4pk7sluo.apps.googleusercontent.com`
* **Web Client ID (Server Client ID)**: `757209543690-bpjk959q2se7oq0eos1olm6urg35oicp.apps.googleusercontent.com`

---

> **📝 Catatan Penting Pendaftaran Google / Firebase:**
> 1. Buka **Google Cloud Console** (bagian *Credentials* -> *OAuth 2.0 Client IDs*) atau **Firebase Console** (Project Settings -> General -> Your apps -> *Add fingerprint*).
> 2. Pastikan kedua nilai **SHA-1** (milik Mahes dan Muha) sudah terdaftar di bawah Package Name `com.sossahabat.app`.
> 3. Dengan mendaftarkan kedua SHA-1 tersebut, semua anggota tim dapat melakukan pengujian fitur login Google dari laptop masing-masing tanpa terkena error `ApiException: 10`.